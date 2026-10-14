<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where a field group applies, as rules instead of a fixed list of targets.
 *
 * A group already knew which content types carried it (field_group_assignments). That answered
 * one question — "which types?" — and nothing else, so it grows here into the question it was
 * always standing in for: under what conditions do these fields apply? The shape is
 *
 *   {"groups": [ {"rules": [ {"attribute": …, "operator": …, "value": …} ]} ]}
 *
 * where rules inside a group are AND and the groups themselves are OR. An empty list of groups
 * means the fields apply nowhere, which is what a group that has not been placed yet means.
 *
 * The old assignments become rules of exactly the same meaning, so nothing a site had set up
 * changes, and the table goes: one way of saying where a group belongs, not two.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
  ALTER TABLE field_groups ADD COLUMN rules jsonb NOT NULL DEFAULT '{"groups": []}'::jsonb;
  SQL);

        // Each assignment was "content type is X"; several of them were alternatives, so each
        // becomes its own rule group (OR), which is what the list meant.
        foreach (DB::table('field_groups')->get(['id']) as $group) {
            $targets = DB::table('field_group_assignments')
                ->where('group_id', $group->id)
                ->orderBy('position')
                ->get(['target_kind', 'target_key']);
            $rules = [];
            foreach ($targets as $target) {
                if ($target->target_kind !== 'content_type') {
                    continue;
                }
                $rules[] = ['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => $target->target_key]]];
            }
            DB::table('field_groups')->where('id', $group->id)->update(['rules' => json_encode(['groups' => $rules])]);
        }

        DB::unprepared(<<<'SQL'
  DROP TABLE IF EXISTS field_group_assignments;
  -- Resolving a group's rules means reading the site's groups, so that read is the one to serve.
  CREATE INDEX field_groups_site_idx ON field_groups (site_id);
  SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
  CREATE TABLE field_group_assignments (
      site_id uuid NOT NULL,
      group_id uuid NOT NULL,
      target_kind text NOT NULL CHECK (target_kind ~ '^[a-z][a-z0-9_]{0,31}$'),
      target_key text NOT NULL CHECK (target_key ~ '^[a-z][a-z0-9_]{0,63}$'),
      position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
      created_at timestamptz NOT NULL DEFAULT now(),
      PRIMARY KEY (group_id, target_kind, target_key),
      CONSTRAINT field_group_assignments_group_fk FOREIGN KEY (site_id, group_id)
          REFERENCES field_groups (site_id, id) ON DELETE CASCADE
  );
  CREATE INDEX field_group_assignments_target_idx ON field_group_assignments (site_id, target_kind, target_key);
  SQL);

        // The rules that say "content type is X" go back to being assignments; anything a rule
        // could express and an assignment could not is lost, which is why this is a way back and
        // not a way to keep both.
        foreach (DB::table('field_groups')->get(['id', 'site_id', 'rules']) as $group) {
            $rules = json_decode((string) $group->rules, true);
            $position = 0;
            foreach ($rules['groups'] ?? [] as $set) {
                foreach ($set['rules'] ?? [] as $rule) {
                    if (($rule['attribute'] ?? null) === 'content_type' && ($rule['operator'] ?? null) === 'is') {
                        DB::table('field_group_assignments')->insertOrIgnore([
                            'site_id' => $group->site_id, 'group_id' => $group->id,
                            'target_kind' => 'content_type', 'target_key' => $rule['value'], 'position' => $position++,
                        ]);
                    }
                }
            }
        }

        DB::unprepared(<<<'SQL'
  DROP INDEX IF EXISTS field_groups_site_idx;
  ALTER TABLE field_groups DROP COLUMN IF EXISTS rules;
  SQL);
    }
};
