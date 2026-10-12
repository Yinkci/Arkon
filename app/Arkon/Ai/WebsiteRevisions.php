<?php

namespace App\Arkon\Ai;

use App\Arkon\Ai\Images\ImageSpec;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Design\TokenService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;

/**
 * "Request changes" on a website proposal: a new proposal whose starting point is the selected
 * proposal's own result (before it is applied) or the latest saved drafts (after it is applied), and
 * whose changes must stay inside the selected section, page or website.
 *
 * The snapshot of a revision keeps the database state the result will be applied to (`pages`, shared
 * versions, token and settings versions), exactly like a first proposal, so applying stays one
 * version-checked transaction. `baseline` holds the parent's result (resources that may not exist in
 * the database yet), `revision` the target. The parent proposal is never changed.
 *
 * Scope selects where, not what kind of change: a revision may do what the member could do in the
 * builder, inside the selected part of the site. A section revision may change that block, what is
 * inside it, the forms shown in it and the reusable components placed in it; a page revision may
 * change the page's blocks, title, URL and SEO, its forms and its components (shared header and
 * footer included). Other pages, unselected sections, navigation and branding stay as they are, and
 * that is enforced here on the compiled result, whatever the model was told. Shared resources that
 * other pages also show are reported (impacts) and confirmed before applying.
 */
final class WebsiteRevisions
{
    public const SCOPES = ['section', 'page', 'website'];

    public function __construct(private readonly DocumentValidator $validator) {}

    /** The snapshot a revision of $parent starts from. Call inside the request transaction, after authorization. */
    public function prepare(SiteContext $ctx, WebsiteProposalService $website, object $parent, array $target): array
    {
        if (! in_array($parent->status, ['proposed', 'applied'], true)) {
            throw new ValidationException('Only a proposal that is ready for review or already applied can be revised. Start a new request instead.');
        }
        $scope = $target['scope'] ?? null;
        if (! in_array($scope, self::SCOPES, true)) {
            throw new ValidationException('Choose what the change applies to: the selected section, the selected page or the entire website.');
        }
        if ($parent->status === 'proposed') {
            $snapshot = Json::decode($parent->website_snapshot);
            if (($conflict = $this->conflict($ctx->siteId, $snapshot)) !== null) {
                throw new ConflictException($conflict.' This proposal can no longer be revised; apply or discard it, or start a new request from the saved drafts.');
            }
            $baseline = $website->storedResult($parent->website_result);
            $kind = 'proposal';
            // Fresh library metadata; the database state stays the parent's (it was just checked to be current).
            $snapshot['assets'] = $website->libraryAssets($ctx->siteId);
        } else {
            $snapshot = $website->snapshot($ctx);
            $baseline = null;
            $kind = 'drafts';
        }
        unset($snapshot['revision'], $snapshot['baseline'], $snapshot['images'], $snapshot['allowRepair']);
        $pages = self::effectivePages($snapshot, $baseline);
        $label = 'Entire website';
        $pageId = $target['pageId'] ?? null;
        $nodeId = $target['nodeId'] ?? null;
        if ($scope !== 'website') {
            $page = $pages[$pageId ?? ''] ?? throw new NotFoundException('The selected page (it is no longer part of this website; choose another target)');
            $label = $page['title'].' ('.$page['path'].')';
            if ($scope === 'section') {
                $nodes = Json::entries($page['document']['nodes']);
                if (! is_string($nodeId) || ! isset($nodes[$nodeId]) || $nodeId === $page['document']['root']) {
                    throw new NotFoundException('The selected section (it is not in the '.($kind === 'drafts' ? 'latest saved draft' : 'proposal').' any more; choose it again)');
                }
                $label = self::describe($nodes, $nodeId).' on '.$label;
            }
        } else {
            $pageId = $nodeId = null;
        }
        $snapshot['baseline'] = $baseline;
        $snapshot['scope'] = $scope === 'website' ? ['siteForm' => true, 'forms' => [], 'shared' => ['header', 'footer'], 'components' => []]
            : $this->resources($ctx->siteId, $website, $snapshot, $baseline, $pages[$pageId]['document'], $scope === 'section' ? $nodeId : null);
        // A website revision may change the shared layout when the site (or its baseline) has one, as a first request would.
        $hasLayout = (int) $snapshot['shared']['header']['version'] > 0 || (int) $snapshot['shared']['footer']['version'] > 0;
        $snapshot['includeLayout'] = $scope === 'website' && (bool) ($baseline['includeLayout'] ?? $hasLayout);
        $snapshot['revision'] = ['parentId' => $parent->id, 'parentPrompt' => mb_substr((string) $parent->prompt, 0, 2000), 'scope' => $scope, 'pageId' => $pageId, 'nodeId' => $nodeId, 'baselineKind' => $kind, 'label' => $label];

        return $snapshot;
    }

