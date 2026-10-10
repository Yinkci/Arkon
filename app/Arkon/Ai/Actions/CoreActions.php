<?php

namespace App\Arkon\Ai\Actions;

use App\Arkon\Ai\Capabilities;
use App\Arkon\Ai\ProposalPrompt;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentReader;
use App\Arkon\Content\ContentTypes;
use App\Arkon\Content\Taxonomies;
use App\Arkon\Content\TermService;
use App\Arkon\Database\Transactions;
use App\Arkon\Media\MediaLibrary;
use App\Arkon\Pages\PageService;
use App\Arkon\Seo\PageSeo;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Rules;
use Illuminate\Support\Facades\DB;

/**
 * Arkon's own AI actions, grouped by what they work on. Each delegates to the domain services
 * the admin and the public API use; none writes to the database itself.
 *
 *  - discovery: capabilities, content and terms, media library, SEO analysis (read only);
 *  - creation: a new draft post or page from public blocks (never published, never changes
 *    existing content);
 *  - proposals: page and website changes are proposed, validated and reviewed in Arkon by a
 *    person before anything is applied.
 */
final class CoreActions
{
    public static function registry(): ActionRegistry
    {
        $registry = new ActionRegistry;
        foreach ([...self::discovery(), ...self::creation(), ...self::proposals()] as $action) {
            $registry->register($action);
        }

        return $registry;
    }

