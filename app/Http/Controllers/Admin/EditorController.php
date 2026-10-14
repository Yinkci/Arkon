<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\ProposalService;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Content\BindingContext;
use App\Arkon\Content\BindingSources;
use App\Arkon\Content\Collections;
use App\Arkon\Content\ContentDetails;
use App\Arkon\Content\ContentTypeRegistry;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldSchemaResolver;
use App\Arkon\Content\Flexible;
use App\Arkon\Content\Taxonomies;
use App\Arkon\Content\TermService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EditorController extends Controller
{
    public function show(Request $request, string $page, PageService $pages, MediaSigner $signer, ComponentRegistry $registry, ProposalService $proposals, ContentDetails $details, TermService $terms, ContentTypeRegistry $types, EntryFields $entryFields, FieldSchemaResolver $schema, BindingContext $bindings, Collections $collections): Response
    {
        $admin = AdminContext::of($request);
        // Page, draft, live state, history and the first canvas paint, all from one snapshot.
        $init = $pages->editorInit($admin->ctx(), $page, $signer);
        $kind = (string) DB::table('pages')->where('site_id', $admin->ctx()->siteId)->where('id', $init['page']['id'])->value('kind');
        $type = $types->forSite($admin->ctx()->siteId)->get($kind);

        return Inertia::render('Admin/Editor', [
            'init' => [
                ...$init,
                'site' => ['name' => $admin->siteName],
                'multiline' => $registry->multilineFields(),
                // Whether the AI panel can be used here; never any provider credentials.
                'ai' => $proposals->editorInfo($admin->ctx(), $init['permissions']['edit']),
                // What kind of item this is, and its draft details (posts: excerpt, featured image, terms).
                'content' => [
                    'kind' => $kind, 'label' => $type['label'], 'plural' => $type['plural'], 'details' => $type['details'],
                    'listUrl' => match (true) {
                        $kind === 'page' => '/admin/pages',
                        $types->forSite($admin->ctx()->siteId)->isDefined($kind) => '/admin/content/'.$kind,
                        default => '/admin/'.$type['collection'],
                    },
                    // Everything this entry carries: the type's own fields, then each attached
                    // field group as its own section. The values themselves stay flat.
                    'sections' => $schema->sectionsFor($admin->ctx()->siteId, $schema->contextForEntry($admin->ctx()->siteId, $init['page']['id'], $admin->role)),
                    // The entry's content-sections fields, so a layout template knows which kind
                    // of section it draws and what that section holds.
                    'flexibleFields' => Flexible::forBuilder($schema->fieldsFor($admin->ctx()->siteId, $schema->contextForEntry($admin->ctx()->siteId, $init['page']['id'], $admin->role))),
                    // Sections of a kind that was removed from the type: kept, and said so.
                    'deprecatedSections' => Flexible::describeDeprecated(Flexible::deprecatedIn(
                        $schema->fieldsFor($admin->ctx()->siteId, $schema->contextForEntry($admin->ctx()->siteId, $init['page']['id'], $admin->role)),
                        $entryFields->drafts($admin->ctx()->siteId, [$init['page']['id']])[$init['page']['id']] ?? [],
                    )),
                    'fieldValues' => (object) ($entryFields->drafts($admin->ctx()->siteId, [$init['page']['id']])[$init['page']['id']] ?? []),
                    // What a content list can show, and which of each kind's fields can be
                    // filtered or sorted on: the same schema the query is checked against.
                    'collectionSources' => $collections->sources($admin->ctx()->siteId, $init['page']['id']),
                    // What a block's property can be bound to here, and what each one can feed.
                    'bindingSources' => BindingSources::all(
                        $bindings->entryFields($admin->ctx()->siteId, $init['page']['id']),
                        $bindings->siteFields($admin->ctx()->siteId),
                        $type['label'],
                    ),
                    'values' => $details->drafts($admin->ctx()->siteId, [$init['page']['id']])[$init['page']['id']],
                    'taxonomies' => array_map(fn ($t) => [
                        'name' => $t, 'label' => Taxonomies::get($t)['label'], 'plural' => Taxonomies::get($t)['plural'],
                        'terms' => array_map(fn ($term) => ['id' => $term['id'], 'name' => $term['name']], $terms->browse($admin->ctx()->siteId, $t, ['perPage' => 500])['items']),
                    ], $type['taxonomies']),
                ],
            ],
        ]);
    }
}