    /**
     * The forms and reusable components a section (with its descendants) or a page shows, with the
     * stored version each change will be checked against. The site's website form and the shared
     * header and footer are named; other forms and components are captured here.
     */
    private function resources(string $siteId, WebsiteProposalService $website, array $snapshot, ?array $baseline, array $doc, ?string $nodeId): array
    {
        $nodes = Json::entries($doc['nodes']);
        $ids = $nodeId === null ? array_keys($nodes) : self::subtree($nodes, $nodeId);
        $forms = [];
        $components = [];
        foreach ($ids as $id) {
            $props = Json::entries($nodes[$id]['props'] ?? []);
            if ($nodes[$id]['type'] === 'form' && is_string($formId = Json::entries($props['form'] ?? [])['id'] ?? null)) {
                $forms[$formId] = true;
            }
            if ($nodes[$id]['type'] === 'instance' && is_string($props['componentId'] ?? null)) {
                $components[$props['componentId']] = true;
            }
        }
        $out = ['siteForm' => isset($forms[$snapshot['form']['id']]), 'forms' => [], 'shared' => [], 'components' => []];
        unset($forms[$snapshot['form']['id']]);
        $earlier = array_column($baseline['forms'] ?? [], null, 'id');
        foreach (DB::table('site_forms')->where('site_id', $siteId)->whereIn('id', array_keys($forms))->whereNull('archived_at')->get() as $row) {
            $out['forms'][$row->id] = ['version' => (int) $row->version, 'name' => $row->name, 'definition' => $earlier[$row->id]['definition'] ?? Json::decode($row->draft)];
        }
        foreach ($snapshot['shared'] as $slot => $r) {
            if (isset($components[$r['id']])) {
                $out['shared'][] = $slot;
                unset($components[$r['id']]);
            }
        }
        $earlier = array_column($baseline['components'] ?? [], null, 'id');
        foreach (DB::table('reusable_components')->where('site_id', $siteId)->whereIn('id', array_keys($components))->get() as $row) {
            $out['components'][$row->id] = ['version' => (int) $row->version, 'name' => $row->name, 'document' => $earlier[$row->id]['document'] ?? $website->asFragment(app(ComponentRegistry::class)->migrateDocument(Json::decode($row->draft)))];
        }

        return $out;
    }

    /**
     * Why the database no longer matches a snapshot (a page, the page list, shared layout, menu, form,
     * branding or site settings changed), or null when it still does.
     */
    public function conflict(string $siteId, array $snapshot): ?string
    {
        $drafts = DB::table('pages as p')->join('page_drafts as d', 'd.page_id', '=', 'p.id')->where('p.site_id', $siteId)->where('p.kind', 'page')->whereNull('p.deleted_at')->pluck('d.version', 'p.id')->all();
        ksort($drafts);
        $expected = array_map(fn ($p) => (int) $p['version'], $snapshot['pages']);
        ksort($expected);
        if (array_keys($drafts) !== array_keys($expected)) {
            return 'Pages were added or removed after this proposal was made.';
        }
        foreach ($expected as $id => $version) {
            if ((int) $drafts[$id] !== $version) {
                return 'A page was edited after this proposal was made.';
            }
        }
        if (TokenService::draftVersion($siteId) !== (int) $snapshot['tokenVersion']) {
            return 'Branding was changed after this proposal was made.';
        }
        if ((int) DB::table('site_website_settings')->where('site_id', $siteId)->value('version') !== (int) $snapshot['settingsVersion']) {
            return 'The website settings changed after this proposal was made.';
        }
        foreach ($snapshot['shared'] as $slot => $r) {
            if ((int) DB::table('reusable_components')->where('site_id', $siteId)->where('id', $r['id'])->value('version') !== (int) $r['version']) {
                return 'The shared '.$slot.' changed after this proposal was made.';
            }
        }
        if ((int) DB::table('site_menus')->where('site_id', $siteId)->where('id', $snapshot['menu']['id'])->value('version') !== (int) $snapshot['menu']['version']) {
            return 'The navigation changed after this proposal was made.';
        }
        if ((int) DB::table('site_forms')->where('site_id', $siteId)->where('id', $snapshot['form']['id'])->value('version') !== (int) $snapshot['form']['version']) {
            return 'The form changed after this proposal was made.';
        }
        foreach ($snapshot['scope']['forms'] ?? [] as $id => $f) {
            if ((int) DB::table('site_forms')->where('site_id', $siteId)->where('id', $id)->value('version') !== $f['version']) {
                return 'The form “'.$f['name'].'” changed after this proposal was made.';
            }
        }
        foreach ($snapshot['scope']['components'] ?? [] as $id => $c) {
            if ((int) DB::table('reusable_components')->where('site_id', $siteId)->where('id', $id)->value('version') !== $c['version']) {
                return 'The component “'.$c['name'].'” changed after this proposal was made.';
            }
        }

        return null;
    }

