<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Components\Render\FormV4;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormDefinition;
use App\Arkon\Forms\FormEntries;
use App\Arkon\Forms\FormManagement;
use App\Arkon\Forms\FormRuntime;
use App\Arkon\Forms\FormService;
use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\Serializer;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class FormsController extends Controller
{
    private function permissions($a): array
    {
        return ['edit' => $a->can('form.edit'), 'publish' => $a->can('form.publish'), 'manage' => $a->can('form.manage'), 'notifications' => $a->can('form.notifications'), 'entries' => $a->can('form.entries.view'), 'export' => $a->can('form.entries.export')];
    }

    public function index(Request $r, FormManagement $forms)
    {
        $v = $r->validate(['q' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1|max:100000']);
        $a = AdminContext::of($r);

        return Inertia::render('Admin/Forms', ['library' => $forms->browse($a->ctx(), $v['q'] ?? '', (int) ($v['page'] ?? 1)), 'permissions' => $this->permissions($a)]);
    }

    public function show(Request $r, FormManagement $forms, string $form)
    {
        $a = AdminContext::of($r);

        return Inertia::render('Admin/FormEditor', ['form' => $forms->detail($a->ctx(), $form), 'permissions' => $this->permissions($a), 'initialSection' => $r->query('section', 'Build'), 'sender' => $a->can('form.notifications') ? config('mail.from.address') : null, 'mailConfigured' => ! in_array(config('mail.default'), ['log', 'array'], true)]);
    }

    public function save(Request $r, FormService $forms)
    {
        return response()->json(['ok' => true, 'data' => $forms->save(AdminContext::of($r)->ctx(), $r->all())]);
    }

    public function publish(Request $r, FormService $forms, string $form)
    {
        return response()->json(['ok' => true, 'data' => $forms->publish(AdminContext::of($r)->ctx(), $form, $r->all())]);
    }

    public function notifications(Request $r, FormService $forms, string $form)
    {
        $forms->notifications(AdminContext::of($r)->ctx(), $form, $r->input('email'));

        return response()->json(['ok' => true, 'data' => []]);
    }

    public function duplicate(Request $r, FormManagement $forms, string $form)
    {
        $v = $r->validate(['requestKey' => 'required|string|max:80']);

        return response()->json(['ok' => true, 'data' => $forms->duplicate(AdminContext::of($r)->ctx(), $form, $v['requestKey'])]);
    }

    public function archive(Request $r, FormManagement $forms, string $form)
    {
        $v = $r->validate(['version' => 'required|integer|min:1']);
        $forms->archive(AdminContext::of($r)->ctx(), $form, (int) $v['version']);

        return response()->json(['ok' => true, 'data' => []]);
    }

    private function filters(Request $r): array
    {
        return $r->validate(['q' => 'nullable|string|max:254', 'page' => 'nullable|integer|min:1|max:100000', 'filter' => 'nullable|in:inbox,unread,starred,spam,trash', 'sort' => 'nullable|in:newest,oldest', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'fields' => 'nullable|array|max:100', 'fields.*' => 'string|max:32']);
    }

    public function entries(Request $r, FormEntries $entries, string $form)
    {
        return response()->json(['ok' => true, 'data' => $entries->browse(AdminContext::of($r)->ctx(), $form, $this->filters($r))]);
    }

    public function entry(Request $r, FormEntries $entries, string $form, string $entry)
    {
        return response()->json(['ok' => true, 'data' => $entries->detail(AdminContext::of($r)->ctx(), $form, $entry)]);
    }

    public function changeEntries(Request $r, FormEntries $entries, string $form)
    {
        $entries->change(AdminContext::of($r)->ctx(), $form, $r->all());

        return response()->json(['ok' => true, 'data' => []]);
    }

    public function export(Request $r, FormEntries $entries, string $form)
    {
        $v = $this->filters($r);

        return $entries->export(AdminContext::of($r)->ctx(), $form, $v, $v['fields'] ?? []);
    }

    public function preview(Request $r, FormManagement $forms, string $form)
    {
        $f = $forms->detail(AdminContext::of($r)->ctx(), $form);
        $tree = FormV4::element($form, 0, $f['definition'], 'preview');
        $tree->attrs['action'] = '/admin/forms/'.$form.'/preview';
        $tree = Element::h('form', $tree->attrs, Element::h('input', ['type' => 'hidden', 'name' => '_token', 'value' => csrf_token()]), $tree->children);

        return response('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Form preview</title><style>body{font:1rem system-ui;margin:2rem}'.file_get_contents(resource_path('arkon/components/form/v4.css')).'</style>'.FormRuntime::tag(2).'</head><body><p>Draft preview. Test submissions are validated but never stored or emailed.</p>'.Serializer::serialize($tree).'</body></html>', 200, ['Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'"]);
    }

    public function previewSubmit(Request $r, FormManagement $forms, string $form)
    {
        $f = $forms->detail(AdminContext::of($r)->ctx(), $form);
        try {
            $values = $r->input('fields', []);
            if (! is_array($values)) {
                throw new ValidationException('Provide valid form fields.');
            }FormDefinition::submission($f['definition'], $values);

            return response()->json(['ok' => true, 'message' => 'Preview validation passed. No entry or email was created.']);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
