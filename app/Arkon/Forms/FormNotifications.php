<?php

namespace App\Arkon\Forms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class FormNotifications
{
    public static function merge(string $text, array $definition, array $values, string $entry, string $date): string
    {
        $tags = ['entry_id' => $entry, 'submission_date' => $date];
        $all = [];
        foreach ($definition['fields'] as $f) {
            $value = $values[$f['id']] ?? '';
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            $tags[$f['id']] = $value;
            if (array_key_exists($f['id'], $values)) {
                $all[] = $f['label'].': '.$value;
            }
        }
        $tags['all_fields'] = implode("\n", $all);

        return preg_replace_callback('/\{([^{}]+)\}/', fn ($m) => $tags[$m[1]] ?? '', $text);
    }

    public static function send(array $d, array $values, string $id, string $date, ?string $legacyEmail): array
    {
        $notifications = $d['notifications'] ?? [];
        if (! $notifications && $legacyEmail) {
            $notifications = [['id' => 'legacy', 'name' => 'Admin notification', 'enabled' => true, 'recipient' => $legacyEmail, 'replyTo' => '', 'fromName' => '', 'subject' => 'New website enquiry', 'message' => '{all_fields}', 'condition' => null]];
        }
        $results = [];
        foreach ($notifications as $n) {
            if (! $n['enabled'] || ! FormDefinition::matches($n['condition'] ?? null, $values)) {
                continue;
            }
            $to = self::merge($n['recipient'], $d, $values, $id, $date);
            $reply = self::merge($n['replyTo'] ?? '', $d, $values, $id, $date);
            if (! filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to.$reply)) {
                $results[] = ['id' => $n['id'], 'name' => $n['name'], 'status' => 'invalid_recipient'];

                continue;
            }
            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                $results[] = ['id' => $n['id'], 'name' => $n['name'], 'status' => 'unconfigured'];

                continue;
            }
            $subject = preg_replace('/[\r\n]+/', ' ', self::merge($n['subject'], $d, $values, $id, $date));
            $body = self::merge($n['message'], $d, $values, $id, $date);
            try {
                Mail::raw($body, function ($m) use ($to, $reply, $subject, $n) {
                    $m->to($to)->subject($subject)->from(config('mail.from.address'), $n['fromName'] ?: config('mail.from.name'));
                    if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                        $m->replyTo($reply);
                    }
                });
                $status = 'sent';
            } catch (\Throwable) {
                $status = 'failed';
                Log::warning('form.notification.failed', ['entry_id' => $id, 'notification_id' => $n['id']]);
            }
            $results[] = ['id' => $n['id'], 'name' => $n['name'], 'status' => $status];
        }

        return $results;
    }
}