    /**
     * Every page as the revision sees it: saved drafts, overlaid with the baseline's pages (changed or new).
     *
     * @return array<string, array{id: string, title: string, path: string, document: array, version: int, new: bool, inBaseline: bool}>
     */
    public static function effectivePages(array $snapshot, ?array $baseline): array
    {
        $out = [];
        foreach ($snapshot['pages'] as $id => $p) {
            $out[$id] = ['id' => $id, 'title' => $p['title'], 'path' => $p['path'], 'document' => $p['document'], 'version' => (int) $p['version'], 'new' => false, 'inBaseline' => false];
        }
        foreach ($baseline['pages'] ?? [] as $p) {
            $out[$p['id']] = ['id' => $p['id'], 'title' => $p['title'], 'path' => $p['path'], 'document' => $p['document'], 'version' => (int) $p['baseVersion'], 'new' => ! $p['existing'], 'inBaseline' => true];
        }

        return $out;
    }

    /** The result a revision starts from: the parent's result, or "no changes" against the saved drafts. */
    public static function baseResult(array $snapshot): array
    {
        if (($snapshot['baseline'] ?? null) !== null) {
            return self::componentForm($snapshot['baseline']);
        }
        $shared = [];
        foreach ($snapshot['shared'] as $slot => $r) {
            if ((int) $r['version'] > 0) {
                // The snapshot keeps the layout page-shaped for editing; a result holds the component.
                $shared[$slot] = [...$r, 'document' => self::asComponent($r['document']), 'changed' => false];
            }
        }

        return ['includeLayout' => false, 'summary' => '', 'pages' => [], 'shared' => $shared,
            'menu' => (int) $snapshot['menu']['version'] > 0 ? ['id' => $snapshot['menu']['id'], 'baseVersion' => (int) $snapshot['menu']['version'], 'definition' => $snapshot['menu']['definition'], 'changed' => false] : null,
            'form' => null, 'forms' => [], 'components' => [], 'tokenChanges' => [], 'notes' => [], 'images' => []];
    }

    /**
     * The shared header and footer of a result are reusable components (root `fragment`), as stored, applied and
     * rendered. The snapshot holds them page-shaped for the model to edit (WebsiteProposalService::asPage); a result
     * built from the snapshot must convert them back. Results stored before this was enforced (October 2026, a
     * revision of an applied proposal) carry a page root here: the conversion is deterministic (the page root of a
     * shared layout never has props of its own), so they are read in component form without being rewritten.
     */
    public static function componentForm(array $result): array
    {
        foreach ($result['shared'] ?? [] as $slot => $s) {
            $result['shared'][$slot]['document'] = self::asComponent($s['document']);
        }

        return $result;
    }

    public static function asComponent(mixed $doc): mixed
    {
        $root = is_array($doc) ? ($doc['root'] ?? null) : null;
        if (! is_string($root) || ($doc['nodes'][$root]['type'] ?? null) !== 'page') {
            return $doc;
        }
        $doc['nodes'][$root] = [...$doc['nodes'][$root], 'type' => 'fragment', 'version' => app(ComponentRegistry::class)->current('fragment')->version, 'props' => new \stdClass];

        return $doc;
    }

    /** Schema and instructions for a section or page revision (a website revision uses the website format). */
    public function format(WebsiteProposalService $website, array $snapshot): array
    {
        $full = $website->format($snapshot);
        // Built on the uncompacted schema: compaction renames $defs, so a hand-written $ref must not point into it.
        $schema = $full['rawSchema'];
        $obj = fn ($props, $required) => ['type' => 'object', 'additionalProperties' => false, 'properties' => $props, 'required' => $required];
        $outOfScope = ['anyOf' => [['type' => 'null'], $obj(['scope' => ['type' => 'string', 'enum' => ['page', 'website']], 'reason' => ['type' => 'string', 'maxLength' => 600]], ['scope', 'reason'])]];
        $revision = $snapshot['revision'];
        if ($revision['scope'] === 'website') {
            // Nothing is outside the entire website, so this format has no outOfScope field.
            return ['schema' => SchemaCompactor::compact($schema), 'instructions' => $full['instructions']."\n".$this->rules($revision)];
        }
        $forms = self::formIds($snapshot);
        $components = self::componentIds($snapshot);
        $props = [
            'summary' => ['type' => 'string'],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'proposal' => ['$ref' => '#/$defs/pageProposal'],
            'seo' => ['anyOf' => [['type' => 'null'], $obj(['title' => ['type' => 'string'], 'description' => ['type' => 'string']], ['title', 'description'])]],
            ...($revision['scope'] === 'page' ? ['settings' => $obj(['title' => ['type' => ['string', 'null']], 'path' => ['type' => ['string', 'null']]], ['title', 'path'])] : []),
            ...($forms !== [] ? ['formChanges' => FormChanges::schema($forms)] : []),
            ...($components !== [] ? ['components' => ['type' => 'array', 'maxItems' => count($components), 'items' => $obj(['id' => ['type' => 'string', 'enum' => $components], 'proposal' => ['$ref' => '#/$defs/pageProposal']], ['id', 'proposal'])]] : []),
            ...(isset($schema['properties']['generatedImages']) ? ['generatedImages' => $schema['properties']['generatedImages']] : []),
            'outOfScope' => $outOfScope,
        ];
        $page = $obj($props, array_keys($props));
        $page['$defs'] = $schema['$defs'];

        return ['schema' => SchemaCompactor::compact($page), 'instructions' => $full['pageInstructions']."\n".$this->rules($revision)];
    }

