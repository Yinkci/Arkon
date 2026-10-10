<?php

namespace App\Arkon\Forms;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\GoneException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\RateLimitedException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Visitor submissions to a published form version: one set of rules for the HTML endpoint the
 * rendered form posts to and for the public API. Only a version used by a live page accepts
 * submissions; values are validated against that version's definition, stored encrypted with a
 * keyed search index, deduplicated by an optional request key, rate limited per client and per
 * site, and notifications are sent (best effort) once per stored entry.
 *
 * HTTP-only concerns (origin checks, body size, the honeypot field) stay with each endpoint.
 */
final class FormSubmissions
{
    /**
     * The published version a submission targets, or null when it does not exist, belongs to
     * another site or the form is in the Trash.
     *
     * @return array{definition: array, notificationEmail: ?string, modern: bool}|null
     */
    public function version(string $siteId, string $formId, int $version): ?array
    {
        if (! Uuid::isValid($formId)) {
            return null;
        }
        $row = DB::table('site_form_versions as v')->join('site_forms as f', fn ($j) => $j->on('f.id', '=', 'v.form_id')->on('f.site_id', '=', 'v.site_id'))
            ->where('v.site_id', $siteId)->where('v.form_id', $formId)->where('v.version', $version)->first(['v.definition', 'f.notification_email', 'f.archived_at']);
        if (! $row || $row->archived_at) {
            return null;
        }
        $definition = Json::decode($row->definition);

        return ['definition' => $definition, 'notificationEmail' => $row->notification_email, 'modern' => ($definition['schemaVersion'] ?? 1) === 2];
    }

    /**
     * Validates and stores one submission.
     *
     * @param  array{definition: array, notificationEmail: ?string, modern: bool}  $form  from version()
     * @param  string  $client  identifies the sender for rate limiting (the IP address)
     * @param  bool  $trapped  the endpoint's spam trap was filled: answered like a success, nothing stored
     * @return array{replayed: bool, confirmation: array{type: string, message?: string, url?: string}}
     *
     * @throws ValidationException|ConflictException|GoneException|NotFoundException|RateLimitedException
     */
    public function submit(string $siteId, string $formId, int $version, array $form, mixed $fields, mixed $requestKey, string $client, bool $trapped = false): array
    {
        $def = $form['definition'];
        if ($form['modern'] && ! $def['active']) {
            throw new GoneException('This form is not accepting submissions.');
        }
        if ($trapped) {
            return ['replayed' => false, 'confirmation' => ['type' => 'message', 'message' => $def['successMessage']]];
        }
        if (! is_array($fields)) {
            throw new ValidationException('Provide valid form fields.');
        }
        $values = $this->validate($def, $form['modern'], $fields);
        // A request key (random per page view in the forms runtime; the Idempotency-Key header in
        // the API) makes a retried submission return its first result instead of a second entry.
        $key = $requestKey;
        if ($key !== null && $key !== '' && (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_\-]{1,100}$/D', $key))) {
            throw new ValidationException('Invalid submission key.');
        }
        $key = $key ?: null;
        $fingerprint = hash('sha256', Json::encode(['version' => $version, 'values' => $values]));
        if ($key && ($previous = DB::table('form_submissions')->where('site_id', $siteId)->where('form_id', $formId)->where('request_key', $key)->first())) {
            if ($previous->request_fingerprint !== $fingerprint) {
                throw new ConflictException('This submission key belongs to different values.');
            }

            return ['replayed' => true, 'confirmation' => self::confirmation($def)];
        }
        if (! $this->isLive($siteId, $formId, $version)) {
            throw new NotFoundException('Form');
        }
        $rate = 'arkon-form:'.hash('sha256', $siteId.$formId.$client);
        $siteRate = 'arkon-form-site:'.$siteId;
        if (RateLimiter::tooManyAttempts($rate, 5) || RateLimiter::tooManyAttempts($siteRate, 100)) {
            throw new RateLimitedException('Please wait a minute before sending another enquiry.');
        }
        RateLimiter::hit($rate, 60);
        RateLimiter::hit($siteRate, 60);
        $id = Uuid::v7();
        $date = now()->toIso8601String();
        $inserted = DB::transaction(function () use ($siteId, $formId, $version, $values, $key, $fingerprint, $id) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.form-submission:'.$siteId.':'.$formId]);
            if ($key && ($r = DB::table('form_submissions')->where('site_id', $siteId)->where('form_id', $formId)->where('request_key', $key)->first())) {
                if ($r->request_fingerprint !== $fingerprint) {
                    throw new ConflictException('The submission key belongs to another request.');
                }

                return false;
            }
            DB::table('form_submissions')->insert(['id' => $id, 'site_id' => $siteId, 'form_id' => $formId, 'form_version' => $version, 'payload' => Crypt::encryptString(Json::encode($values)),
                'search_tokens' => '{'.implode(',', EntryIndex::tokens($values)).'}', 'request_key' => $key, 'request_fingerprint' => $fingerprint, 'notification_status' => 'pending']);

            return true;
        });
        if ($inserted) {
            $results = FormNotifications::send($def, $values, $id, $date, $form['notificationEmail']);
            $states = array_column($results, 'status');
            $status = ! $states ? 'disabled' : (in_array('failed', $states, true) ? 'failed' : (in_array('unconfigured', $states, true) ? 'unconfigured' : (in_array('invalid_recipient', $states, true) ? 'failed' : 'sent')));
            DB::table('form_submissions')->where('id', $id)->update(['notification_status' => $status, 'notification_results' => Json::encode($results)]);
        }

