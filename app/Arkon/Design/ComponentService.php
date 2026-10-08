<?php

namespace App\Arkon\Design;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageStore;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Schema\OperationException;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Time;
use App\Arkon\Support\Uuid;
use App\Arkon\Themes\ThemeService;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reusable components: a named block arrangement (a fragment document: the same
 * nodes, validation and design settings as pages) with an editable draft and
 * immutable published versions. Pages place it with an instance block, which always
 * renders the latest *published* version: editing the draft changes nothing live;
 * publishing it re-renders the live pages that use it (PageRefreshes).
 *
 * Instances may override their own placement (spacing, size, alignment); their
 * content belongs to the component. Detaching (editor) copies the published blocks
 * into the page instead. Components cannot contain instances.
 */
class ComponentService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Transactions $transactions,
        private readonly ComponentRegistry $registry,
        private readonly DocumentValidator $validator,
        private readonly PageRenderer $renderer,
        private readonly PageStore $store,
        private readonly MediaService $media,
        private readonly DesignResources $resources,
        private readonly PageRefreshes $refreshes,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array{id: string, name: string, version: int, published: int|null, changed: bool, livePages: int, updatedAt: string}> */
    public function list(SiteContext $ctx): array
    {
        return $this->transactions->run(function () use ($ctx) {
            $this->authorizer->authorize($ctx, 'page.view');
            $rows = DB::table('reusable_components as c')->where('c.site_id', $ctx->siteId)->orderBy('c.name')->get();

            return $rows->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'version' => (int) $c->version,
                'published' => $c->published_version === null ? null : (int) $c->published_version,
                'changed' => $this->draftDiffers($c),
                'livePages' => $this->livePagesUsing($ctx->siteId, $c->id),
                'updatedAt' => Time::iso($c->updated_at),
            ])->all();
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    /**
     * A new component, optionally from blocks (a node list in insertNode form: the
     * first node is a top-level block, the rest its descendants).
     *
     * @return array{id: string}
     */
    public function create(SiteContext $ctx, array $input): array
    {
        $name = $this->name($input['name'] ?? null);
        $doc = $this->fragmentFrom($input['nodes'] ?? []);

        return $this->transactions->run(function () use ($ctx, $name, $doc) {
            $this->authorizer->authorize($ctx, 'page.edit');
            ThemeService::assertAdditions($ctx->siteId, null, $doc);
            $this->validateForSave($ctx->siteId, $doc);
            $id = Uuid::v7();
            DB::table('reusable_components')->insert([
                'id' => $id, 'site_id' => $ctx->siteId, 'name' => $name, 'draft' => Json::encode($doc),
                'created_by' => $ctx->userId, 'updated_by' => $ctx->userId,
            ]);
            $this->audit->forContext($ctx, 'component.create', 'component', $id, ['name' => $name]);

            return ['id' => $id];
        });
    }

    /** Everything the component editor opens with. */
    public function editorInit(SiteContext $ctx, string $id, MediaSigner $signer): array
    {
        return $this->transactions->run(function () use ($ctx, $id, $signer) {
            $role = $this->authorizer->authorize($ctx, 'page.view');
            $row = $this->load($ctx->siteId, $id);
            $doc = $this->registry->migrateDocument(Json::decode($row->draft));
            $media = array_values($this->media->signedMediaMap($ctx->siteId, array_values(array_unique([
                ...$this->validator->safeMediaRefs($this->asPage($doc)),
                ...DB::table('media_assets')->where('site_id', $ctx->siteId)->orderByDesc('created_at')->limit(100)->pluck('id')->all(),
            ])), $signer));

            return [
                'component' => ['id' => $row->id, 'name' => $row->name, 'published' => $row->published_version === null ? null : (int) $row->published_version],
                'draft' => ['document' => $doc, 'version' => (int) $row->version],
                'changed' => $this->draftDiffers($row),
                'livePages' => $this->livePagesUsing($ctx->siteId, $row->id),
                'media' => $this->media->withNames($ctx->siteId, $media),
                'tokens' => $this->resources->resolvedTokens($ctx->siteId),
                'permissions' => ['edit' => Permissions::allows($role, 'page.edit'), 'publish' => Permissions::allows($role, 'page.publish'), 'upload' => Permissions::allows($role, 'media.upload')],
                'canvas' => $this->readCanvas($ctx, $doc, $signer),
                'multiline' => $this->registry->multilineFields(),
                'refreshes' => $this->refreshes->status($ctx),
            ];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    /** Editor-mode rendering of a (draft) component document, as it would look on a page. */
    public function renderCanvas(SiteContext $ctx, string $id, mixed $document, MediaSigner $signer): array
    {
        return $this->transactions->run(function () use ($ctx, $id, $document, $signer) {
            $this->authorizer->authorize($ctx, 'page.view');
            $this->load($ctx->siteId, $id);

            return $this->readCanvas($ctx, $document, $signer);
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    private function readCanvas(SiteContext $ctx, mixed $document, MediaSigner $signer): array
    {
        if ($this->validator->validateFragment($document) !== []) {
            throw new ValidationException('The component is not valid', $this->validator->validateFragment($document));
        }
        $page = $this->asPage($document);
        $media = $this->media->signedMediaMap($ctx->siteId, $this->validator->safeMediaRefs($page), $signer);
        try {
            $out = $this->renderer->render($page, 'editor', ['title' => 'Component', 'path' => '/'], ['name' => 'Component'], $media, resources: ['tokens' => $this->resources->publishedTokens($ctx->siteId)], protectLcp: false);
        } catch (RenderException $error) {
            throw new ValidationException('The component could not be rendered', $error->issues);
        }

        // Whether an animation is left off depends on the page a component is placed on, not on the component alone.
        return ['body' => $out['body'], 'css' => $out['css'], 'motion' => ['protected' => new stdClass]];
    }

    /**
     * Applies operations to the draft the caller saw (like a page save: stale-checked,
     * idempotent per key). Optionally renames it.
     *
     * @return array{version: int, document: mixed, replayed: bool}
     */
    public function save(SiteContext $ctx, string $id, array $input): array
    {
        $valid = Input::validate($input, ['baseVersion' => ['required', 'integer', 'min:1'], 'saveKey' => Input::requestKeyRule()]);
        [$operations, $issues] = Operations::parse($input['operations'] ?? []);
        if ($issues !== []) {
            throw new ValidationException('The change is not valid', $issues);
        }
        $name = array_key_exists('name', $input) ? $this->name($input['name']) : null;
        if ($operations === [] && $name === null) {
            throw new ValidationException('Nothing to save');
        }
        $base = (int) $valid['baseVersion'];
        $fingerprint = Fingerprint::of(['kind' => 'component.save', 'id' => $id, 'baseVersion' => $base, 'operations' => $operations, 'name' => $name]);

        return $this->transactions->run(function () use ($ctx, $id, $operations, $name, $base, $valid, $fingerprint) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $row = $this->load($ctx->siteId, $id, lock: true);
            if ($row->last_save_key === $valid['saveKey']) {
                if ($row->last_save_fingerprint !== $fingerprint) {
                    throw new ConflictException('This save key was already used for a different save');
                }

                return ['version' => (int) $row->version, 'document' => Json::decode($row->draft), 'replayed' => true];
            }
            if ((int) $row->version !== $base) {
                throw new StaleVersionException($base, (int) $row->version);
            }
            try {
                $next = Operations::apply($this->registry->migrateDocument(Json::decode($row->draft)), $operations)['doc'];
            } catch (OperationException $error) {
                throw new ValidationException($error->getMessage());
            }
            ThemeService::assertAdditions($ctx->siteId, Json::decode($row->draft), $next);
            $this->validateForSave($ctx->siteId, $next);
            $version = (int) $row->version + 1;
            DB::table('reusable_components')->where('id', $id)->update([
                'draft' => Json::encode($next), 'version' => $version, 'name' => $name ?? $row->name,
                'last_save_key' => $valid['saveKey'], 'last_save_fingerprint' => $fingerprint,
                'updated_by' => $ctx->userId, 'updated_at' => DB::raw('now()'),
            ]);
            $this->audit->forContext($ctx, 'component.draft.save', 'component', $id, ['version' => $version, 'operations' => count($operations)]);

            return ['version' => $version, 'document' => $next, 'replayed' => false];
        });
    }

    /**
     * Publishes the draft version the caller saw as the component's next version, then
     * refreshes the live pages that use the component. Idempotent per key.
     *
     * @return array{version: int, replayed: bool, refreshes: array}
     */
    public function publish(SiteContext $ctx, string $id, array $input): array
    {
        $valid = Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1'], 'idempotencyKey' => Input::requestKeyRule()]);
        $expected = (int) $valid['expectedVersion'];
        $key = $valid['idempotencyKey'];
        $fingerprint = Fingerprint::of(['kind' => 'component.publish', 'id' => $id, 'expectedVersion' => $expected]);

        $result = $this->transactions->run(function () use ($ctx, $id, $expected, $key, $fingerprint) {
            $this->authorizer->authorize($ctx, 'page.publish');
            $row = $this->load($ctx->siteId, $id, lock: true);
            $existing = DB::table('reusable_component_versions')->where('site_id', $ctx->siteId)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new ConflictException('This publish key was already used for a different component or version');
                }

                return ['version' => (int) $existing->version, 'replayed' => true, 'queued' => 0];
            }
            if ((int) $row->version !== $expected) {
                throw new StaleVersionException($expected, (int) $row->version);
            }
            // The published document is exactly what instances will render: current versions, valid, publishable.
            $doc = $this->registry->migrateDocument(Json::decode($row->draft));
            $this->validateForSave($ctx->siteId, $doc);
            $problems = $this->validator->publishIssues($doc);
            if ($problems !== []) {
                throw new ValidationException('Fix these problems before publishing', $problems);
            }
            $this->store->lockNextEpoch($ctx->siteId);
            $version = (int) ($row->published_version ?? 0) + 1;
            DB::table('reusable_component_versions')->insert([
                'site_id' => $ctx->siteId, 'component_id' => $id, 'version' => $version, 'name' => $row->name,
                'document' => Json::encode($doc), 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'published_by' => $ctx->userId,
            ]);
            DB::table('reusable_components')->where('id', $id)->update(['published_version' => $version]);
            $queued = $this->refreshes->enqueue($ctx->siteId, 'component', $id, $version);
            $this->audit->forContext($ctx, 'component.publish', 'component', $id, ['version' => $version, 'refreshes' => $queued]);

            return ['version' => $version, 'replayed' => false, 'queued' => $queued];
        });
        $counts = $this->refreshes->run($ctx->siteId);

        return ['version' => $result['version'], 'replayed' => $result['replayed'], 'refreshes' => ['queued' => $result['queued'], ...$counts]];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function load(string $siteId, string $id, bool $lock = false): object
    {
        $query = DB::table('reusable_components')->where('site_id', $siteId)->where('id', Input::id($id, 'Reusable component'));

        return ($lock ? $query->lockForUpdate()->first() : $query->first()) ?? throw new NotFoundException('Reusable component');
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ValidationException('Give the component a name of 1 to 80 characters');
        }

        return $name;
    }

    /** A fragment document holding the given top-level block (insertNode form), or empty. */
    private function fragmentFrom(mixed $nodes): array
    {
        $rootId = Operations::newNodeId();
        $fragment = $this->registry->current('fragment');
        $doc = [
            'schemaVersion' => Rules::get('schemaVersion'), 'root' => $rootId,
            'nodes' => [$rootId => ['id' => $rootId, 'type' => 'fragment', 'version' => $fragment->version, 'props' => new stdClass, 'children' => []]],
            'seo' => new stdClass,
        ];
        if ($nodes === [] || $nodes === null) {
            return $doc;
        }
        try {
            return Operations::apply($doc, [['op' => 'insertNode', 'parentId' => $rootId, 'index' => 0, 'nodes' => $nodes]])['doc'];
        } catch (OperationException $error) {
            throw new ValidationException($error->getMessage());
        }
    }

    /** Valid fragment whose images belong to this site. */
    private function validateForSave(string $siteId, mixed $doc): void
    {
        $issues = $this->validator->validateFragment($doc);
        if ($issues !== []) {
            throw new ValidationException('The component is not valid', $issues);
        }
        $refs = $this->validator->mediaRefs($doc);
        $found = $this->media->mediaMap($siteId, $refs);
        $missing = array_values(array_filter($refs, fn ($ref) => ! isset($found[$ref])));
        if ($missing !== []) {
            throw new ValidationException('An image in this component does not exist', array_map(fn ($ref) => ['message' => "Image {$ref} not found"], $missing));
        }
    }

    /** The fragment rendered as a page of its own (its root becomes a page; the blocks are the same). */
    private function asPage(mixed $doc): mixed
    {
        if (! is_array($doc) || ! isset($doc['root'])) {
            return $doc;
        }
        $page = $this->registry->current('page');
        $doc['nodes'] = Json::entries($doc['nodes']);
        $doc['nodes'][$doc['root']] = [...Json::entries($doc['nodes'][$doc['root']]), 'type' => 'page', 'version' => $page->version, 'props' => new stdClass];

        return $doc;
    }

    private function draftDiffers(object $row): bool
    {
        if ($row->published_version === null) {
            return true;
        }
        $published = DB::table('reusable_component_versions')->where('component_id', $row->id)->where('version', $row->published_version)->value('document');

        return Json::canonical(Json::decode($published)) !== Json::canonical($this->registry->migrateDocument(Json::decode($row->draft)));
    }

    private function livePagesUsing(string $siteId, string $id): int
    {
        return DB::table('live_pages as l')
            ->join('publication_dependencies as d', 'd.publication_id', '=', 'l.publication_id')
            ->where('l.site_id', $siteId)->where('d.kind', 'component')->where('d.resource_id', $id)
            ->distinct()->count('l.page_id');
    }
}