    /** Form ids a revision may change: the forms its target shows (all for an entire-website revision). */
    public static function formIds(array $snapshot): array
    {
        $scope = $snapshot['scope'] ?? ['siteForm' => false, 'forms' => []];

        return [...(($scope['siteForm'] ?? false) ? [$snapshot['form']['id']] : []), ...array_keys($scope['forms'] ?? [])];
    }

    /** Reusable component ids a section or page revision may change (shared header and footer included). */
    public static function componentIds(array $snapshot): array
    {
        $scope = $snapshot['scope'] ?? ['shared' => [], 'components' => []];

        return [...array_map(fn ($slot) => $snapshot['shared'][$slot]['id'], $scope['shared'] ?? []), ...array_keys($scope['components'] ?? [])];
    }

    private function rules(array $revision): string
    {
        $scope = match ($revision['scope']) {
            'section' => "SECTION: the block with id {$revision['nodeId']} and everything inside it may change (update, add inside, remove inside, or remove it), and so may the forms shown in it (formChanges) and the reusable components placed in it (components). Every other block of the page, the page's title, URL and SEO, other pages, navigation and branding must stay exactly as they are.",
            'page' => 'PAGE: everything on the page with id '.$revision['pageId'].' may change: its blocks, its title and URL (settings; null keeps them), its SEO, the forms shown on it (formChanges) and the reusable components placed on it (components), including the shared header and footer. Other pages, navigation and branding must stay exactly as they are.',
            default => 'WEBSITE: pages, shared layout, navigation, forms and branding may change. Keep everything the request does not mention exactly as it is.',
        };

        return "\nREVISION. You are revising an existing website proposal, not building a new one. The context contains the current result to change (<baseline>); the user's request below describes only the change. Do not repeat or rebuild the original brief.\n"
            ."Scope: {$scope} Arkon rejects any change outside the scope.\n"
            .'Make the smallest change that fulfils the request: refer to existing block ids; update a property to replace an image or text instead of removing and re-adding blocks; never recreate sections, pages or forms that already exist. '
            .'A form or shared component may also appear on other pages; changing it there is expected and the user is shown the impact before applying. Anything the site builder can do inside this scope is allowed. '
            .FormChanges::RULES_SHORT.' '
            .'Only if the request needs a change outside this scope (another page, an unselected section, navigation or branding), return no changes and set outOfScope to the scope that is needed and why (in plain words for the user).';
    }

    /** The model's view of a section or page revision: the target page in full, everything else only as names. */
    public function context(WebsiteProposalService $website, ProposalPrompt $prompts, array $snapshot): array
    {
        $revision = $snapshot['revision'];
        $pages = self::effectivePages($snapshot, $snapshot['baseline']);
        $base = self::baseResult($snapshot);
        $form = $base['form']['definition'] ?? $snapshot['form']['definition'];
        $context = ['site' => $snapshot['site'], 'originalBrief' => $revision['parentPrompt'], 'target' => array_intersect_key($revision, array_flip(['scope', 'pageId', 'nodeId', 'label'])),
            'assets' => $snapshot['assets'], 'images' => $website->imageContext($snapshot), 'themeTypes' => $snapshot['themeTypes'], 'componentIds' => $snapshot['componentIds']];
        if ($revision['scope'] === 'website') {
            $context['pages'] = array_values(array_map(fn ($p) => ['pageId' => $p['id'], 'title' => $p['title'], 'path' => $p['path'], 'new' => $p['new'], 'seo' => $p['document']['seo'], 'blocks' => $prompts->blocks($p['document'])], $pages));
            foreach (['header', 'footer'] as $slot) {
                $doc = $base['shared'][$slot]['document'] ?? $snapshot['shared'][$slot]['document'];
                $context['shared'][$slot] = ['id' => $snapshot['shared'][$slot]['id'], 'blocks' => $prompts->blocks($doc)];
            }
            $context['menu'] = ['id' => $snapshot['menu']['id'], 'definition' => $base['menu']['definition'] ?? $snapshot['menu']['definition']];
            $context['form'] = ['id' => $snapshot['form']['id'], 'current' => $form === null ? null : FormChanges::context($snapshot['form']['id'], $form)];
            $context['tokenChanges'] = $base['tokenChanges'];
        } else {
            $page = $pages[$revision['pageId']];
            $context['page'] = ['pageId' => $page['id'], 'title' => $page['title'], 'path' => $page['path'], 'seo' => $page['document']['seo'], 'blocks' => $prompts->blocks($page['document'])];
            $context['otherPages'] = array_values(array_map(fn ($p) => ['title' => $p['title'], 'path' => $p['path']], array_filter($pages, fn ($p) => $p['id'] !== $page['id'])));
            $scope = $snapshot['scope'];
            $context['forms'] = [
                ...(($scope['siteForm'] ?? false) && $form !== null ? [FormChanges::context($snapshot['form']['id'], $form)] : []),
                ...array_map(fn ($id, $f) => FormChanges::context($id, collect($base['forms'] ?? [])->firstWhere('id', $id)['definition'] ?? $f['definition']), array_keys($scope['forms']), $scope['forms']),
            ];
            $context['components'] = [
                ...array_map(fn ($slot) => ['id' => $snapshot['shared'][$slot]['id'], 'name' => 'Shared '.$slot.' (every page)', 'blocks' => $prompts->blocks($website->asPage($base['shared'][$slot]['document'] ?? $snapshot['shared'][$slot]['document']))], $scope['shared']),
                ...array_map(fn ($id, $c) => ['id' => $id, 'name' => $c['name'], 'blocks' => $prompts->blocks($website->asPage(collect($base['components'] ?? [])->firstWhere('id', $id)['document'] ?? $c['document']))], array_keys($scope['components']), $scope['components']),
            ];
            $context['menuIds'] = $snapshot['menuIds'];
        }

        return $context;
    }

