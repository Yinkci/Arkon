<?php

namespace App\Arkon\Ai\Mcp;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\ProposalPrompt;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ArkonException;
use App\Arkon\Pages\PageService;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Arkon's MCP server for Claude Code (VS Code), spoken over stdio as newline-delimited
 * JSON-RPC 2.0. The connection is one paired Arkon user on one site (its token, from the
 * ARKON_MCP_TOKEN environment variable, resolved again on every call so revocation is
 * immediate); no tool takes a user or site id.
 *
 * Tools: list pages, read a page and its draft version, read the proposal format (catalogue +
 * schema), submit a proposal, read a proposal's status. A submission is validated like every
 * proposal and waits in Arkon's editor for visual review; nothing here changes a draft,
 * applies or publishes. There is no SQL, shell or file access.
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly ?string $token,
        private readonly AiConnections $connections,
        private readonly ProposalService $proposals,
        private readonly ProposalPrompt $prompts,
        private readonly PageService $pages,
        private readonly Authorizer $authorizer,
        private readonly Transactions $transactions,
    ) {}

    /** Handles one incoming line; returns the response line, or null for notifications. */
    public function handleLine(string $line): ?string
    {
        $message = json_decode($line, true);
        if (! is_array($message)) {
            return self::encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }
        $response = $this->handle($message);

        return $response === null ? null : self::encode($response);
    }

    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (! array_key_exists('id', $message)) {
            return null; // notifications (initialized, cancelled, …) need no answer
        }
        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => new \stdClass,
            'tools/list' => ['tools' => $this->tools()],
            'tools/call' => $this->call((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []),
            default => null,
        };
        if ($result === null) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: {$method}"]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[1],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'arkon', 'version' => '1.0.0'],
            'instructions' => 'Arkon CMS: build or refine one page from native blocks. Flow: arkon_list_pages → arkon_get_page → arkon_get_proposal_format → write a proposal as JSON matching the schema → arkon_submit_proposal with the page\'s draftVersion as baseVersion. The proposal is validated and waits in Arkon\'s editor (AI tab) where the user previews it and applies or discards it; you cannot apply or publish. If validation fails, fix the listed problems and submit again with a new requestKey.',
        ];
    }

    /** @return list<array> */
    public function tools(): array
    {
        $page = ['pageId' => ['type' => 'string', 'description' => 'Page id from arkon_list_pages']];

        return [
            [
                'name' => 'arkon_list_pages',
                'description' => 'List the pages of the Arkon site this connection is paired with: id, title, URL path, status and current draft version.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'arkon_get_page',
                'description' => 'Read one page: its draft version (use it as baseVersion when submitting), its blocks (ids, types, props) and the images already on it.',
                'inputSchema' => ['type' => 'object', 'properties' => $page, 'required' => ['pageId'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'arkon_get_proposal_format',
                'description' => 'The rules, the component catalogue (registered blocks, props, nesting, design settings and tokens) and the JSON schema a proposal for this page must match. Read it before writing a proposal.',
                'inputSchema' => ['type' => 'object', 'properties' => $page, 'required' => ['pageId'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'arkon_submit_proposal',
                'description' => 'Submit a proposal for visual review in Arkon. It is validated (components, props, nesting, links, images) against the draft version given as baseVersion; the draft is not changed. The user applies or discards it in the editor\'s AI tab. Nothing is published.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...$page,
                        'baseVersion' => ['type' => 'integer', 'description' => 'draftVersion from arkon_get_page'],
                        'request' => ['type' => 'string', 'description' => 'What the user asked for, in their words (shown with the proposal)'],
                        'requestKey' => ['type' => 'string', 'description' => '16–100 characters [A-Za-z0-9_-], unique per submission; resend the same key only to retry the same submission'],
                        'proposal' => ['type' => 'object', 'description' => 'The proposal: {summary, notes, tokenChanges, changes} matching the schema from arkon_get_proposal_format'],
                    ],
                    'required' => ['pageId', 'baseVersion', 'request', 'requestKey', 'proposal'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'arkon_get_proposal_status',
                'description' => 'Whether a submitted proposal is still waiting for review, applied or discarded.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [...$page, 'proposalId' => ['type' => 'string']],
                    'required' => ['pageId', 'proposalId'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
        ];
    }

    /** A tool call: domain errors become tool errors (isError) the model can read and act on. */
    private function call(string $name, array $arguments): array
    {
        try {
            $connection = $this->connections->resolve($this->token, 'mcp')
                ?? throw new AiException(AiException::UNAUTHENTICATED, 'This Arkon connection is not paired or was revoked. Pair again with: php artisan arkon:ai-pair you@example.com --mcp');
            $this->connections->touch($connection->id);
            $ctx = $this->connections->context($connection, 'ai');
            $result = match ($name) {
                'arkon_list_pages' => $this->listPages($ctx),
                'arkon_get_page' => $this->getPage($ctx, (string) ($arguments['pageId'] ?? '')),
                'arkon_get_proposal_format' => $this->format($ctx, (string) ($arguments['pageId'] ?? '')),
                'arkon_submit_proposal' => $this->submit($ctx, $arguments, $connection->id),
                'arkon_get_proposal_status' => $this->summary($this->proposals->status($ctx, (string) ($arguments['pageId'] ?? ''), (string) ($arguments['proposalId'] ?? ''))),
                default => throw new AiException(AiException::INVALID_OUTPUT, "Unknown tool {$name}"),
            };

            return ['content' => [['type' => 'text', 'text' => self::encode($result)]], 'structuredContent' => $result, 'isError' => false];
        } catch (ArkonException $error) {
            $data = $error->toArray();

            return ['content' => [['type' => 'text', 'text' => self::encode($data)]], 'isError' => true];
        } catch (Throwable $error) {
            return ['content' => [['type' => 'text', 'text' => 'Arkon could not complete this call.']], 'isError' => true];
        }
    }

    private function listPages(SiteContext $ctx): array
    {
        $site = DB::table('sites')->where('id', $ctx->siteId)->value('name');
        $pages = array_map(fn ($p) => [
            'id' => $p['id'], 'title' => $p['title'], 'path' => $p['path'], 'status' => $p['status'], 'draftVersion' => $p['version'],
            'editorUrl' => $this->editorUrl($p['id']),
        ], $this->pages->listPages($ctx));

        return ['site' => $site, 'pages' => $pages];
    }

    private function getPage(SiteContext $ctx, string $pageId): array
    {
        $pageId = Input::id($pageId);

        return $this->transactions->run(function () use ($ctx, $pageId) {
            $this->authorizer->authorize($ctx, 'page.view');
            $context = $this->prompts->context($ctx, $pageId);

            return [
                'page' => ['id' => $context['page']->id, 'title' => $context['page']->title, 'path' => $context['page']->path],
                'draftVersion' => $context['version'],
                'imagesOnPage' => array_map(fn ($id, $a) => ['assetId' => $id, ...$a], array_keys($context['assets']), $context['assets']),
                'blocks' => $this->prompts->blocks($context['doc']),
                // Published shared resources only (instances render published versions; drafts are never shown).
                'reusableComponents' => array_map(fn ($id, $c) => ['componentId' => $id, ...$c], array_keys($context['components']), $context['components']),
                'designTokens' => $context['tokens'],
                'editorUrl' => $this->editorUrl($pageId),
            ];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    private function format(SiteContext $ctx, string $pageId): array
    {
        $pageId = Input::id($pageId);

        return $this->transactions->run(function () use ($ctx, $pageId) {
            $this->authorizer->authorize($ctx, 'page.view');
            $context = $this->prompts->context($ctx, $pageId);

            return ['instructions' => $this->prompts->instructions($context), 'schema' => $this->prompts->schema($context), 'draftVersion' => $context['version']];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    private function submit(SiteContext $ctx, array $arguments, string $connectionId): array
    {
        $pageId = (string) ($arguments['pageId'] ?? '');
        $view = $this->proposals->submit($ctx, $pageId, [
            'prompt' => $arguments['request'] ?? null,
            'baseVersion' => $arguments['baseVersion'] ?? null,
            'requestKey' => $arguments['requestKey'] ?? null,
            'proposal' => $arguments['proposal'] ?? null,
        ], $connectionId);

        return [...$this->summary($view), 'next' => 'The proposal is waiting in Arkon. Ask the user to open the page in the editor, AI tab, to preview it and apply or discard it.'];
    }

    private function summary(array $view): array
    {
        return [
            'proposalId' => $view['id'],
            'status' => $view['status'],
            'request' => $view['prompt'],
            'baseVersion' => $view['baseVersion'],
            'changes' => $view['proposal']['changes'] ?? [],
            'warnings' => $view['proposal']['warnings'] ?? [],
            'notes' => $view['proposal']['notes'] ?? [],
            'error' => $view['error'],
            'reviewUrl' => $this->editorUrl($view['pageId']),
        ];
    }

    private function editorUrl(string $pageId): string
    {
        return rtrim((string) config('app.url'), '/')."/admin/editor/{$pageId}";
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