    /** Tools without arguments: `properties` must encode as a JSON object. */
    private static function noArguments(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    /** @return list<Action> */
    private static function discovery(): array
    {
        $kinds = array_keys(ContentTypes::all());

        return [
            new Action('arkon_get_capabilities', 'What this Arkon site supports and what you may do on it: content types, taxonomies, the public block format, registered components and nesting, design token slots, allowed actions, recommended workflows and the rules. Read it first.',
                self::noArguments(), fn (SiteContext $ctx) => app(Capabilities::class)->describe($ctx), area: 'site'),
            new Action('arkon_list_content', 'List pages, posts or another content type: id, title, URL path, status (draft, published, trash), draft version and editor link. Filter by status or search the title.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['type'], 'properties' => [
                    'type' => ['type' => 'string', 'enum' => $kinds], 'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'trash', 'any']],
                    'search' => ['type' => 'string', 'maxLength' => 200], 'page' => ['type' => 'integer'],
                ]],
                function (SiteContext $ctx, array $in) {
                    app(Authorizer::class)->authorize($ctx, 'page.view');
                    $reader = app(ContentReader::class);
                    $result = $reader->page($reader->query($ctx->siteId, $in['type'], 'draft', ['status' => $in['status'] ?? 'any', 'search' => $in['search'] ?? null]), 'title', max(1, $in['page'] ?? 1), 50);

                    return ['total' => $result['total'], 'items' => array_map(fn ($r) => [
                        'id' => $r['id'], 'type' => $r['kind'], 'title' => $r['title'], 'path' => $r['path'], 'status' => $r['status'], 'draftVersion' => $r['version'],
                        'hasUnpublishedChanges' => $r['hasChanges'], 'editorUrl' => self::editorUrl($r['id']),
                    ], $result['rows'])];
                }, area: 'content'),
            new Action('arkon_list_terms', 'List the categories, tags or other terms of the site, with how many published items use each. Reuse existing terms before suggesting new ones.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['taxonomy'], 'properties' => ['taxonomy' => ['type' => 'string', 'enum' => array_keys(Taxonomies::all())], 'search' => ['type' => 'string', 'maxLength' => 100]]],
                function (SiteContext $ctx, array $in) {
                    app(Authorizer::class)->authorize($ctx, 'page.view');

                    return ['items' => array_map(fn ($t) => array_intersect_key($t, array_flip(['id', 'name', 'slug', 'parentId', 'count', 'draftCount'])), app(TermService::class)->browse($ctx->siteId, $in['taxonomy'], ['q' => $in['search'] ?? '', 'perPage' => 200])['items'])];
                }, area: 'content'),
            new Action('arkon_search_media', 'Search the media library (title, file name, alt text): image ids, size and current alt text. Reuse suitable images instead of asking for new ones; only these ids may be used in content.',
                ['type' => 'object', 'additionalProperties' => false, 'properties' => ['search' => ['type' => 'string', 'maxLength' => 100], 'page' => ['type' => 'integer']]],
                function (SiteContext $ctx, array $in) {
                    $result = app(MediaLibrary::class)->browse($ctx, $in['search'] ?? '', 'newest', max(1, $in['page'] ?? 1));

                    return ['total' => $result['total'], 'items' => array_map(fn ($a) => ['id' => $a['id'], 'title' => $a['title'], 'alt' => $a['alt'], 'caption' => $a['caption'], 'width' => $a['width'], 'height' => $a['height']], $result['items'])];
                }, permission: 'media.view', area: 'media'),
            new Action('arkon_analyze_seo', "Arkon's deterministic SEO report of a page or post draft (as it would be published): score, label and each check. The score always comes from this analyzer; suggest fixes from its checks.",
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
                function (SiteContext $ctx, array $in) {
                    $report = app(PageSeo::class)->analyzeDraft($ctx, $in['id']);

                    return ['score' => $report['score'], 'label' => $report['label'], 'title' => $report['title'], 'description' => $report['description'],
                        'checks' => array_map(fn ($c) => array_intersect_key($c, array_flip(['id', 'category', 'status', 'message', 'weight', 'earned'])), $report['checks'])];
                }, area: 'seo'),
        ];
    }

    /** @return list<Action> */
    private static function creation(): array
    {
        $block = ['type' => 'object', 'additionalProperties' => false, 'required' => ['type'], 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['heading', 'paragraph', 'image', 'button']], 'text' => ['type' => 'string', 'maxLength' => 5000],
            'level' => ['type' => 'integer'], 'media_id' => ['type' => 'string'], 'alt' => ['type' => 'string', 'maxLength' => 300], 'caption' => ['type' => 'string', 'maxLength' => 300],
            'label' => ['type' => 'string', 'maxLength' => 80], 'href' => ['type' => 'string', 'maxLength' => 2000],
        ]];
        $seo = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['title' => ['type' => 'string', 'maxLength' => 120], 'description' => ['type' => 'string', 'maxLength' => 320], 'focusTopic' => ['type' => 'string', 'maxLength' => 120]]];
        $key = ['type' => 'string', 'pattern' => Rules::get('patterns.requestKey'), 'description' => '16–100 characters [A-Za-z0-9_-], unique per request; resend the same key only to retry the same request'];
        $names = ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 100]];

        return [
            new Action('arkon_create_post', 'Create a blog post as a draft: title, optional slug, excerpt, categories and tags (names: existing ones are reused, new ones created), a featured image id from arkon_search_media, SEO title and description, and the body in the public block format. The post is a normal Arkon post; it is never published by you. Returns its id, editor link and Arkon\'s SEO score.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'blocks', 'requestKey'], 'properties' => [
                    'title' => ['type' => 'string', 'maxLength' => 120], 'slug' => ['type' => 'string', 'maxLength' => 100], 'excerpt' => ['type' => 'string', 'maxLength' => 1000],
                    'categories' => $names, 'tags' => $names, 'featuredMediaId' => ['type' => ['string', 'null']], 'seo' => $seo,
                    'blocks' => ['type' => 'array', 'maxItems' => 400, 'items' => $block], 'requestKey' => $key,
                ]],
                fn (SiteContext $ctx, array $in) => self::createDraft($ctx, 'post', $in), readOnly: false, permission: 'page.create', area: 'content'),
            new Action('arkon_create_page', 'Create a page as a draft at a new URL path, with SEO title and description and content in the public block format. Use it for simple content pages; for designed layouts submit a page proposal afterwards. Never published by you.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'path', 'blocks', 'requestKey'], 'properties' => [
                    'title' => ['type' => 'string', 'maxLength' => 120], 'path' => ['type' => 'string', 'maxLength' => 2000], 'seo' => $seo,
                    'blocks' => ['type' => 'array', 'maxItems' => 400, 'items' => $block], 'requestKey' => $key,
                ]],
                fn (SiteContext $ctx, array $in) => self::createDraft($ctx, 'page', $in), readOnly: false, permission: 'page.create', area: 'content'),
        ];
    }

    /** A new draft through ContentItems, the same path the public API takes; terms by name. */
    private static function createDraft(SiteContext $ctx, string $kind, array $in): array
    {
        $created = app(Transactions::class)->run(function () use ($ctx, $kind, $in) {
            $terms = [];
            foreach (['category' => 'categories', 'tag' => 'tags'] as $taxonomy => $field) {
                if (isset($in[$field]) && ContentTypes::uses($kind, $taxonomy)) {
                    $terms[$taxonomy] = app(TermService::class)->idsForNames($ctx, $taxonomy, $in[$field]);
                }
            }

            return app(ContentItems::class)->create($ctx, $kind, array_filter([
                'title' => $in['title'], 'slug' => $in['slug'] ?? null, 'path' => $in['path'] ?? null, 'excerpt' => $in['excerpt'] ?? null,
                'featuredMediaId' => $in['featuredMediaId'] ?? null, 'terms' => $terms ?: null, 'seo' => $in['seo'] ?? null,
                'blocks' => $in['blocks'], 'requestKey' => $in['requestKey'], 'status' => 'draft',
            ], fn ($v) => $v !== null));
        });
        $row = DB::table('pages')->where('id', $created['id'])->first(['path', 'title']);
        $seo = app(PageSeo::class)->analyzeDraft($ctx, $created['id']);

        return [
            'success' => true, 'replayed' => $created['replayed'],
            'resource' => ['type' => $kind, 'id' => $created['id'], 'title' => $row->title, 'path' => $row->path, 'status' => 'draft'],
            'seo' => ['score' => $seo['score'], 'label' => $seo['label'], 'failing' => array_values(array_map(fn ($c) => $c['message'], array_filter($seo['checks'], fn ($c) => $c['status'] !== 'passed')))],
            'editorUrl' => self::editorUrl($created['id']),
            'next' => 'The draft is in Arkon. Ask the user to review it in the editor and publish it when ready.',
        ];
    }

    /** @return list<Action> The proposal tools (unchanged contracts): nothing changes until a person applies it. */
    private static function proposals(): array
    {
        $page = ['pageId' => ['type' => 'string', 'description' => 'Page or post id from arkon_list_pages or arkon_list_content']];

        return [
            new Action('arkon_get_website_context', 'Read the paired site, capture draft versions, and get the schema and rules for one multi-page website proposal. Context expires after 30 minutes. No page or resource draft is changed.',
                self::noArguments(), fn (SiteContext $ctx) => app(WebsiteProposalService::class)->mcpContext($ctx), readOnly: false, area: 'website'),
            new Action('arkon_submit_website_proposal', 'Submit an editable multi-page website proposal for review at /admin/website. Use the contextId and proposal schema from arkon_get_website_context. Nothing is applied or published.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['contextId', 'prompt', 'requestKey', 'proposal'], 'properties' => ['contextId' => ['type' => 'string'], 'prompt' => ['type' => 'string'], 'requestKey' => ['type' => 'string'], 'proposal' => ['type' => 'object']]],
                fn (SiteContext $ctx, array $in, string $connection) => app(WebsiteProposalService::class)->submit($ctx, $in, $connection), readOnly: false, permission: 'page.edit', area: 'website'),
            new Action('arkon_get_website_proposal_status', 'Read your own multi-page website proposal status; never applies or publishes.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['proposalId'], 'properties' => ['proposalId' => ['type' => 'string']]],
                fn (SiteContext $ctx, array $in) => app(WebsiteProposalService::class)->status($ctx, $in['proposalId']), area: 'website'),
            new Action('arkon_list_pages', 'List the pages of the Arkon site this connection is paired with: id, title, URL path, status and current draft version.',
                self::noArguments(), function (SiteContext $ctx) {
                    $pages = array_map(fn ($p) => ['id' => $p['id'], 'title' => $p['title'], 'path' => $p['path'], 'status' => $p['status'], 'draftVersion' => $p['version'], 'editorUrl' => self::editorUrl($p['id'])], app(PageService::class)->listPages($ctx));

                    return ['site' => DB::table('sites')->where('id', $ctx->siteId)->value('name'), 'pages' => $pages];
                }),
            new Action('arkon_get_page', 'Read one page: its draft version (use it as baseVersion when submitting), its blocks (ids, types, props) and the images already on it.',
                ['type' => 'object', 'properties' => $page, 'required' => ['pageId'], 'additionalProperties' => false], fn (SiteContext $ctx, array $in) => self::getPage($ctx, $in['pageId'])),
            new Action('arkon_get_proposal_format', 'The rules, the component catalogue (registered blocks, props, nesting, design settings and tokens) and the JSON schema a proposal for this page must match. Read it before writing a proposal.',
                ['type' => 'object', 'properties' => $page, 'required' => ['pageId'], 'additionalProperties' => false], fn (SiteContext $ctx, array $in) => self::format($ctx, $in['pageId'])),
            new Action('arkon_submit_proposal', 'Submit a proposal for visual review in Arkon. It is validated (components, props, nesting, links, images) against the draft version given as baseVersion; the draft is not changed. The user applies or discards it in the editor\'s AI tab. Nothing is published.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['pageId', 'baseVersion', 'request', 'requestKey', 'proposal'], 'properties' => [
                    ...$page,
                    'baseVersion' => ['type' => 'integer', 'description' => 'draftVersion from arkon_get_page'],
                    'request' => ['type' => 'string', 'description' => 'What the user asked for, in their words (shown with the proposal)'],
                    'requestKey' => ['type' => 'string', 'description' => '16–100 characters [A-Za-z0-9_-], unique per submission; resend the same key only to retry the same submission'],
                    'proposal' => ['type' => 'object', 'description' => 'The proposal: {summary, notes, tokenChanges, changes} matching the schema from arkon_get_proposal_format'],
                ]],
                function (SiteContext $ctx, array $in, string $connection) {
                    $view = app(ProposalService::class)->submit($ctx, $in['pageId'], ['prompt' => $in['request'], 'baseVersion' => $in['baseVersion'], 'requestKey' => $in['requestKey'], 'proposal' => $in['proposal']], $connection);

                    return [...self::summary($view), 'next' => 'The proposal is waiting in Arkon. Ask the user to open the page in the editor, AI tab, to preview it and apply or discard it.'];
                }, readOnly: false, permission: 'page.edit'),
            new Action('arkon_get_proposal_status', 'Whether a submitted proposal is still waiting for review, applied or discarded.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['pageId', 'proposalId'], 'properties' => [...$page, 'proposalId' => ['type' => 'string']]],
                fn (SiteContext $ctx, array $in) => self::summary(app(ProposalService::class)->status($ctx, $in['pageId'], $in['proposalId']))),
        ];
    }

    private static function getPage(SiteContext $ctx, string $pageId): array
    {
        $pageId = Input::id($pageId);

        return app(Transactions::class)->run(function () use ($ctx, $pageId) {
            app(Authorizer::class)->authorize($ctx, 'page.view');
            $prompts = app(ProposalPrompt::class);
            $context = $prompts->context($ctx, $pageId);

            return [
                'page' => ['id' => $context['page']->id, 'title' => $context['page']->title, 'path' => $context['page']->path],
                'draftVersion' => $context['version'],
                'imagesOnPage' => array_map(fn ($id, $a) => ['assetId' => $id, ...$a], array_keys($context['assets']), $context['assets']),
                'blocks' => $prompts->blocks($context['doc']),
                // Published shared resources only (instances render published versions; drafts are never shown).
                'reusableComponents' => array_map(fn ($id, $c) => ['componentId' => $id, ...$c], array_keys($context['components']), $context['components']),
                'designTokens' => $context['tokens'],
                'publishedMenus' => $context['menus'],
                'publishedForms' => $context['forms'],
                'editorUrl' => self::editorUrl($pageId),
            ];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    private static function format(SiteContext $ctx, string $pageId): array
    {
        $pageId = Input::id($pageId);

        return app(Transactions::class)->run(function () use ($ctx, $pageId) {
            app(Authorizer::class)->authorize($ctx, 'page.view');
            $prompts = app(ProposalPrompt::class);
            $context = $prompts->context($ctx, $pageId);

            return ['instructions' => $prompts->instructions($context), 'schema' => $prompts->schema($context), 'draftVersion' => $context['version']];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    private static function summary(array $view): array
    {
        return [
            'proposalId' => $view['id'], 'status' => $view['status'], 'request' => $view['prompt'], 'baseVersion' => $view['baseVersion'],
            'changes' => $view['proposal']['changes'] ?? [], 'warnings' => $view['proposal']['warnings'] ?? [], 'notes' => $view['proposal']['notes'] ?? [],
            'error' => $view['error'], 'reviewUrl' => self::editorUrl($view['pageId']),
        ];
    }

    public static function editorUrl(string $pageId): string
    {
        return rtrim((string) config('app.url'), '/')."/admin/editor/{$pageId}";
    }
}
