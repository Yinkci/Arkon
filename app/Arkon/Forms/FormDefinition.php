<?php

namespace App\Arkon\Forms;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Input;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class FormDefinition
{
    public static function registry(): array
    {
        return json_decode(file_get_contents(resource_path('arkon/forms.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function modern(array $d): array
    {
        return ['schemaVersion' => 2, 'name' => $d['name'], 'submitLabel' => $d['submitLabel'], 'successMessage' => $d['successMessage'],
            'description' => $d['description'] ?? '', 'active' => $d['active'] ?? true,
            'confirmation' => $d['confirmation'] ?? ['type' => 'message', 'message' => $d['successMessage'], 'url' => ''],
            'notifications' => $d['notifications'] ?? [],
            'fields' => array_map(fn ($f) => [...$f, 'description' => $f['description'] ?? '', 'placeholder' => $f['placeholder'] ?? '', 'defaultValue' => $f['defaultValue'] ?? '',
                'width' => $f['width'] ?? 12, 'row' => $f['row'] ?? $f['id'], 'choices' => $f['choices'] ?? array_map(fn ($o) => ['label' => $o, 'value' => $o], $f['options'] ?? []),
                'condition' => $f['condition'] ?? null, 'min' => $f['min'] ?? null, 'max' => $f['max'] ?? null, 'step' => $f['step'] ?? null], $d['fields'])];
    }

    public static function validate(array $input): array
    {
        $limits = self::registry();
        $types = implode(',', array_keys($limits['types']));
        $d = Input::validate($input, [
            'schemaVersion' => 'required|in:2', 'name' => 'required|string|max:100', 'submitLabel' => 'required|string|max:60', 'successMessage' => 'required|string|max:400',
            'description' => 'present|nullable|string|max:600', 'active' => 'required|boolean', 'fields' => 'present|array|max:'.$limits['maxFields'],
            'fields.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,31}$/D|distinct', 'fields.*.label' => 'required|string|max:120',
            'fields.*.type' => 'required|in:'.$types, 'fields.*.required' => 'required|boolean', 'fields.*.description' => 'present|nullable|string|max:500',
            'fields.*.placeholder' => 'present|nullable|string|max:120', 'fields.*.defaultValue' => 'present|nullable|string|max:2000',
            'fields.*.row' => 'required|string|regex:/^[a-z][a-z0-9_]{0,31}$/D', 'fields.*.width' => 'required|in:3,4,6,12',
            'fields.*.choices' => 'present|array|max:'.$limits['maxChoices'], 'fields.*.choices.*.label' => 'required|string|max:100', 'fields.*.choices.*.value' => 'required|string|max:100',
            'fields.*.min' => 'nullable|numeric', 'fields.*.max' => 'nullable|numeric', 'fields.*.step' => 'nullable|numeric|gt:0',
            'confirmation.type' => 'required|in:message,redirect', 'confirmation.message' => 'present|nullable|string|max:400', 'confirmation.url' => 'present|nullable|string|max:500',
            'notifications' => 'present|array|max:'.$limits['maxNotifications'], 'notifications.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,31}$/D|distinct',
            'notifications.*.name' => 'required|string|max:100', 'notifications.*.enabled' => 'required|boolean', 'notifications.*.recipient' => 'required|string|max:254',
            'notifications.*.subject' => 'required|string|max:150', 'notifications.*.message' => 'required|string|max:4000',
            'notifications.*.fromName' => 'present|nullable|string|max:100', 'notifications.*.replyTo' => 'present|nullable|string|max:254',
        ]);
        $d['schemaVersion'] = 2;
        foreach (['description'] as $k) {
            $d[$k] ??= '';
        }
        foreach ($d['fields'] as &$f) {
            foreach (['description', 'placeholder', 'defaultValue'] as $k) {
                $f[$k] ??= '';
            }
            $values = array_column($f['choices'], 'value');
            if (count($values) !== count(array_unique($values))) {
                throw new ValidationException('Choice values must be unique.');
            }
            if (in_array($f['type'], ['select', 'radio', 'checkboxes'], true) && ! $values) {
                throw new ValidationException($f['label'].' needs at least one choice.');
            }
            if (in_array($f['type'], ['select', 'radio'], true) && $f['defaultValue'] !== '' && ! in_array($f['defaultValue'], $values, true)) {
                throw new ValidationException('Choose a valid default for '.$f['label'].'.');
            }
            if (($f['min'] ?? null) !== null && ($f['max'] ?? null) !== null && $f['min'] > $f['max']) {
                throw new ValidationException('Minimum must not exceed maximum.');
            }
            foreach (['min', 'max', 'step'] as $number) {
                if (isset($f[$number]) && ! is_finite((float) $f[$number])) {
                    throw new ValidationException('Number settings must be finite.');
                }
            }
            $f['condition'] = self::condition($input['fields'][array_search($f['id'], array_column($d['fields'], 'id'), true)]['condition'] ?? null, $d['fields'], $f['id']);
        } unset($f);
        foreach ($d['fields'] as $f) {
            if ($f['defaultValue'] !== '' && ! in_array($f['type'], ['section', 'divider', 'checkboxes'], true)) {
                try {
                    self::submission(['fields' => [[...$f, 'required' => false, 'condition' => null]]], [$f['id'] => $f['defaultValue']]);
                } catch (ValidationException) {
                    throw new ValidationException('Provide a valid default value for '.$f['label'].'.');
                }
            }
        }
        $graph = [];
        foreach ($d['fields'] as $f) {
            $graph[$f['id']] = array_column($f['condition']['rules'] ?? [], 'fieldId');
        }
        $walk = function ($id, $path = []) use (&$walk, $graph) {
            if (isset($path[$id])) {
                throw new ValidationException('Conditional fields cannot depend on each other in a loop.');
            } $path[$id] = true;
            foreach ($graph[$id] ?? [] as $ref) {
                $walk($ref, $path);
            }
        };
        foreach (array_keys($graph) as $id) {
            $walk($id);
        }
        $confirmation = $d['confirmation'];
        $confirmation['message'] ??= '';
        $confirmation['url'] ??= '';
        if ($confirmation['type'] === 'message' && trim($confirmation['message']) === '') {
            throw new ValidationException('Provide a confirmation message.');
        }
        if ($confirmation['type'] === 'redirect' && ! self::safeRedirect($confirmation['url'])) {
            throw new ValidationException('Use a local /path or an http(s) confirmation URL.');
        }
        $d['confirmation'] = $confirmation;
        $d['successMessage'] = $confirmation['type'] === 'message' ? $confirmation['message'] : $d['successMessage'];
        foreach ($d['notifications'] as $i => &$n) {
            $n['fromName'] ??= '';
            $n['replyTo'] ??= '';
            foreach (['recipient', 'replyTo', 'subject', 'fromName'] as $key) {
                if (preg_match('/[\r\n]/', $n[$key])) {
                    throw new ValidationException('Email headers must be a single line.');
                }
            }
            foreach (['recipient', 'replyTo'] as $key) {
                if ($n[$key] === '' && $key === 'replyTo') {
                    continue;
                }
                if (preg_match('/^\{([a-z][a-z0-9_]{0,31})\}$/D', $n[$key], $m)) {
                    $field = collect($d['fields'])->firstWhere('id', $m[1]);
                    if (! $field || $field['type'] !== 'email') {
                        throw new ValidationException('Choose an email field for notification recipients or replies.');
                    }
                } elseif (! filter_var($n[$key], FILTER_VALIDATE_EMAIL)) {
                    throw new ValidationException('Provide a valid notification email address.');
                }
            }
            $n['condition'] = self::condition($input['notifications'][$i]['condition'] ?? null, $d['fields']);
            preg_match_all('/\{([^{}]+)\}/', $n['subject'].' '.$n['message'], $tags);
            foreach ($tags[1] as $tag) {
                if (! in_array($tag, [...array_column($d['fields'], 'id'), 'all_fields', 'entry_id', 'submission_date'], true)) {
                    throw new ValidationException('Unknown merge field: '.$tag);
                }
            }
        }unset($n);

        return $d;
    }

    public static function safeRedirect(string $url): bool
    {
        return ! preg_match('/[\x00-\x20\\\\]/', $url) && ((str_starts_with($url, '/') && ! str_starts_with($url, '//')) || filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true));
    }

    public static function condition(mixed $c, array $fields, ?string $self = null): ?array
    {
        if ($c === null) {
            return null;
        }if (! is_array($c)) {
            throw new ValidationException('Conditions must contain valid rules.');
        }
        $c = Input::validate($c, ['mode' => 'required|in:all,any', 'rules' => 'required|array|min:1|max:10', 'rules.*.fieldId' => 'required|string', 'rules.*.operator' => 'required|in:is,is_not,contains,not_contains,greater,less,empty,not_empty', 'rules.*.value' => 'present|nullable|string|max:2000']);
        foreach ($c['rules'] as &$r) {
            $r['value'] ??= '';
            $field = collect($fields)->firstWhere('id', $r['fieldId']);
            if (! $field || $r['fieldId'] === $self || in_array($field['type'], ['section', 'divider'], true)) {
                throw new ValidationException('Choose another input field for this condition.');
            }if (in_array($r['operator'], ['greater', 'less'], true) && ($field['type'] !== 'number' || ! is_numeric($r['value']) || ! is_finite((float) $r['value']))) {
                throw new ValidationException('Numeric conditions require a number field and value.');
            }
        }unset($r);

        return $c;
    }

    public static function matches(?array $c, array $values): bool
    {
        if ($c === null) {
            return true;
        }$results = [];
        foreach ($c['rules'] as $r) {
            $raw = $values[$r['fieldId']] ?? '';
            $v = is_array($raw) ? implode(', ', $raw) : (string) $raw;
            $target = $r['value'];
            $results[] = match ($r['operator']) {
                'is' => is_array($raw) ? in_array($target, $raw, true) : $v === $target,'is_not' => is_array($raw) ? ! in_array($target, $raw, true) : $v !== $target,'contains' => str_contains($v, $target),'not_contains' => ! str_contains($v, $target),'greater' => is_numeric($v) && is_finite((float) $v) && (float) $v > (float) $target,'less' => is_numeric($v) && is_finite((float) $v) && (float) $v < (float) $target,'empty' => $v === '','not_empty' => $v !== '',default => false
            };
        }

        return $c['mode'] === 'all' ? ! in_array(false, $results, true) : in_array(true, $results, true);
    }

    public static function visibleFields(array $d, array $values): array
    {
        $by = array_column($d['fields'], null, 'id');
        $visible = [];
        $resolving = [];
        $resolve = function ($id) use (&$resolve, &$visible, &$resolving, $by, $values) {
            if (array_key_exists($id, $visible)) {
                return $visible[$id];
            }if (isset($resolving[$id])) {
                return false;
            }$resolving[$id] = true;
            $f = $by[$id];
            $known = $values;
            foreach ($f['condition']['rules'] ?? [] as $r) {
                if (! $resolve($r['fieldId'])) {
                    unset($known[$r['fieldId']]);
                }
            }unset($resolving[$id]);

            return $visible[$id] = self::matches($f['condition'] ?? null, $known);
        };

        return array_values(array_filter($d['fields'], fn ($f) => $resolve($f['id'])));
    }

    public static function submission(array $d, array $values): array
    {
        $rules = [];
        $labels = [];
        foreach (self::visibleFields($d, $values) as $f) {
            if (in_array($f['type'], ['section', 'divider'], true)) {
                continue;
            }$labels[$f['id']] = $f['label'];
            $r = [$f['required'] ? 'required' : 'nullable'];
            if ($f['type'] === 'checkboxes') {
                $rules[$f['id']] = [...$r, 'array', 'max:30'];
                $rules[$f['id'].'.*'] = ['string', Rule::in(array_column($f['choices'], 'value'))];

                continue;
            }
            $r[] = 'string';
            $r[] = 'max:2000';
            if ($f['type'] === 'email') {
                $r[] = 'email';
            }if ($f['type'] === 'url') {
                $r[] = 'url:http,https';
            }if ($f['type'] === 'date') {
                $r[] = 'date_format:Y-m-d';
            }if ($f['type'] === 'time') {
                $r[] = 'date_format:H:i';
            }
            if ($f['type'] === 'number') {
                $r[] = 'numeric';
                if (($f['min'] ?? null) !== null) {
                    $r[] = 'min:'.$f['min'];
                }if (($f['max'] ?? null) !== null) {
                    $r[] = 'max:'.$f['max'];
                }
            }
            if (in_array($f['type'], ['select', 'radio'], true)) {
                $r[] = Rule::in(array_column($f['choices'], 'value'));
            }if ($f['type'] === 'checkbox') {
                $r[] = Rule::in(['yes']);
            }$rules[$f['id']] = $r;
        }
        $validator = Validator::make($values, $rules, [], $labels);
        if ($validator->fails()) {
            throw new ValidationException(implode(' ', $validator->errors()->all()), collect($validator->errors()->messages())->map(fn ($m, $key) => ['path' => $key, 'message' => $m[0]])->values()->all());
        }
        $valid = $validator->validated();
        foreach ($d['fields'] as $f) {
            if ($f['type'] === 'number' && isset($valid[$f['id']]) && ! is_finite((float) $valid[$f['id']])) {
                throw new ValidationException($f['label'].' must be a finite number.');
            }
        }
        foreach ($d['fields'] as $f) {
            if ($f['type'] === 'number' && isset($valid[$f['id']]) && ($f['step'] ?? null) !== null) {
                $q = ((float) $valid[$f['id']] - (float) ($f['min'] ?? 0)) / (float) $f['step'];
                if (abs($q - round($q)) > 1e-7) {
                    throw new ValidationException($f['label'].' must use the configured step.');
                }
            }
        }

        return $valid;
    }
}