    /** Applies a section or page revision reply to the baseline, then enforces the scope. */
    public function compile(WebsiteProposalService $website, array $snapshot, array $reply): array
    {
        $revision = $snapshot['revision'];
        $this->outOfScope($reply);
        $base = self::baseResult($snapshot);
        if ($revision['scope'] === 'website') {
            $out = $this->compileWebsite($website, $snapshot, $base, $reply);
        } else {
            $page = self::effectivePages($snapshot, $snapshot['baseline'])[$revision['pageId']];
            $compiled = $website->compilePage($snapshot, $page['document'], is_array($reply['proposal'] ?? null) ? $reply['proposal'] : [], 'Page '.$page['path']);
            $doc = $compiled['document'];
            // Applied whenever returned: for a section revision the scope check then refuses it.
            if (is_array($reply['seo'] ?? null)) {
                $doc['seo'] = [...Json::entries($doc['seo']), ...Input::validate($reply['seo'], ['title' => ['required', 'string', 'max:120'], 'description' => ['required', 'string', 'max:320']])];
            }
            $out = $base;
            $found = false;
            foreach ($out['pages'] as &$p) {
                if ($p['id'] === $page['id']) {
                    $p['document'] = $doc;
                    $p['changes'] = [...$p['changes'], ...$compiled['changes']];
                    $found = true;
                }
            } unset($p);
            if (! $found) {
                $out['pages'][] = ['id' => $page['id'], 'existing' => true, 'baseVersion' => $page['version'], 'title' => $page['title'], 'path' => $page['path'], 'document' => $doc, 'changes' => $compiled['changes']];
            }
            $out['summary'] = mb_substr(trim((string) ($reply['summary'] ?? '')) ?: $compiled['summary'], 0, 1000);
            $out['notes'] = array_values(array_unique([...array_filter(array_map(fn ($n) => is_string($n) ? trim($n) : '', array_slice($reply['notes'] ?? [], 0, 8))), ...$compiled['notes'], ...$compiled['warnings']]));
            $out['revisionChanges'] = $compiled['changes'] === [] ? [] : [['page' => $page['title'].' ('.$page['path'].')', 'changes' => $compiled['changes']]];
            $other = $this->compileResources($website, $snapshot, $out, $reply);
            if ($compiled['operations'] === [] && ! $other && self::canon($doc['seo']) === self::canon($page['document']['seo'])) {
                throw new AiException(AiException::INVALID_OUTPUT, 'The revision contains no changes. Nothing was proposed; rephrase the request or choose a broader scope.');
            }
        }
        $this->assertScope($snapshot, $base, $out);

        return $out;
    }

