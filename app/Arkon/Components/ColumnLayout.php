<?php

namespace App\Arkon\Components;

use App\Arkon\Support\Json;
use stdClass;

/**
 * Columns widths after the columns changed: the PHP twin of resources/js/arkon/editor/columns.ts
 * (reconcileColumns, resetNotice), used where proposals are compiled. A Columns block's
 * fraction widths must give one width per column (DocumentValidator::widthIssues), so when its
 * columns are added, removed, moved or copied the widths are reconciled with the same rules
 * as in the editor:
 * - proportions follow their columns when every column came from the old list (removing,
 *   copying, reordering); otherwise that screen goes back to equal widths (reported);
 * - a count equal to the old number of columns ("all side by side") becomes the new number;
 * - a smaller count (wrapping, or "1" to stack) stays while it fits; a larger one resets.
 */
final class ColumnLayout
{
    private const SCREENS = ['base' => 'all screens', 'tablet' => 'tablets', 'mobile' => 'phones'];

    /**
     * @param  list<int|null>  $mapping  for each new column in order, the index of the old column it came from (null: new)
     * @param  list<string>  $keep  screens whose widths were set explicitly: left exactly as set (and validated)
     * @return array{style: array, reset: list<string>}
     */
    public static function reconcile(array $style, int $oldCount, array $mapping, array $keep = []): array
    {
        $newCount = count($mapping);
        $reset = [];
        foreach (array_keys(self::SCREENS) as $bp) {
            $value = $style['root'][$bp]['columns'] ?? null;
            if (! is_string($value) || in_array($bp, $keep, true)) {
                continue;
            }
            $replacement = false; // false: keep, null: remove
            if (preg_match('/^[1-6]$/D', $value) === 1) {
                $k = (int) $value;
                if ($k === 1) {
                    $replacement = false;
                } elseif ($k === $oldCount) {
                    $replacement = $bp === 'base' ? null : (string) $newCount;
                } elseif ($k > $newCount) {
                    $replacement = null;
                    $reset[] = $bp;
                }
            } else {
                $tracks = explode(' ', $value);
                $known = array_filter($mapping, fn ($i) => $i !== null && $i < count($tracks));
                if (count($tracks) === $oldCount && count($known) === $newCount) {
                    $mapped = implode(' ', array_map(fn (int $i) => $tracks[$i], $mapping));
                    $replacement = $newCount === 1 ? null : ($mapped === $value ? false : $mapped);
                } elseif (count($tracks) !== $newCount) {
                    $replacement = null;
                    $reset[] = $bp;
                }
            }
            if ($replacement === false) {
                continue;
            }
            if ($replacement === null) {
                unset($style['root'][$bp]['columns']);
            } else {
                $style['root'][$bp]['columns'] = $replacement;
            }
            if (($style['root'][$bp] ?? null) === []) {
                unset($style['root'][$bp]);
            }
        }
        if (($style['root'] ?? null) === []) {
            unset($style['root']);
        }

        return ['style' => $style, 'reset' => $reset];
    }

    /** "Columns widths on all screens and tablets went back to equal: 2 widths no longer fit 3 columns". */
    public static function resetNotice(array $reset, int $oldCount, int $newCount): string
    {
        $screens = array_map(fn ($bp) => self::SCREENS[$bp], $reset);
        $list = count($screens) === 1 ? $screens[0] : implode(', ', array_slice($screens, 0, -1)).' and '.end($screens);

        return "Columns widths on {$list} went back to equal: {$oldCount} width".($oldCount === 1 ? '' : 's')." no longer fit {$newCount} column".($newCount === 1 ? '' : 's');
    }

    /**
     * Width changes for every Columns block whose columns changed between two documents. Screens
     * whose widths were set explicitly ($explicit: block id → screens with a `columns` setting)
     * are taken as they are (and validated); other style settings of the block (gap, padding,
     * background, animation …) never count as widths. $copies maps a copied column's id to its
     * original's, so a copy takes the original's width. Each result is one updateProps operation
     * with a description.
     *
     * @param  array<string, list<string>>  $explicit
     * @param  array<string, string>  $copies
     * @return list<array{op: array, description: string}>
     */
    public static function changes(array $before, array $after, array $explicit = [], array $copies = []): array
    {
        $old = Json::entries($before['nodes']);
        $out = [];
        foreach (Json::entries($after['nodes']) as $id => $node) {
            $id = (string) $id;
            if ($node['type'] !== 'columns' || ! isset($old[$id])) {
                continue;
            }
            $was = $old[$id]['children'] ?? [];
            $now = $node['children'] ?? [];
            if ($was === $now) {
                continue;
            }
            $mapping = array_map(function ($child) use ($was, $copies) {
                $index = array_search($copies[$child] ?? $child, $was, true);

                return $index === false ? null : $index;
            }, $now);
            $style = Json::toArray(Json::entries($node['props'] ?? [])['style'] ?? []);
            $result = self::reconcile($style, count($was), $mapping, $explicit[$id] ?? []);
            if ($result['style'] === $style) {
                continue;
            }
            $out[] = [
                'op' => ['op' => 'updateProps', 'nodeId' => $id, 'set' => ['style' => $result['style'] === [] ? new stdClass : $result['style']], 'unset' => []],
                'description' => $result['reset'] !== [] ? self::resetNotice($result['reset'], count($was), count($now)) : 'Columns widths follow their columns',
            ];
        }

        return $out;
    }
}
