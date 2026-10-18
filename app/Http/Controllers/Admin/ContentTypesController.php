<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldSchemaResolver;
use App\Arkon\Content\FieldTypes;
use App\Arkon\Content\Relationships;
use App\Arkon\Content\Taxonomies;
use App\Arkon\Sites\Authorizer;
use App\Http\AdminContext;
use App\Http\Controllers\Api\EditorApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screens and JSON endpoints for a site's content types, and for the field values of their
 * entries. HTTP only: ContentTypeService and EntryFields hold the rules, so the API and AI
 * reach the same behaviour through the same services.
 */
final class ContentTypesController extends Controller
{
    public function index(Request $request, ContentTypeService $types): Response
    {
        $ctx = AdminContext::of($request)->ctx();

        return Inertia::render('Admin/ContentTypes', [
            'types' => $types->browse($ctx),
            'canManage' => AdminContext::of($request)->can('contentType.manage'),
        ]);
    }

    /** The definition editor, for a new type or an existing one. */
    public function edit(Request $request, ContentTypeService $types, FieldSchemaResolver $schema, Authorizer $auth): Response
    {
        $ctx = AdminContext::of($request)->ctx();
        $key = (string) $request->route('key');
        // Defining a type is schema management, whether the type exists yet or not.
        $auth->authorize($ctx, 'contentType.manage');

        return Inertia::render('Admin/ContentTypeEditor', [
            'type' => $key === 'new' ? null : $types->show($ctx, $key),
            // The groups attached to this type, so the editor shows everything its entries carry
            // and not only the fields declared here. Attaching is done from the group itself.
            'groups' => $key === 'new' ? [] : array_map(
                fn ($group) => ['key' => $group['key'], 'name' => $group['name'], 'fields' => count($group['fields'])],
                $schema->groupsFor($ctx->siteId, ['kind' => $key]),
            ),
            'fieldTypes' => self::fieldTypes(),
            'contentTypes' => self::contentTypes($types->browse($ctx)),
            'taxonomies' => array_map(
                fn ($t, $name) => ['name' => $name, 'label' => $t['label'], 'plural' => $t['plural']],
                Taxonomies::all(),
                array_keys(Taxonomies::all()),
            ),
        ]);
    }

    public function store(Request $request, ContentTypeService $types): JsonResponse
    {
        return EditorApiController::ok($types->create(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function update(Request $request, ContentTypeService $types): JsonResponse
    {
        return EditorApiController::ok($types->update(AdminContext::of($request)->ctx(), (string) $request->route('key'), EditorApiController::body($request)));
    }

    public function setActive(Request $request, ContentTypeService $types): JsonResponse
    {
        $body = EditorApiController::body($request);

        return EditorApiController::ok($types->setActive(AdminContext::of($request)->ctx(), (string) $request->route('key'), (bool) ($body['active'] ?? false)));
    }

    public function destroy(Request $request, ContentTypeService $types): JsonResponse
    {
        $types->delete(AdminContext::of($request)->ctx(), (string) $request->route('key'));

        return EditorApiController::ok(['deleted' => true]);
    }

    /** An entry's own field values, saved from the entry editor. */
    public function saveFields(Request $request, EntryFields $fields): JsonResponse
    {
        $body = EditorApiController::body($request);

        // `expected`: what the editor last read for the fields it changes, so a save never
        // overwrites a change somebody else made in the meantime.
        $expected = $body['expected'] ?? null;

        return EditorApiController::ok([
            'fields' => $fields->update(AdminContext::of($request)->ctx(), (string) $request->route('page'), (array) ($body['fields'] ?? []), $expected === null ? null : (array) $expected),
        ]);
    }

    /**
     * One change to one content-sections field of an entry: the editor and AI both send a single
     * operation rather than the whole value, so what happened stays legible.
     */
    public function saveSection(Request $request, EntryFields $fields): JsonResponse
    {
        $body = EditorApiController::body($request);

        return EditorApiController::ok([
            'fields' => $fields->section(AdminContext::of($request)->ctx(), (string) $request->route('page'), $body),
        ]);
    }

    /**
     * Entries a relationship field can point at, searched in the database: the picker sends what
     * somebody typed and gets back a page of matches, so a site with thousands of entries costs
     * the same as a site with ten.
     */
    public function related(Request $request, Relationships $relationships, Authorizer $auth): JsonResponse
    {
        $admin = AdminContext::of($request);
        $auth->authorize($admin->ctx(), 'page.view');
        // Asked by id: the chips an entry already holds, so each can show what it points at and
        // say when that entry is in the Trash.
        if ($request->query('ids') !== null) {
            $ids = array_slice(array_filter(explode(',', (string) $request->query('ids'))), 0, FieldTypes::limit('related'));

            return EditorApiController::ok(['items' => array_values($relationships->summaries($admin->ctx()->siteId, $ids))]);
        }
        $kind = (string) $request->query('type', '');
        if (! preg_match('/'.FieldTypes::pattern('typeKey').'/D', $kind)) {
            return EditorApiController::ok(['items' => []]);
        }
        $except = array_filter(explode(',', (string) $request->query('except', '')));

        return EditorApiController::ok([
            'items' => $relationships->search($admin->ctx()->siteId, $kind, (string) $request->query('q', ''), 20, $except),
        ]);
    }

    /** What refers to these entries, for a confirmation before they are trashed or deleted. */
    public function usage(Request $request, Relationships $relationships, Authorizer $auth): JsonResponse
    {
        $admin = AdminContext::of($request);
        $auth->authorize($admin->ctx(), 'page.view');
        $uses = $relationships->usedByAny($admin->ctx()->siteId, array_values(array_filter((array) $request->input('ids', []), 'is_string')));

        return EditorApiController::ok(['uses' => $uses, 'sentences' => Relationships::describeUses($uses)]);
    }

    /**
     * The kinds of content a relationship field can point at, for the drawer's target picker.
     * Everything a site has, built-in types included: a property may relate to a page.
     */
    public static function contentTypes(array $browsed): array
    {
        return array_map(fn ($type) => ['key' => $type['key'], 'label' => $type['label'], 'plural' => $type['plural']], $browsed);
    }

    /** The field types either editor offers, from the shared registry both sides read. */
    public static function fieldTypes(): array
    {
        $out = [];
        foreach (FieldTypes::registry()['types'] as $key => $type) {
            $out[] = [
                'key' => $key,
                'label' => $type['label'],
                'group' => $type['group'],
                'choices' => (bool) ($type['choices'] ?? false),
                'children' => (bool) ($type['children'] ?? false),
                'layouts' => (bool) ($type['layouts'] ?? false),
                'settings' => $type['settings'],
            ];
        }

        return $out;
    }
}