    /**
     * The forms, components and page settings a section or page revision changes, written into $out.
     * Anything the target does not show is refused here; returns whether something changed.
     */
    private function compileResources(WebsiteProposalService $website, array $snapshot, array &$out, array $reply): bool
    {
        $revision = $snapshot['revision'];
        $scope = $snapshot['scope'];
        $changed = false;
        $outside = fn (string $what) => new AiException(AiException::SCOPE_EXCEEDED, 'The revision would change '.$what.', which “'.$revision['label'].'” does not show. Nothing was changed. If the request needs that, choose a broader scope and run it again.');
        foreach (FormChanges::byForm($reply['formChanges'] ?? []) as $formId => $ops) {
            if ($formId === $snapshot['form']['id'] && ($scope['siteForm'] ?? false)) {
                $edited = FormChanges::apply($out['form']['definition'] ?? $snapshot['form']['definition'], $ops, 'Form');
                $out['form'] = ['id' => $formId, 'baseVersion' => (int) $snapshot['form']['version'], 'definition' => $edited['definition']];
            } elseif (isset($scope['forms'][$formId])) {
                $current = collect($out['forms'] ?? [])->firstWhere('id', $formId);
                $edited = FormChanges::apply($current['definition'] ?? $scope['forms'][$formId]['definition'], $ops, 'Form “'.$scope['forms'][$formId]['name'].'”');
                $out['forms'] = [...array_values(array_filter($out['forms'] ?? [], fn ($f) => $f['id'] !== $formId)), ['id' => $formId, 'baseVersion' => $scope['forms'][$formId]['version'], 'definition' => $edited['definition']]];
            } else {
                throw $outside('a form');
            }
            $out['revisionChanges'][] = ['page' => 'Form “'.$edited['definition']['name'].'”', 'changes' => $edited['changes']];
            $changed = $changed || $edited['changes'] !== [];
        }
        foreach (is_array($reply['components'] ?? null) ? $reply['components'] : [] as $c) {
            $id = is_array($c) ? ($c['id'] ?? null) : null;
            $slot = array_search($id, array_map(fn ($s) => $snapshot['shared'][$s]['id'], ['header' => 'header', 'footer' => 'footer']), true);
            if ($slot !== false && in_array($slot, $scope['shared'] ?? [], true)) {
                $current = $out['shared'][$slot] ?? [...$snapshot['shared'][$slot], 'changed' => false];
                $compiled = $website->compileComponent($snapshot, $current['document'], is_array($c['proposal'] ?? null) ? $c['proposal'] : [], 'Shared '.$slot, layout: true);
                if ($compiled['operations'] !== []) {
                    $out['shared'][$slot] = [...$current, 'document' => $compiled['document'], 'changed' => true];
                }
                $name = 'Shared '.$slot;
            } elseif (is_string($id) && isset($scope['components'][$id])) {
                $base = $scope['components'][$id];
                $current = collect($out['components'] ?? [])->firstWhere('id', $id) ?? ['id' => $id, 'version' => $base['version'], 'name' => $base['name'], 'document' => $base['document']];
                $compiled = $website->compileComponent($snapshot, $current['document'], is_array($c['proposal'] ?? null) ? $c['proposal'] : [], 'Component “'.$base['name'].'”', layout: false);
                if ($compiled['operations'] !== []) {
                    $out['components'] = [...array_values(array_filter($out['components'] ?? [], fn ($x) => $x['id'] !== $id)), [...$current, 'document' => $compiled['document']]];
                }
                $name = 'Component “'.$base['name'].'”';
            } else {
                throw $outside('a reusable component');
            }
            if ($compiled['changes'] !== []) {
                $out['revisionChanges'][] = ['page' => $name, 'changes' => $compiled['changes']];
                $changed = true;
            }
        }
        $settings = $reply['settings'] ?? null;
        if ($revision['scope'] === 'page' && is_array($settings) && (($settings['title'] ?? null) !== null || ($settings['path'] ?? null) !== null)) {
            $pages = self::effectivePages($snapshot, $out);
            foreach ($out['pages'] as &$p) {
                if ($p['id'] !== $revision['pageId']) {
                    continue;
                }
                $title = ($settings['title'] ?? null) !== null ? Input::title($settings['title']) : $p['title'];
                $path = ($settings['path'] ?? null) !== null ? Input::path($settings['path']) : $p['path'];
                foreach ($pages as $other) {
                    if ($other['id'] !== $p['id'] && $other['path'] === $path) {
                        throw new ValidationException('Another page already uses the URL '.$path.'.');
                    }
                }
                $notes = [];
                if ($title !== $p['title']) {
                    $notes[] = 'Page title: '.$p['title'].' → '.$title;
                }
                if ($path !== $p['path']) {
                    $notes[] = 'Page URL: '.$p['path'].' → '.$path.($p['existing'] ? ' (after publishing, the old URL redirects to the new one)' : '');
                }
                [$p['title'], $p['path']] = [$title, $path];
                if ($notes !== []) {
                    $out['revisionChanges'][] = ['page' => 'Page settings', 'changes' => $notes];
                    $changed = true;
                }
            } unset($p);
        }

        return $changed;
    }

