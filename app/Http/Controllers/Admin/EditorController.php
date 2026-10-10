<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\ProposalService;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Content\ContentDetails;
use App\Arkon\Content\ContentTypes;
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
    public function show(Request $request, string $page, PageService $pages, MediaSigner $signer, ComponentRegistry $registry, ProposalService $proposals, ContentDetails $details, TermService $terms): Response
    {
        $admin = AdminContext::of($request);
        // Page, draft, live state, history and the first canvas paint, all from one snapshot.
        $init = $pages->editorInit($admin->ctx(), $page, $signer);
        $kind = (string) DB::table('pages')->where('site_id', $admin->ctx()->siteId)->where('id', $init['page']['id'])->value('kind');
        $type = ContentTypes::get($kind);

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
                    'listUrl' => $kind === 'page' ? '/admin/pages' : '/admin/'.$type['collection'],
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
