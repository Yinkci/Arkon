<?php

namespace App\Console\Commands;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Support\Json;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only scan of stored builder content: reusable components, page drafts, references from pages to reusable
 * components, and the shared layout and components stored in open or applied website proposals. Reports what the
 * renderer would reject; it never changes anything (there is no repair mode: the one known defect, a page-shaped
 * shared layout in a stored proposal, is read in component form by WebsiteRevisions::componentForm).
 */
class ValidateContent extends Command
{
    protected $signature = 'arkon:validate-content {--site= : Only this site id}';

    protected $description = 'Report invalid builder content (components, page drafts, references, website proposals) without changing it';

    public function handle(DocumentValidator $validator, ComponentRegistry $registry): int
    {
        $site = $this->option('site');
        $scope = fn ($q) => $site ? $q->where('site_id', $site) : $q;
        $rows = [];
        $add = function (string $kind, string $id, string $name, array $issues) use (&$rows) {
            foreach (array_slice($issues, 0, 3) as $issue) {
                $rows[] = [$kind, $id, mb_substr($name, 0, 40), ($issue['path'] ?? $issue['nodeId'] ?? '').' '.$issue['message']];
            }
        };
        $decode = function (string $json) use ($registry) {
            try {
                return $registry->migrateDocument(Json::decode($json));
            } catch (\Throwable $e) {
                return ['__error' => $e->getMessage()];
            }
        };

        $components = $scope(DB::table('reusable_components'))->get(['id', 'site_id', 'name', 'draft']);
        $known = $components->pluck('site_id', 'id')->all();
        foreach ($components as $c) {
            $doc = $decode($c->draft);
            $add('component', $c->id, $c->name, isset($doc['__error']) ? [['message' => 'Unreadable: '.$doc['__error']]] : $validator->validateFragment($doc, pinned: true));
        }

        $pages = $scope(DB::table('page_drafts')->join('pages', 'pages.id', '=', 'page_drafts.page_id')->whereNull('pages.deleted_at'))
            ->get(['pages.id', 'pages.site_id', 'pages.path', 'page_drafts.document']);
        foreach ($pages as $p) {
            $doc = $decode($p->document);
            if (isset($doc['__error'])) {
                $add('page draft', $p->id, $p->path, [['message' => 'Unreadable: '.$doc['__error']]]);

                continue;
            }
            $add('page draft', $p->id, $p->path, $validator->validatePinned($doc));
            foreach (Json::entries($doc['nodes'] ?? []) as $id => $node) {
                $ref = $node['type'] === 'instance' ? (Json::entries($node['props'])['componentId'] ?? null) : null;
                if ($ref !== null && ($known[$ref] ?? null) !== $p->site_id) {
                    $add('page reference', $p->id, $p->path, [['nodeId' => $id, 'message' => 'Reusable component '.$ref.' does not exist on this site']]);
                }
            }
        }

        $proposals = $scope(DB::table('ai_proposals'))->whereIn('status', ['proposed', 'applied'])->whereNotNull('website_result')->get(['id', 'prompt', 'website_result']);
        foreach ($proposals as $r) {
            $result = Json::decode($r->website_result);
            foreach ([...array_values($result['shared'] ?? []), ...($result['components'] ?? [])] as $s) {
                $issues = $validator->validateFragment($registry->migrateDocument($s['document']), pinned: true);
                $add('proposal resource', $r->id, $r->prompt, array_map(fn ($i) => [...$i, 'message' => 'component '.$s['id'].': '.$i['message']], $issues));
            }
        }

        $this->line(sprintf('Checked %d components, %d page drafts, %d website proposals.', $components->count(), $pages->count(), $proposals->count()));
        if ($rows === []) {
            $this->info('No invalid content found.');

            return self::SUCCESS;
        }
        $this->table(['kind', 'id', 'name', 'issue'], $rows);
        $this->warn(count(array_unique(array_column($rows, 1))).' item(s) have issues. Nothing was changed.');

        return self::FAILURE;
    }
}