        return ['replayed' => ! $inserted, 'confirmation' => self::confirmation($def)];
    }

    /** The newest version of a form that a live page uses, or null: the version visitors can submit. */
    public function liveVersion(string $siteId, string $formId): ?int
    {
        if (! Uuid::isValid($formId)) {
            return null;
        }
        $version = DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.site_id', $siteId)
            ->max(DB::raw("(p.render_inputs->'forms'->'".$formId."'->>'version')::int"));

        return $version === null ? null : (int) $version;
    }

    /** Whether a live page uses exactly this form version (what makes a form public). */
    public function isLive(string $siteId, string $formId, int $version): bool
    {
        return DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.site_id', $siteId)
            ->whereRaw("p.render_inputs->'forms'->?->>'version' = ?", [$formId, (string) $version])->exists();
    }

    /** @return array{type: string, message?: string, url?: string} */
    public static function confirmation(array $def): array
    {
        $c = $def['confirmation'] ?? ['type' => 'message', 'message' => $def['successMessage']];

        return $c['type'] === 'redirect' ? ['type' => 'redirect', 'url' => $c['url']] : ['type' => 'message', 'message' => $c['message']];
    }

    private function validate(array $def, bool $modern, array $raw): array
    {
        if ($modern) {
            if (array_diff(array_keys($raw), array_column($def['fields'], 'id'))) {
                throw new ValidationException('The submission contains an unknown field.');
            }

            return FormDefinition::submission($def, $raw);
        }
        $rules = [];
        foreach ($def['fields'] as $f) {
            $rules[$f['id']] = [$f['required'] ? 'required' : 'nullable', 'string', 'max:2000'];
            if ($f['type'] === 'email') {
                $rules[$f['id']][] = 'email';
            }
            if ($f['type'] === 'select') {
                $rules[$f['id']][] = Rule::in($f['options']);
            }
            if ($f['type'] === 'checkbox') {
                $rules[$f['id']][] = Rule::in(['yes']);
            }
        }
        $v = Validator::make($raw, $rules);
        if ($v->fails()) {
            throw new ValidationException(implode(' ', $v->errors()->all()));
        }

        return $v->validated();
    }
}
