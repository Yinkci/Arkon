<?php

namespace App\Http\Controllers;

use App\Arkon\Components\Render\FormV4;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\EntryIndex;
use App\Arkon\Forms\FormDefinition;
use App\Arkon\Forms\FormNotifications;
use App\Arkon\Forms\FormRuntime;
use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\Serializer;
use App\Arkon\Sites\Membership;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
        $row = DB::table('site_form_versions as v')->join('site_forms as f', fn ($j) => $j->on('f.id', '=', 'v.form_id')->on('f.site_id', '=', 'v.site_id'))->where('v.site_id', $site)->where('v.form_id', $form)->where('v.version', $version)->first(['v.definition', 'f.notification_email', 'f.archived_at']);
        if (! $row || $row->archived_at) {
            abort(404);
        }
        if (($request->header('Origin') !== null && $request->header('Origin') !== $request->getSchemeAndHttpHost()) || $request->header('Sec-Fetch-Site') === 'cross-site') {
            abort(403);
        }
        if ((int) $request->server('CONTENT_LENGTH', 0) > 32768 || strlen($request->getContent()) > 32768) {
            return self::answer($request, 'This submission is too large.', 413);
        }
        $def = Json::decode($row->definition);
        $modern = ($def['schemaVersion'] ?? 1) === 2;
        if ($modern && $request->header('Origin') === null && ! in_array($request->header('Sec-Fetch-Site'), ['same-origin', 'same-site'], true)) {
            abort(403);
        }
        if ($modern && ! $def['active']) {
            return self::answer($request, 'This form is not accepting submissions.', 410);
        }
        if ($request->input('website')) {
            return self::answer($request, $def['successMessage']);
        }
        $raw = $request->input('fields', []);
        if (! is_array($raw)) {
            return self::answer($request, 'Provide valid form fields.', 422);
        }
        try {
            if ($modern) {
                if (array_diff(array_keys($raw), array_column($def['fields'], 'id'))) {
                    throw new ValidationException('The submission contains an unknown field.');
                }$values = FormDefinition::submission($def, $raw);
            } else {
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
                }$v = Validator::make($raw, $rules);
                if ($v->fails()) {
                    throw new ValidationException(implode(' ', $v->errors()->all()));
                }$values = $v->validated();
            }
        } catch (ValidationException $e) {
            return self::answer($request, $e->getMessage(), 422, null, [FormV4::element($form, $version, $def, 'retry', false, $raw, $e->issues)], $e->issues);
        }
        $key = $modern ? $request->input('requestKey') : null;
        if ($key !== null && $key !== '' && (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_\-]{1,100}$/D', $key))) {
            return self::answer($request, 'Invalid submission key.', 422);
        }$key = $key ?: null;
        $fingerprint = hash('sha256', Json::encode(['version' => $version, 'values' => $values]));
        if ($key && ($previous = DB::table('form_submissions')->where('site_id', $site)->where('form_id', $form)->where('request_key', $key)->first())) {
            if ($previous->request_fingerprint !== $fingerprint) {
                return self::answer($request, 'This submission key belongs to different values.', 409);
            }

            return $this->confirmation($request, $def);
        }
        $used = DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.site_id', $site)->whereRaw("p.render_inputs->'forms'->?->>'version' = ?", [$form, (string) $version])->exists();
        if (! $used) {
            abort(404);
        }
        $rate = 'arkon-form:'.hash('sha256', $site.$form.$request->ip());
        $siteRate = 'arkon-form-site:'.$site;
        if (RateLimiter::tooManyAttempts($rate, 5) || RateLimiter::tooManyAttempts($siteRate, 100)) {
            return self::answer($request, 'Please wait a minute before sending another enquiry.', 429);
        }
        RateLimiter::hit($rate, 60);
        RateLimiter::hit($siteRate, 60);
        $id = Uuid::v7();
        $date = now()->toIso8601String();
        $inserted = DB::transaction(function () use ($site, $form, $version, $values, $key, $fingerprint, $id) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.form-submission:'.$site.':'.$form]);
            if ($key && ($r = DB::table('form_submissions')->where('site_id', $site)->where('form_id', $form)->where('request_key', $key)->first())) {
                if ($r->request_fingerprint !== $fingerprint) {
                    throw new ConflictException('The submission key belongs to another request.');
                }

                return false;
            }
            DB::table('form_submissions')->insert(['id' => $id, 'site_id' => $site, 'form_id' => $form, 'form_version' => $version, 'payload' => Crypt::encryptString(Json::encode($values)),
                'search_tokens' => '{'.implode(',', EntryIndex::tokens($values)).'}', 'request_key' => $key, 'request_fingerprint' => $fingerprint, 'notification_status' => 'pending']);

            return true;
        });
        if ($inserted) {
            $results = FormNotifications::send($def, $values, $id, $date, $row->notification_email);
            $states = array_column($results, 'status');
            $status = ! $states ? 'disabled' : (in_array('failed', $states, true) ? 'failed' : (in_array('unconfigured', $states, true) ? 'unconfigured' : (in_array('invalid_recipient', $states, true) ? 'failed' : 'sent')));
            DB::table('form_submissions')->where('id', $id)->update(['notification_status' => $status, 'notification_results' => Json::encode($results)]);
        }

        return $this->confirmation($request, $def);
    }

    private function confirmation(Request $request, array $def)
    {
        $c = $def['confirmation'] ?? ['type' => 'message', 'message' => $def['successMessage']];
        if ($c['type'] === 'redirect') {
            return $request->expectsJson() ? self::answer($request, 'Submission received.', 200, $c['url']) : redirect()->away($c['url'], 303)->withHeaders(['Cache-Control' => 'no-store']);
        }

        return self::answer($request, $c['message']);
    }

    private static function answer(Request $request, string $message, int $status = 200, ?string $redirect = null, array $extra = [], array $issues = [])
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $status < 400, 'message' => $message, ...($redirect ? ['redirect' => $redirect] : []), ...($issues ? ['issues' => $issues] : [])], $status, ['Cache-Control' => 'no-store']);
        }
        $body = Serializer::serialize(Element::h('main', [], Element::h('h1', ['tabindex' => -1], $message), $extra, Element::h('a', ['href' => '/'], 'Back to website')));

        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Form submission</title><style>'.file_get_contents(resource_path('arkon/components/form/v4.css')).'body{font:1rem system-ui;margin:2rem;max-width:50rem}</style>'.($extra ? FormRuntime::tag(2) : '').'</head><body>'.$body.'</body></html>', $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", 'X-Content-Type-Options' => 'nosniff']);
    }
}
