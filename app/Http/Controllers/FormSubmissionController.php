<?php

namespace App\Http\Controllers;

use App\Arkon\Components\Render\FormV1;
use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\Serializer;
use App\Arkon\Sites\Membership;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class FormSubmissionController extends Controller
{
    public function __invoke(Request $request, Membership $membership, string $form, int $version)
    {
        $site = $membership->siteForHost($request->getHttpHost());
        if (! $site || ! Uuid::isValid($form)) {
            abort(404);
        }
        $row = DB::table('site_form_versions as v')->join('site_forms as f', 'f.id', '=', 'v.form_id')->where('v.site_id', $site)->where('v.form_id', $form)->where('v.version', $version)->first(['v.definition', 'f.notification_email']);
        $used = DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.site_id', $site)->whereRaw("p.render_inputs->'forms'->?->>'version' = ?", [$form, (string) $version])->exists();
        if (! $row || ! $used) {
            abort(404);
        }
        if (($request->header('Origin') !== null && $request->header('Origin') !== $request->getSchemeAndHttpHost()) || $request->header('Sec-Fetch-Site') === 'cross-site') {
            abort(403);
        }
        $def = Json::decode($row->definition);
        if ($request->input('website')) {
            return self::page($def['successMessage']);
        }
        $key = 'arkon-form:'.hash('sha256', $site.$form.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return self::page('Please wait a minute before sending another enquiry.', 429);
        }
        RateLimiter::hit($key, 60);
        $siteKey = 'arkon-form-site:'.$site;
        if (RateLimiter::tooManyAttempts($siteKey, 100)) {
            return self::page('Please try again later.', 429);
        }
        RateLimiter::hit($siteKey, 60);
        if ((int) $request->server('CONTENT_LENGTH', 0) > 32768) {
            abort(413);
        }
        $rules = [];
        foreach ($def['fields'] as $f) {
            $rules[$f['id']] = [$f['required'] ? 'required' : 'nullable', 'string', 'max:2000'];
            if ($f['type'] === 'email') {
                $rules[$f['id']][] = 'email';
            }if ($f['type'] === 'select') {
                $rules[$f['id']][] = Rule::in($f['options']);
            }if ($f['type'] === 'checkbox') {
                $rules[$f['id']][] = Rule::in(['yes']);
            }
        }
        $fields = $request->input('fields', []);
        if (! is_array($fields) || strlen($request->getContent()) > 32768) {
            abort(422);
        }
        $validator = Validator::make($fields, $rules);
        if ($validator->fails()) {
            $errors = Element::h('ul', [], array_map(fn ($m) => Element::h('li', [], $m), $validator->errors()->all()));

            return self::page('Please correct your enquiry.', 422, [$errors, FormV1::element($form, $version, $def, 'retry')]);
        }
        $values = $validator->validated();
        $id = Uuid::v7();
        DB::table('form_submissions')->insert(['id' => $id, 'site_id' => $site, 'form_id' => $form, 'form_version' => $version, 'payload' => Crypt::encryptString(Json::encode($values)), 'notification_status' => $row->notification_email ? (in_array(config('mail.default'), ['log', 'array'], true) ? 'unconfigured' : 'pending') : 'disabled']);
        if ($row->notification_email && ! in_array(config('mail.default'), ['log', 'array'], true)) {
            try {
                Mail::raw('New enquiry from '.$def['name']."\n\n".implode("\n", array_map(fn ($k, $v) => $k.': '.$v, array_keys($values), array_values($values))), fn ($m) => $m->to($row->notification_email)->subject('New website enquiry'));
                $status = 'sent';
            } catch (\Throwable) {
                $status = 'failed';
            }
            DB::table('form_submissions')->where('id', $id)->update(['notification_status' => $status]);
        }

        return self::page($def['successMessage']);
    }

    private static function page(string $message, int $status = 200, array $extra = [])
    {
        $body = Serializer::serialize(Element::h('main', [], Element::h('h1', [], $message), $extra, Element::h('a', ['href' => '/'], 'Back to website')));

        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Website enquiry</title><style>body{font:1rem system-ui;margin:2rem;max-width:50rem}input,textarea,select,button{font:inherit;max-width:100%}label{display:block;margin-top:1rem}.ak-form__trap{position:absolute;left:-10000px}</style></head><body>'.$body.'</body></html>', $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", 'X-Content-Type-Options' => 'nosniff']);
    }
}