    /** A website-scope revision: the website format against the baseline, merged with what the reply leaves alone. */
    private function compileWebsite(WebsiteProposalService $website, array $snapshot, array $base, array $reply): array
    {
        $pages = self::effectivePages($snapshot, $snapshot['baseline']);
        $working = $snapshot;
        $working['pages'] = array_map(fn ($p) => ['id' => $p['id'], 'title' => $p['title'], 'path' => $p['path'], 'version' => $p['new'] ? 0 : $p['version'], 'document' => $p['document'], 'new' => $p['new']], $pages);
        foreach (['header', 'footer'] as $slot) {
            if (isset($base['shared'][$slot])) {
                // compile() edits the layout page-shaped, like the snapshot it normally reads.
                $working['shared'][$slot]['document'] = $website->asPage($base['shared'][$slot]['document']);
            }
        }
        if (isset($base['menu']['definition'])) {
            $working['menu']['definition'] = $base['menu']['definition'];
        }
        if (isset($base['form']['definition'])) {
            $working['form']['definition'] = $base['form']['definition'];
        }
        $out = $website->compile($working, $reply);
        $out['forms'] = $base['forms'] ?? [];
        $out['components'] = $base['components'] ?? [];
        $returned = array_column($out['pages'], 'id');
        foreach ($base['pages'] as $p) {
            if (! in_array($p['id'], $returned, true)) {
                $out['pages'][] = $p;
            }
        }
        foreach ($out['pages'] as &$p) {
            $p['existing'] = isset($snapshot['pages'][$p['id']]);
            $p['baseVersion'] = $p['existing'] ? (int) $snapshot['pages'][$p['id']]['version'] : 0;
        } unset($p);
        foreach ($out['shared'] as $slot => &$r) {
            $r['changed'] = $r['changed'] || ($base['shared'][$slot]['changed'] ?? false);
        } unset($r);
        if ($out['menu'] !== null && ($base['menu']['changed'] ?? false)) {
            $out['menu']['changed'] = true;
        }
        $out['form'] ??= $base['form'];
        $tokens = [];
        foreach ([...$base['tokenChanges'], ...$out['tokenChanges']] as $c) {
            $tokens[$c['token']] = $c;
        }
        $out['tokenChanges'] = array_values($tokens);
        $out['notes'] = array_values(array_unique($out['notes']));
        $out['revisionChanges'] = [...array_map(fn ($p) => ['page' => $p['title'].' ('.$p['path'].')', 'changes' => $p['changes']], array_values(array_filter($out['pages'], fn ($p) => in_array($p['id'], $returned, true)))),
            ...(($out['formChanges'] ?? []) !== [] ? [['page' => 'Form “'.$out['form']['definition']['name'].'”', 'changes' => $out['formChanges']]] : [])];

        return $out;
    }

    private function outOfScope(array $reply): void
    {
        $o = $reply['outOfScope'] ?? null;
        if (is_array($o) && in_array($o['scope'] ?? null, ['page', 'website'], true)) {
            $needed = $o['scope'] === 'page' ? 'Selected page' : 'Entire website';
            throw new AiException(AiException::SCOPE_EXCEEDED, 'This change needs a broader scope: '.mb_substr(trim((string) ($o['reason'] ?? '')), 0, 600).' Nothing was changed. To continue, choose “'.$needed.'” and run the request again.');
        }
    }

    /** Refuses a result that changes anything outside the revision's scope, with what would have changed. */
    public function assertScope(array $snapshot, array $base, array $out): void
    {
        $revision = $snapshot['revision'];
        if ($revision['scope'] === 'website') {
            return;
        }
        $violations = [];
        $before = self::effectivePages($snapshot, $base);
        $after = self::effectivePages($snapshot, $out);
        if (array_keys($before) !== array_keys($after) || count($out['pages']) < count($base['pages'])) {
            $violations[] = 'pages would be added or removed';
        }
        foreach ($after as $id => $p) {
            $b = $before[$id] ?? null;
            if ($b === null) {
                continue;
            }
            if ($id !== $revision['pageId']) {
                if (self::canon($b['document']) !== self::canon($p['document']) || $b['title'] !== $p['title'] || $b['path'] !== $p['path']) {
                    $violations[] = 'the page '.$b['title'].' ('.$b['path'].')';
                }

                continue;
            }
            if ($b['path'] !== $p['path'] && $revision['scope'] !== 'page') {
                $violations[] = 'the URL of '.$b['title'];
            }
            if ($revision['scope'] === 'section') {
                if ($b['title'] !== $p['title'] || self::canon($b['document']['seo']) !== self::canon($p['document']['seo'])) {
                    $violations[] = 'the title or SEO of '.$b['title'];
                }
                array_push($violations, ...$this->outsideSection($b['document'], $p['document'], $revision['nodeId'], $b['title']));
            }
        }
        foreach (['header', 'footer'] as $slot) {
            $x = $base['shared'][$slot] ?? null;
            $y = $out['shared'][$slot] ?? null;
            if (! in_array($slot, $snapshot['scope']['shared'] ?? [], true) && (self::canon($x['document'] ?? null) !== self::canon($y['document'] ?? null) || ($x['changed'] ?? false) !== ($y['changed'] ?? false))) {
                $violations[] = 'the shared '.$slot;
            }
        }
        if (self::canon($base['menu']['definition'] ?? null) !== self::canon($out['menu']['definition'] ?? null) || ($base['menu']['changed'] ?? false) !== ($out['menu']['changed'] ?? false)) {
            $violations[] = 'the navigation';
        }
        if (! ($snapshot['scope']['siteForm'] ?? false) && self::canon($base['form'] ?? null) !== self::canon($out['form'] ?? null)) {
            $violations[] = 'a form this part of the page does not show';
        }
        foreach ($out['forms'] ?? [] as $f) {
            if (! isset($snapshot['scope']['forms'][$f['id']]) && self::canon(collect($base['forms'] ?? [])->firstWhere('id', $f['id'])) !== self::canon($f)) {
                $violations[] = 'a form this part of the page does not show';
            }
        }
        foreach ($out['components'] ?? [] as $c) {
            if (! isset($snapshot['scope']['components'][$c['id']]) && self::canon(collect($base['components'] ?? [])->firstWhere('id', $c['id'])) !== self::canon($c)) {
                $violations[] = 'a component this part of the page does not show';
            }
        }
        if (self::canon($base['tokenChanges']) !== self::canon($out['tokenChanges'])) {
            $violations[] = 'the site branding';
        }
        if ($violations !== []) {
            $violations = array_values(array_unique($violations));
            throw new AiException(AiException::SCOPE_EXCEEDED, 'The generated revision would change '.implode(', ', $violations).', which is outside “'.$revision['label'].'”. Nothing was changed. If the request needs that, choose a broader scope and run it again.', array_map(fn ($v) => ['message' => 'Outside the selected scope: '.$v], $violations));
        }
    }

