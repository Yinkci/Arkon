<?php

namespace App\Http\Controllers\Api\V1;

use App\Arkon\Forms\FormEntries;
use App\Arkon\Forms\FormManagement;
use App\Arkon\Forms\FormSubmissions;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Time;
use App\Arkon\Support\Uuid;
use App\Http\Api\ApiError;
use App\Http\Api\ApiPrincipal;
use App\Http\Api\ApiResponse;
use App\Http\Api\Pagination;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/forms. A form is public, like on the website, once a live page uses it: its definition
 * can be read and it accepts submissions through FormSubmissions, the same rules as the HTML
 * endpoint (validation, encrypted storage, deduplication, rate limits, notifications). Drafts
 * need read:forms; entries are personal data and need read:form_entries.
 */
final class FormsController extends Controller
{
    public function __construct(private readonly FormSubmissions $submissions) {}

    public function index(Request $request, FormManagement $forms): JsonResponse
    {
        $ctx = ApiPrincipal::of($request)->require('read:forms');
        $v = ApiResponse::query($request, ['search' => ['sometimes', 'string', 'max:100'], 'status' => ['sometimes', 'in:'.implode(',', FormManagement::STATUSES)], 'page' => ['sometimes'], 'per_page' => ['sometimes', 'in:20']]);
        $page = Pagination::from($request, 20, 20);
        $result = $forms->browse($ctx, $v['search'] ?? '', $page->page, $v['status'] ?? 'all');
        $items = array_map(fn ($f) => [
            'id' => $f['id'], 'name' => $f['definition']['name'], 'status' => strtolower(str_replace('In ', '', $f['status'])),
            'version' => $f['version'], 'published_version' => $f['publishedVersion'] === null ? null : (int) $f['publishedVersion'],
            'live_version' => $this->submissions->liveVersion($ctx->siteId, $f['id']), 'has_unpublished_changes' => $f['hasDraftChanges'],
            'entries' => $f['entries'], 'updated_at' => Time::iso($f['updatedAt']),
        ], $result['items']);

        return ApiResponse::collection($request, $items, new Pagination($result['page'], 20), $result['total']);
    }

    public function show(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $principal = ApiPrincipal::of($request);
        [$version, $definition] = $this->live($principal->siteId, $id);

        return ApiResponse::item($request, self::present($id, $version, $definition));
    }

    /** {"fields": {"email": "…"}} with an optional Idempotency-Key header. Anonymous, rate limited. */
    public function submit(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $principal = ApiPrincipal::of($request);
        [$version] = $this->live($principal->siteId, $id);
        $form = $this->submissions->version($principal->siteId, $id, $version) ?? throw ApiError::notFound('Form');
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ! Rules::matches('requestKey', $key)) {
            throw ApiError::invalidParameter('Idempotency-Key must be 16 to 100 characters: letters, digits, - and _.', ['Idempotency-Key' => ['Invalid format.']]);
        }
        $result = $this->submissions->submit($principal->siteId, $id, $version, $form, $request->json('fields'), $key, (string) $request->ip());

        return ApiResponse::item($request, ['form' => $id, 'form_version' => $version, 'received' => true, 'replayed' => $result['replayed'], 'confirmation' => $result['confirmation']], $result['replayed'] ? 200 : 201);
    }

    public function entries(Request $request, FormEntries $entries): JsonResponse
    {
        $id = (string) $request->route('id');
        $ctx = ApiPrincipal::of($request)->require('read:form_entries');
        $v = ApiResponse::query($request, ['status' => ['sometimes', 'in:inbox,unread,starred,spam,trash'], 'search' => ['sometimes', 'string', 'max:200'], 'after' => ['sometimes', 'date'], 'before' => ['sometimes', 'date'], 'sort' => ['sometimes', 'in:newest,oldest'], 'page' => ['sometimes'], 'per_page' => ['sometimes']]);
        $page = Pagination::from($request, 25, 25);
        $result = $entries->browse($ctx, $id, ['filter' => $v['status'] ?? 'inbox', 'q' => $v['search'] ?? '', 'from' => $v['after'] ?? null, 'to' => $v['before'] ?? null, 'sort' => $v['sort'] ?? 'newest', 'page' => $page->page]);

        return ApiResponse::collection($request, array_map(self::presentEntry(...), $result['items']), new Pagination($result['page'], 25), $result['total']);
    }

    public function entry(Request $request, FormEntries $entries): JsonResponse
    {
        [$id, $entry] = [(string) $request->route('id'), (string) $request->route('entry')];
        $ctx = ApiPrincipal::of($request)->require('read:form_entries');

        return ApiResponse::item($request, self::presentEntry($entries->detail($ctx, $id, $entry)));
    }

    /** @return array{0: int, 1: array} the live version and its definition, or 404 */
    private function live(string $siteId, string $id): array
    {
        $version = Uuid::isValid($id) ? $this->submissions->liveVersion($siteId, $id) : null;
        $form = $version === null ? null : $this->submissions->version($siteId, $id, $version);

        return $form === null ? throw ApiError::notFound('Form') : [$version, $form['definition']];
    }

    /** The public definition: what a client needs to render the form. Never notifications or recipients. */
    private static function present(string $id, int $version, array $d): array
    {
        $modern = ($d['schemaVersion'] ?? 1) === 2;

        return [
            'id' => $id, 'version' => $version, 'name' => $d['name'], 'description' => $modern ? (string) ($d['description'] ?? '') : '',
            'submit_label' => $d['submitLabel'], 'accepting_submissions' => ! $modern || ($d['active'] ?? true),
            'fields' => array_map(fn ($f) => [
                'id' => $f['id'], 'type' => $f['type'], 'label' => $f['label'], 'required' => (bool) $f['required'],
                'description' => (string) ($f['description'] ?? ''), 'placeholder' => (string) ($f['placeholder'] ?? ''),
                'default_value' => $f['defaultValue'] ?? null,
                'choices' => $modern ? array_map(fn ($c) => ['label' => $c['label'], 'value' => $c['value']], $f['choices'] ?? []) : array_map(fn ($o) => ['label' => $o, 'value' => $o], $f['options'] ?? []),
                ...($modern ? ['min' => $f['min'] ?? null, 'max' => $f['max'] ?? null, 'step' => $f['step'] ?? null, 'row' => $f['row'], 'width' => (int) $f['width'], 'condition' => $f['condition'] ?? null] : []),
            ], $d['fields']),
            'submit_url' => "/api/v1/forms/{$id}/submissions",
        ];
    }

    private static function presentEntry(array $e): array
    {
        return ['id' => $e['id'], 'created_at' => Time::iso($e['createdAt']), 'status' => $e['status'], 'read' => $e['read'], 'starred' => $e['starred'], 'form_version' => $e['formVersion'], 'fields' => array_map(fn ($f) => ['id' => $f['id'], 'label' => $f['label'], 'type' => $f['type'], 'value' => $f['value']], $e['fields'])];
    }
}
