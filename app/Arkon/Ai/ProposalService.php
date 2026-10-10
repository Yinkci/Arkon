<?php

namespace App\Arkon\Ai;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Database\Transactions;
use App\Arkon\Design\TokenService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Pages\PageService;
use App\Arkon\Pages\PageStore;
use App\Arkon\Schema\OperationException;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One proposal backend for both ways of prompting Claude Code:
 *
 *   requesting   request()   the editor's AI panel queues a request (no long web request)
 *   executing    claimNext() / execute()   the local helper runs Claude Code under a lease
 *   submitting   submit()    Claude Code in VS Code submits a proposal through MCP
 *   reviewing    list() / status() / cancel() / discard(); applying is a normal save
 *
 * Whatever the provider returns is untrusted: every proposal goes through ProposalCompiler (registered
 * components, props, nesting, link policy, media on the page) against the draft version it is
 * based on. Nothing here changes a draft or publishes.
 */
final class ProposalService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Transactions $transactions,
        private readonly PageStore $store,
        private readonly PageService $pages,
        private readonly DocumentValidator $validator,
        private readonly ProposalPrompt $prompts,
        private readonly ProposalCompiler $compiler,
        private readonly ProposalLedger $ledger,
        private readonly AiConnections $connections,
        private readonly AuditLog $audit,
    ) {}

    /** What the editor needs to show the AI panel. Never credentials. */
    public function editorInfo(SiteContext $ctx, bool $canEdit): array
    {
        return [
            'available' => $canEdit,
            'reason' => $canEdit ? null : 'Only members who can edit this page can use AI.',
            'promptMax' => (int) config('arkon.ai.prompt_max_chars'),
            'connection' => $canEdit ? $this->connections->forContext($ctx) : ['selectionState' => 'unavailable', 'ready' => false, 'provider' => null, 'providerName' => null, 'message' => 'Only members who can edit this page can use AI.', 'providers' => []],
        ];
    }

    // ── Requesting (AI panel) ───────────────────────────────────────────────

    public function request(SiteContext $ctx, string $pageId, array $input): array
    {
        $pageId = Input::id($pageId);
        [$prompt, $baseVersion, $key] = $this->validInput($input);
        $providerInput = isset($input['provider']) ? ProviderRegistry::validate((string) $input['provider']) : null;
        $fingerprint = ProposalLedger::requestFingerprint(['source' => 'panel', 'pageId' => $pageId, 'prompt' => $prompt, 'baseVersion' => $baseVersion, ...(isset($input['provider']) ? ['provider' => $providerInput] : []), ...(isset($input['selectionMode']) ? ['selectionMode' => $input['selectionMode']] : [])]);

        $id = $this->transactions->run(function () use ($ctx, $pageId, $prompt, $baseVersion, $key, $fingerprint, $input) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $this->store->loadPage($ctx->siteId, $pageId);
            $this->ledger->lockSite($ctx->siteId);
            if ($existing = $this->ledger->existing($ctx, $key, $pageId, $fingerprint)) {
                return $existing->id; // a retried request: the same record, never a second run
            }
            $provider = $this->connections->selectForRequest($ctx, $input, 'page_proposal');
            $this->assertCurrentDraft($ctx, $pageId, $baseVersion);
            $this->ledger->supersede($ctx, $pageId);
            $this->ledger->checkLimits($ctx, startsRun: true);
            $id = $this->ledger->insert([
                'site_id' => $ctx->siteId, 'page_id' => $pageId, 'created_by' => $ctx->userId, 'source' => 'panel',
                'request_key' => $key, 'request_fingerprint' => $fingerprint, 'prompt' => $prompt, 'base_version' => $baseVersion,
                'status' => 'queued', 'provider' => $provider, 'model' => (string) (config('arkon.ai.model') ?: 'default'),
            ]);
            $this->audit->forContext($ctx, 'page.ai.request', 'page', $pageId, ['request' => $id]);

            return $id;
        });

        return $this->status($ctx, $pageId, $id);
    }

    // ── Submitting (MCP from Claude Code in VS Code) ────────────────────────

    /** Validates and records a proposal written by Claude Code; it waits in Arkon for visual review. */
    public function submit(SiteContext $ctx, string $pageId, array $input, string $connectionId): array
    {
        $pageId = Input::id($pageId);
        [$prompt, $baseVersion, $key] = $this->validInput($input);
        $proposal = $input['proposal'] ?? null;
        $fingerprint = ProposalLedger::requestFingerprint(['source' => 'mcp', 'pageId' => $pageId, 'prompt' => $prompt, 'baseVersion' => $baseVersion, 'proposal' => Json::canonical($proposal)]);

        // An exact retry (lost acknowledgement) gets the stored proposal and its current status, whatever
        // happened to the draft since; the same key with a changed payload is a conflict. Only new
        // submissions are validated against the current draft.
        $existing = $this->transactions->run(function () use ($ctx, $pageId, $key, $fingerprint) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $this->store->loadPage($ctx->siteId, $pageId);

            return $this->ledger->existing($ctx, $key, $pageId, $fingerprint);
        });
        if ($existing !== null) {
            return $this->status($ctx, $pageId, $existing->id);
        }

        // Validate against the draft the proposal claims to be based on (no lock held while compiling).
        $context = $this->transactions->run(function () use ($ctx, $pageId, $baseVersion) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $context = $this->prompts->context($ctx, $pageId);
            if ($context['version'] !== $baseVersion) {
                throw new StaleVersionException($baseVersion, $context['version']);
            }
            if ($this->validator->validate($context['doc']) !== []) {
                throw new ValidationException('Repair this draft in the editor before proposing changes to it');
            }

            return $context;
        }, isolation: 'REPEATABLE READ', readOnly: true);
        $compiled = $this->compiler->compile($context['doc'], Json::toArray($proposal), array_keys($context['assets']), (int) config('arkon.ai.max_changes'), array_keys($context['components']), $context['themeTypes']);
        self::assertSeoScope($prompt, $compiled, $context['doc']);
        if (array_diff(FormService::references($compiled['document']), FormService::references($context['doc']), array_keys($context['forms'] ?? [])) !== []) {
            throw new AiException(AiException::INVALID_OUTPUT, 'Use only the published forms supplied in the page context.');
        }
        if (array_diff(MenuService::references($compiled['document']), MenuService::references($context['doc']), array_keys($context['menus'] ?? [])) !== []) {
            throw new AiException(AiException::INVALID_OUTPUT, 'Use only the published menus supplied in the page context.');
        }

        $id = $this->transactions->run(function () use ($ctx, $pageId, $prompt, $baseVersion, $key, $fingerprint, $compiled, $connectionId) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $this->ledger->lockSite($ctx->siteId);
            if ($existing = $this->ledger->existing($ctx, $key, $pageId, $fingerprint)) {
                return $existing->id;
            }
            $this->assertCurrentDraft($ctx, $pageId, $baseVersion);
            $this->ledger->checkLimits($ctx, startsRun: false);
            $id = $this->ledger->insert([
                'site_id' => $ctx->siteId, 'page_id' => $pageId, 'created_by' => $ctx->userId, 'source' => 'mcp', 'connection_id' => $connectionId,
                'request_key' => $key, 'request_fingerprint' => $fingerprint, 'prompt' => $prompt, 'base_version' => $baseVersion,
                'provider' => $this->connections->providerForConnection($connectionId), 'model' => 'mcp', ...$this->ledger->proposalColumns($compiled, $ctx->siteId),
            ]);
            $this->audit->forContext($ctx, 'page.ai.propose', 'page', $pageId, ['proposal' => $id, 'via' => 'mcp', 'operations' => count($compiled['operations'])]);

            return $id;
        });

        return $this->status($ctx, $pageId, $id);
    }

    // ── Executing (local helper) ────────────────────────────────────────────

    /** Recovers interrupted runs and leases the next queued request of the helper's site. */
    public function claimNext(object $connection): ?object
    {
        return $this->transactions->run(function () use ($connection) {
            $this->ledger->recover($connection->site_id);

            return $this->ledger->claim($connection->site_id, $connection->id);
        });
    }

    /**
     * Runs the selected provider for a leased request: the current draft at the request's base version,
     * one repair run when the output does not compile, then the result, only while the lease holds.
     */
    public function execute(object $claim, AiRunner $runner, ?callable $alive = null): string
    {
        if (($claim->scope ?? 'page') === 'website') {
            return app(WebsiteProposalService::class)->execute($claim, $runner, $alive);
        }
        $ctx = new SiteContext($claim->site_id, $claim->created_by, 'ai');
        $fail = fn (string $code, string $message) => $this->ledger->failRun($claim->id, $claim->lease_token, $code, $message) ? 'failed' : 'lost';
        try {
            $context = $this->transactions->run(function () use ($ctx, $claim) {
                $this->authorizer->authorize($ctx, 'page.edit');

                return $this->prompts->context($ctx, $claim->page_id);
            }, isolation: 'REPEATABLE READ', readOnly: true);
        } catch (NotFoundException|ForbiddenException) {
            return $fail(AiException::CANCELLED, 'The page is gone or you can no longer edit it.');
        }
        if ($context['version'] !== (int) $claim->base_version) {
            return $fail(AiException::STALE_DRAFT, 'The draft changed while this request was waiting. Ask again.');
        }
        if ($this->validator->validate($context['doc']) !== []) {
            return $fail(AiException::STALE_DRAFT, 'This draft needs repair before the AI can change it.');
        }

        $keepGoing = function () use ($claim, $alive) {
            if ($alive !== null) {
                $alive();
            }

            return $this->ledger->renew($claim->id, $claim->lease_token);
        };
        $prompt = $this->prompts->prompt($context, $claim->prompt);
        $repairs = (int) config('arkon.ai.repair_attempts');
        for ($attempt = 0; ; $attempt++) {
            try {
                $completion = $runner->run(new AiRequest($this->prompts->instructions($context), $prompt, $this->prompts->schema($context)), $keepGoing);
                $compiled = $this->compiler->compile($context['doc'], $completion->output, array_keys($context['assets']), (int) config('arkon.ai.max_changes'), array_keys($context['components']), $context['themeTypes']);
                self::assertSeoScope($claim->prompt, $compiled, $context['doc']);
                if (array_diff(FormService::references($compiled['document']), FormService::references($context['doc']), array_keys($context['forms'] ?? [])) !== []) {
                    throw new AiException(AiException::INVALID_OUTPUT, 'Use only the published forms supplied in the page context.');
                }
                if (array_diff(MenuService::references($compiled['document']), MenuService::references($context['doc']), array_keys($context['menus'] ?? [])) !== []) {
                    throw new AiException(AiException::INVALID_OUTPUT, 'Use only the published menus supplied in the page context.');
                }
            } catch (AiException $error) {
                if ($error->code() === AiException::CANCELLED) {
                    return 'cancelled';
                }
                if ($error->code() === AiException::INVALID_OUTPUT && $attempt < $repairs && isset($completion)) {
                    $prompt .= "\n\nYour previous proposal:\n{$completion->text}\n\nIt cannot be applied:\n- "
                        .implode("\n- ", array_column($error->issues, 'message'))."\nReturn a corrected proposal for the same request.";
                    unset($completion);

                    continue;
                }
                $message = $error->getMessage().($error->issues ? ' '.implode(' ', array_slice(array_column($error->issues, 'message'), 0, 5)) : '');

                return $fail($error->code(), mb_substr($message, 0, 1000));
            }
            if (! $this->ledger->finish($claim->id, $claim->lease_token, $compiled)) {
                Log::info('AI result discarded: the request was cancelled or taken over', ['request' => $claim->id]);

                return 'lost';
            }
            $this->audit->forContext($ctx, 'page.ai.propose', 'page', $claim->page_id, ['proposal' => $claim->id, 'via' => 'helper', 'operations' => count($compiled['operations'])]);

            return $compiled['operations'] === [] ? 'empty' : 'proposed';
        }
    }

    // ── Reviewing ───────────────────────────────────────────────────────────

    /** The user's requests on this page still running or waiting for review, oldest first. */
    public function list(SiteContext $ctx, string $pageId): array
    {
        $pageId = Input::id($pageId);

        return $this->transactions->run(function () use ($ctx, $pageId) {
            $this->authorizer->authorize($ctx, 'page.view');
            $this->store->loadPage($ctx->siteId, $pageId);

            return [
                'requests' => array_map(fn ($row) => $this->view($row), $this->ledger->reviewable($ctx, $pageId)),
                'connection' => $this->connections->forContext($ctx),
            ];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    /** One request; with a signer, a proposal also carries its rendered preview. */
    public function status(SiteContext $ctx, string $pageId, string $id, ?MediaSigner $signer = null): array
    {
        $pageId = Input::id($pageId);
        $row = $this->transactions->run(function () use ($ctx, $pageId, $id) {
            $this->authorizer->authorize($ctx, 'page.view');
            $this->store->loadPage($ctx->siteId, $pageId);

            return $this->ledger->find($ctx, $pageId, $id) ?? throw new NotFoundException('AI request');
        }, isolation: 'REPEATABLE READ', readOnly: true);
        $view = $this->view($row);
        if ($signer !== null && $row->status === 'proposed') {
            $view['proposal']['canvas'] = $this->preview($ctx, $pageId, $row, $signer);
        }

        return $view;
    }

    public function cancel(SiteContext $ctx, string $pageId, string $id): array
    {
        $pageId = Input::id($pageId);
        $this->transactions->run(function () use ($ctx, $pageId, $id) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $row = $this->ledger->lockOwn($ctx, $pageId, $id) ?? throw new NotFoundException('AI request');
            if (in_array($row->status, ['queued', 'running'], true)) {
                $this->ledger->cancel($row->id);
                $this->audit->forContext($ctx, 'page.ai.cancel', 'page', $pageId, ['request' => $row->id]);
            }
        });

        return $this->status($ctx, $pageId, $id);
    }

    public function discard(SiteContext $ctx, string $pageId, string $id): void
    {
        $pageId = Input::id($pageId);
        $this->transactions->run(function () use ($ctx, $pageId, $id) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $this->store->loadPage($ctx->siteId, $pageId);
            $row = $this->ledger->lockOwn($ctx, $pageId, $id) ?? throw new AiException(AiException::STALE_PROPOSAL, 'This AI proposal does not exist for this page.');
            if ($row->status === 'applied') {
                throw new AiException(AiException::STALE_PROPOSAL, 'This AI proposal has already been applied. Use Undo to revert it.');
            }
            if (in_array($row->status, ['queued', 'running'], true)) {
                $this->ledger->cancel($row->id);
            } elseif (in_array($row->status, ['proposed', 'empty'], true)) {
                $this->ledger->markDiscarded($row->id);
                $this->audit->forContext($ctx, 'page.ai.discard', 'page', $pageId, ['proposal' => $row->id]);
            }
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: int, 2: string} prompt, base version, request key */
    private function validInput(array $input): array
    {
        $max = (int) config('arkon.ai.prompt_max_chars');
        $valid = Input::validate($input, [
            'prompt' => ['required', 'string', "max:{$max}"],
            'baseVersion' => ['required', 'integer', 'min:1'],
            'requestKey' => Input::requestKeyRule(),
        ]);
        $prompt = Text::trim($valid['prompt']);
        if ($prompt === '') {
            throw new ValidationException('Describe what you want the AI to do');
        }

        return [$prompt, (int) $valid['baseVersion'], $valid['requestKey']];
    }

    private function assertCurrentDraft(SiteContext $ctx, string $pageId, int $baseVersion): void
    {
        $draft = $this->store->loadDraft($ctx->siteId, $pageId);
        if ((int) $draft->version !== $baseVersion) {
            throw new StaleVersionException($baseVersion, (int) $draft->version);
        }
        if ($this->validator->validate($this->store->document($draft->document)) !== []) {
            throw new ValidationException('Repair this draft before asking the AI to change it');
        }
    }

    /** The proposed page, rendered by the one renderer in editor mode, if it still fits the draft. */
    private function preview(SiteContext $ctx, string $pageId, object $row, MediaSigner $signer): ?array
    {
        try {
            $document = $this->transactions->run(function () use ($ctx, $pageId, $row) {
                $draft = $this->store->loadDraft($ctx->siteId, $pageId);
                if ((int) $draft->version !== (int) $row->base_version) {
                    return null;
                }

                return Operations::apply($this->store->document($draft->document), Json::decode((string) $row->operations))['doc'];
            }, isolation: 'REPEATABLE READ', readOnly: true);
        } catch (OperationException) {
            return null;
        }

        return $document === null ? null : $this->pages->renderCanvas($ctx, $pageId, $document, $signer);
    }

    /**
     * Applies a proposal's site-wide token changes to the token draft (never publishes them).
     * Separate from applying the page changes; once per proposal.
     *
     * @return array{tokenDraftVersion: int}
     */
    public function applyTokenChanges(SiteContext $ctx, string $pageId, string $proposalId, TokenService $tokens): array
    {
        $pageId = Input::id($pageId);
        $proposalId = Input::id($proposalId, 'AI proposal');

        // One transaction: the token draft write and the proposal's applied marker commit together or not
        // at all. The proposal row lock serializes duplicates; the second one finds the marker.
        return $this->transactions->run(function () use ($ctx, $pageId, $proposalId, $tokens) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $row = $this->ledger->lockOwn($ctx, $pageId, $proposalId) ?? throw new NotFoundException('AI proposal');
            $details = Json::toArray(Json::decode((string) ($row->details ?? '{}'))) ?: [];
            if (! in_array($row->status, ['proposed', 'applied'], true) || ($details['tokenChanges'] ?? []) === []) {
                throw new ConflictException('This proposal has no design token changes to apply');
            }
            // An exact retry (lost response, second click, another tab): the original result, even after later token edits.
            if (isset($details['tokenChangesApplied'])) {
                return ['tokenDraftVersion' => (int) $details['tokenChangesApplied']];
            }
            try {
                $version = $tokens->applyChangesLocked($ctx, $details['tokenChanges'], isset($details['tokenBaseVersion']) ? (int) $details['tokenBaseVersion'] : null, 'ai-tokens-'.str_replace('-', '', $row->id));
            } catch (StaleVersionException) {
                throw new AiException(AiException::STALE_PROPOSAL, 'The design tokens were changed after this proposal was made, so its token changes were not applied. Ask again to get changes based on the current tokens.');
            }
            DB::table('ai_proposals')->where('id', $row->id)->update(['details' => Json::encode([...$details, 'tokenChangesApplied' => $version])]);
            $this->audit->forContext($ctx, 'tokens.ai.apply', 'site', $ctx->siteId, ['proposal' => $row->id, 'tokens' => count($details['tokenChanges']), 'version' => $version]);

            return ['tokenDraftVersion' => $version];
        });
    }

    /** The request as the editor and the MCP tools see it. */
    public static function assertSeoScope(string $prompt, array $compiled, array $base = []): void
    {
        if (preg_match('/^ARKON_SEO_ALT_ONLY:([A-Za-z0-9_-]+)\n/', $prompt, $alt)) {
            if (($compiled['tokenChanges'] ?? []) !== []) {
                throw new AiException(AiException::INVALID_OUTPUT, 'Alt text generation cannot change design tokens.');
            }
            $before = Json::entries(Json::entries($base['nodes'] ?? [])[$alt[1]]['props'] ?? [])['image'] ?? null;
            foreach ($compiled['operations'] as $op) {
                $image = Json::entries(Json::entries($op['set'] ?? [])['image'] ?? []);
                if (($op['op'] ?? '') !== 'updateProps' || ($op['nodeId'] ?? '') !== $alt[1] || array_keys(Json::entries($op['set'] ?? [])) !== ['image'] || ! empty($op['unset']) || $before === null || ($image['assetId'] ?? null) !== (Json::entries($before)['assetId'] ?? null) || array_diff(array_keys($image), array_keys(Json::entries($before))) !== []) {
                    throw new AiException(AiException::INVALID_OUTPUT, 'Alt generation can only describe the selected existing image.');
                }foreach (Json::entries($before) as $key => $value) {
                    if ($key !== 'alt' && ($image[$key] ?? null) !== $value) {
                        throw new AiException(AiException::INVALID_OUTPUT, 'Alt generation cannot change the image.');
                    }
                }
            }

            return;
        }
        if (! preg_match('/^ARKON_SEO_METADATA_ONLY:([A-Za-z]+)\n/', $prompt, $m)) {
            return;
        }
        $allowed = $m[1] === 'all' ? ['title', 'description', 'focusTopic', 'socialTitle', 'socialDescription'] : [$m[1]];
        if (($compiled['tokenChanges'] ?? []) !== []) {
            throw new AiException(AiException::INVALID_OUTPUT, 'SEO text generation cannot change design tokens.');
        }
        foreach ($compiled['operations'] as $op) {
            if (($op['op'] ?? '') !== 'updateSeo' || array_diff(array_keys(Json::entries($op['set'] ?? [])), $allowed) !== [] || ! empty($op['unset'])) {
                throw new AiException(AiException::INVALID_OUTPUT, 'SEO text generation can only change the requested metadata fields.');
            }
        }
    }

    private function view(object $row): array
    {
        $details = Json::toArray(Json::decode((string) ($row->details ?? '{}'))) ?: [];
        $hasProposal = in_array($row->status, ['proposed', 'empty', 'applied', 'discarded'], true) && $row->operations !== null;

        return [
            'id' => $row->id,
            'pageId' => $row->page_id,
            'source' => $row->source,
            'provider' => $row->provider,
            'status' => $row->status,
            'prompt' => $row->prompt,
            'baseVersion' => (int) $row->base_version,
            'createdAt' => $row->created_at,
            'startedAt' => $row->started_at,
            'error' => $row->error_code ? ['code' => $row->error_code, 'message' => (string) $row->error_message] : null,
            'proposal' => $hasProposal ? [
                'id' => $row->id,
                'pageId' => $row->page_id,
                'baseVersion' => (int) $row->base_version,
                'status' => $row->status === 'empty' ? 'empty' : 'proposed',
                'prompt' => $row->prompt,
                'summary' => (string) $row->summary,
                'notes' => $details['notes'] ?? [],
                'changes' => $details['changes'] ?? [],
                'warnings' => $details['warnings'] ?? [],
                // Separate from the page changes: applied only on request, to the site's token draft.
                'tokenChanges' => $details['tokenChanges'] ?? [],
                'tokenChangesApplied' => $details['tokenChangesApplied'] ?? null,
                // Raw form: empty objects stay objects, exactly as a save will send them back.
                'operations' => Json::decode((string) $row->operations),
                'canvas' => null,
            ] : null,
        ];
    }
}
