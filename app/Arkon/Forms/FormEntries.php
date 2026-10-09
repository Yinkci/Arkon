<?php

namespace App\Arkon\Forms;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FormEntries
{
    public function __construct(private Authorizer $auth, private FormManagement $forms) {}

    private function query(SiteContext $ctx, string $id, array $filters)
    {
        $this->auth->authorize($ctx, 'form.entries.view');
        $this->forms->row($ctx, $id);
        $query = DB::table('form_submissions')->where('site_id', $ctx->siteId)->where('form_id', $id);
        $filter = $filters['filter'] ?? 'inbox';
        if (in_array($filter, ['spam', 'trash'], true)) {
            $query->where('status', $filter);
        } else {
            $query->where('status', 'inbox');
            if ($filter === 'unread') {
                $query->whereNull('read_at');
            }if ($filter === 'starred') {
                $query->where('starred', true);
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $text = trim($filters['q'] ?? '');
        if ($text !== '') {
            if (Uuid::isValid($text)) {
                $query->where('id', $text);
            } else {
                if (! EntryIndex::terms($text)) {
                    $query->whereRaw('false');
                }foreach (EntryIndex::terms($text) as $term) {
                    $query->whereRaw('search_tokens @> ARRAY[?]::text[]', [EntryIndex::hash($term)]);
                }
            }
        }

        return $query;
    }

    public static function present(object $r): array
    {
        $def = DB::table('site_form_versions')->where('site_id', $r->site_id)->where('form_id', $r->form_id)->where('version', $r->form_version)->value('definition');
        $fields = Json::decode($def)['fields'];
        $values = Json::decode(Crypt::decryptString($r->payload));
        $display = [];
        foreach ($fields as $f) {
            if (array_key_exists($f['id'], $values)) {
                $v = $values[$f['id']];
                $choices = $f['choices'] ?? [];
                $labels = array_column($choices, 'label', 'value');
                $pretty = is_array($v) ? implode(', ', array_map(fn ($x) => $labels[$x] ?? $x, $v)) : ($labels[$v ?? ''] ?? ($v ?? ''));
                $display[] = ['id' => $f['id'], 'label' => $f['label'], 'value' => $pretty, 'type' => $f['type']];
            }
        }

        return ['id' => $r->id, 'createdAt' => $r->created_at, 'read' => $r->read_at !== null, 'starred' => (bool) $r->starred, 'status' => $r->status, 'notificationStatus' => $r->notification_status, 'notificationResults' => Json::decode($r->notification_results), 'fields' => $display, 'formVersion' => (int) $r->form_version];
    }

    public function browse(SiteContext $ctx, string $id, array $filters): array
    {
        $query = $this->query($ctx, $id, $filters);
        $total = (clone $query)->count();
        $page = min(max(1, (int) ($filters['page'] ?? 1)), max(1, (int) ceil($total / 25)));

        return ['items' => $query->orderBy('created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->orderBy('id')->offset(($page - 1) * 25)->limit(25)->get()->map(self::present(...))->all(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 25))];
    }

    public function detail(SiteContext $ctx, string $form, string $entry): array
    {
        $this->auth->authorize($ctx, 'form.entries.view');
        $this->forms->row($ctx, $form);
        Input::id($entry, 'Entry');
        $row = DB::table('form_submissions')->where('site_id', $ctx->siteId)->where('form_id', $form)->where('id', $entry)->first() ?? throw new NotFoundException('Entry');

        return self::present($row);
    }

    public function change(SiteContext $ctx, string $form, array $input): void
    {
        $this->auth->authorize($ctx, 'form.entries.manage');
        $this->forms->row($ctx, $form);
        $v = Input::validate($input, ['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|uuid|distinct', 'action' => 'required|in:read,unread,star,unstar,spam,trash,restore']);
        $query = DB::table('form_submissions')->where('site_id', $ctx->siteId)->where('form_id', $form)->whereIn('id', $v['ids']);
        if ((clone $query)->count() !== count($v['ids'])) {
            throw new NotFoundException('Entry');
        }
        $values = match ($v['action']) {
            'read' => ['read_at' => now()],'unread' => ['read_at' => null],'star' => ['starred' => true],'unstar' => ['starred' => false],'spam' => ['status' => 'spam'],'trash' => ['status' => 'trash'],'restore' => ['status' => 'inbox']
        };
        $query->update($values);
        app(AuditLog::class)->forContext($ctx, 'form.entries.'.$v['action'], 'form', $form, ['count' => count($v['ids'])]);
    }

    public function export(SiteContext $ctx, string $form, array $filters, array $chosen = []): StreamedResponse
    {
        $this->auth->authorize($ctx, 'form.entries.export');
        $query = $this->query($ctx, $form, $filters);
        $defs = DB::table('site_form_versions')->where('site_id', $ctx->siteId)->where('form_id', $form)->orderBy('version')->pluck('definition');
        $headers = [];
        foreach ($defs as $def) {
            foreach (Json::decode($def)['fields'] as $f) {
                if (! in_array($f['type'], ['section', 'divider'], true)) {
                    $headers[$f['id']] = $f['label'];
                }
            }
        }
        if ($chosen) {
            if (array_diff($chosen, array_keys($headers))) {
                throw new ValidationException('Choose valid export fields.');
            }$headers = array_intersect_key($headers, array_flip($chosen));
        }
        $safe = fn ($s) => preg_match('/^[\s]*[=+@\-]/u', (string) $s) ? "'".$s : (string) $s;

        return response()->streamDownload(function () use ($query, $headers, $safe) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, array_map($safe, ['Entry ID', 'Submitted', ...array_values($headers)]), ',', '"', '');
            foreach ($query->orderBy('id')->cursor() as $r) {
                $v = Json::decode(Crypt::decryptString($r->payload));
                fputcsv($out, array_map($safe, [$r->id, $r->created_at, ...array_map(fn ($id) => is_array($v[$id] ?? null) ? implode(', ', $v[$id]) : ($v[$id] ?? ''), array_keys($headers))]), ',', '"', '');
            }fclose($out);
        }, 'form-entries.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
