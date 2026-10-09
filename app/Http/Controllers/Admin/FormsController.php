<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Forms\FormService;
use App\Arkon\Sites\Permissions;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class FormsController extends Controller
{
    public function index(Request $r, FormService $forms)
    {
        $a = AdminContext::of($r);

        return Inertia::render('Admin/Forms', ['forms' => $forms->list($a->ctx()), 'submissions' => Permissions::allows($a->role, 'page.publish') ? $forms->submissions($a->ctx()) : [], 'permissions' => ['edit' => Permissions::allows($a->role, 'page.edit'), 'publish' => Permissions::allows($a->role, 'page.publish')]]);
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
}