    /** Blocks outside a section (and its descendants) that differ between two versions of a page. */
    private function outsideSection(array $before, array $after, string $nodeId, string $title): array
    {
        $b = Json::entries($before['nodes']);
        $a = Json::entries($after['nodes']);
        $inside = [...self::subtree($b, $nodeId), ...self::subtree($a, $nodeId)];
        $inside = array_flip($inside);
        $out = [];
        if ($before['root'] !== $after['root']) {
            return ['the structure of '.$title];
        }
        foreach (array_unique([...array_keys($b), ...array_keys($a)]) as $id) {
            if (isset($inside[$id])) {
                continue;
            }
            if (! isset($b[$id], $a[$id])) {
                $out[] = 'a block outside the selected section on '.$title;

                continue;
            }
            $children = fn ($n) => array_values(array_filter($n['children'] ?? [], fn ($c) => ! isset($inside[$c])));
            if ($b[$id]['type'] !== $a[$id]['type'] || self::canon($b[$id]['props']) !== self::canon($a[$id]['props']) || $children($b[$id]) !== $children($a[$id])) {
                $out[] = self::describe($b, $id).' on '.$title;
            }
            // The section keeps its place among its siblings.
            if (in_array($nodeId, $b[$id]['children'] ?? [], true) && in_array($nodeId, $a[$id]['children'] ?? [], true)) {
                $pos = fn ($n) => array_search($nodeId, array_values(array_filter($n['children'], fn ($c) => $c === $nodeId || ! isset($inside[$c]))), true);
                if ($pos($b[$id]) !== $pos($a[$id])) {
                    $out[] = 'the position of the selected section on '.$title;
                }
            }
        }

        return $out;
    }

    /** @return list<string> the node and everything inside it */
    public static function subtree(array $nodes, string $id): array
    {
        if (! isset($nodes[$id])) {
            return [];
        }
        $out = [$id];
        foreach ($nodes[$id]['children'] ?? [] as $child) {
            array_push($out, ...self::subtree($nodes, (string) $child));
        }

        return $out;
    }

    /** "Hero “Contact us”", "Section “Our services”": a block as a person would name it. */
    public static function describe(array $nodes, string $id): string
    {
        $node = $nodes[$id] ?? null;
        if ($node === null) {
            return 'A block';
        }
        $type = ucfirst(str_replace('-', ' ', $node['type']));
        $text = self::heading($nodes, $id);

        return $text === '' ? $type : $type.' “'.mb_strimwidth($text, 0, 48, '…').'”';
    }

    private static function heading(array $nodes, string $id, int $depth = 0): string
    {
        $node = $nodes[$id] ?? null;
        if ($node === null || $depth > 6) {
            return '';
        }
        $props = Json::entries($node['props'] ?? []);
        foreach (['heading', 'label'] as $key) {
            if (is_string($props[$key] ?? null) && trim($props[$key]) !== '') {
                return trim($props[$key]);
            }
        }
        if ($node['type'] === 'text' && in_array($props['element'] ?? '', ['h1', 'h2', 'h3'], true) && is_string($props['text'] ?? null)) {
            return trim($props['text']);
        }
        foreach ($node['children'] ?? [] as $child) {
            if (($text = self::heading($nodes, (string) $child, $depth + 1)) !== '') {
                return $text;
            }
        }

        return '';
    }

    /** The top-level blocks of a page a revision can target, in order (the shared header and footer excluded). */
    public static function sections(array $doc): array
    {
        $nodes = Json::entries($doc['nodes']);
        $out = [];
        foreach ($nodes[$doc['root']]['children'] ?? [] as $id) {
            if (($nodes[$id]['type'] ?? '') === 'instance') {
                continue;
            }
            $out[] = ['id' => $id, 'type' => $nodes[$id]['type'], 'label' => self::describe($nodes, $id)];
        }

        return $out;
    }

    /** A stable comparison form: objects and arrays alike, keys sorted. */
    public static function canon(mixed $value): string
    {
        $norm = function ($v) use (&$norm) {
            if ($v instanceof \stdClass) {
                $v = (array) $v;
            }
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($norm, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return json_encode($norm(Json::decode(Json::encode($value))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function imageSchema(array $reserved): array
    {
        return ['type' => 'array', 'maxItems' => count($reserved), 'items' => ImageSpec::schema($reserved)];
    }
}
