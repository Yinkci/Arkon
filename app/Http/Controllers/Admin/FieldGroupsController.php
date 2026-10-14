<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\LocationRules;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Http\AdminContext;
use App\Http\Controllers\Api\EditorApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screens and endpoints for field groups. HTTP only: FieldGroupService holds the rules, so
 * the API and AI reach the same behaviour through the same service.
 */
final class FieldGroupsController extends Controller
{
    public function index(Request $request, FieldGroupService $groups): Response
    {
        $admin = AdminContext::of($request);

        return Inertia::render('Admin/FieldGroups', [
            'groups' => $groups->browse($admin->ctx()),
            'canManage' => $admin->can('contentType.manage'),
        ]);
    }

    /** The group editor, for a new group or an existing one. */
    public function edit(Request $request, FieldGroupService $groups, ContentTypeService $types): Response
    {
        $ctx = AdminContext::of($request)->ctx();
        $key = (string) $request->route('key');

        return Inertia::render('Admin/FieldGroupEditor', [
            'group' => $key === 'new' ? null : $groups->show($ctx, $key),
            'fieldTypes' => ContentTypesController::fieldTypes(),
            'contentTypes' => ContentTypesController::contentTypes($types->browse($ctx)),
            // What a rule may ask about, and the values it can be given. One list, built from the
            // same registry the rule engine validates against.
            'attributes' => array_values(array_map(
                fn ($attribute, $name) => [
                    'key' => $name,
                    'label' => $attribute['label'],
                    'operators' => array_map(fn ($op) => ['key' => $op, 'label' => LocationRules::OPERATORS[$op]], $attribute['operators']),
                    'values' => $this->valuesFor($ctx, $attribute['targets'], $types),
                ],
                LocationRules::ATTRIBUTES,
                array_keys(LocationRules::ATTRIBUTES),
            )),
        ]);
    }

    /**
     * The values a rule about this attribute can be given. Entries and terms are capped: a rule
     * builder is a chooser, not a browser, and a site with thousands of entries should not send
     * them all to design one rule.
     *
     * @return list<array{value: string, label: string}>
     */
    private function valuesFor(SiteContext $ctx, string $targets, ContentTypeService $types): array
    {
        return match ($targets) {
            'content_types' => array_values(array_map(
                fn ($type) => ['value' => $type['key'], 'label' => $type['plural']],
                array_filter($types->browse($ctx), fn ($type) => $type['defined'] || in_array($type['key'], ['page', 'post'], true)),
            )),
            'entries' => DB::table('pages')->where('site_id', $ctx->siteId)->whereNull('deleted_at')->whereNull('purged_at')
                ->orderBy('title')->limit(200)->get(['id', 'title'])
                ->map(fn ($page) => ['value' => $page->id, 'label' => $page->title])->all(),
            'terms' => DB::table('terms')->where('site_id', $ctx->siteId)->orderBy('name')->limit(200)->get(['id', 'name', 'taxonomy'])
                ->map(fn ($term) => ['value' => $term->id, 'label' => $term->name])->all(),
            'roles' => array_map(fn ($role) => ['value' => $role, 'label' => ucfirst($role)], Permissions::ROLES),
            'scopes' => array_map(fn ($label, $value) => ['value' => $value, 'label' => $label], LocationRules::SCOPES, array_keys(LocationRules::SCOPES)),
            default => [],
        };
    }

    public function store(Request $request, FieldGroupService $groups): JsonResponse
    {
        return EditorApiController::ok($groups->create(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function update(Request $request, FieldGroupService $groups): JsonResponse
    {
        return EditorApiController::ok($groups->update(AdminContext::of($request)->ctx(), (string) $request->route('key'), EditorApiController::body($request)));
    }

    public function destroy(Request $request, FieldGroupService $groups): JsonResponse
    {
        $body = EditorApiController::body($request);
        $groups->delete(AdminContext::of($request)->ctx(), (string) $request->route('key'), (bool) ($body['confirmed'] ?? false));

        return EditorApiController::ok(['deleted' => true]);
    }
}
