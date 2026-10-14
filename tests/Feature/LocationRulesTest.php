<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\FieldSchemaResolver;
use App\Arkon\Content\LocationRules;
use App\Arkon\Content\TermService;
use App\Arkon\Errors\ValidationException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Where a field group's fields apply. Rules inside a rule group are AND; the rule groups are
 * alternatives, OR. One resolver decides, so the admin, the API and AI cannot disagree.
 */
final class LocationRulesTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function type(string $key, string $plural, array $taxonomies = []): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => $key, 'name' => $plural, 'singular' => rtrim($plural, 's'), 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/'.str_replace('_', '-', $key), 'inMenu' => true, 'menuOrder' => 1, 'details' => true,
            'taxonomies' => $taxonomies, 'fields' => [],
        ]);
    }

    private function group(string $key, array $rules, array $fields = []): array
    {
        return app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => $key, 'name' => ucfirst(str_replace('_', ' ', $key)), 'description' => '', 'rules' => $rules,
            'fields' => $fields ?: [['key' => $key.'_note', 'label' => 'Note', 'type' => 'text', 'settings' => (object) []]],
        ]);
    }

    private static function rule(string $attribute, string $operator, string $value): array
    {
        return ['attribute' => $attribute, 'operator' => $operator, 'value' => $value];
    }

    public function test_rules_in_one_group_must_all_hold_and_separate_groups_are_alternatives(): void
    {
        // (content type is service AND edited by an editor) OR (content type is workshop)
        $rules = ['groups' => [
            ['rules' => [self::rule('content_type', 'is', 'service'), self::rule('user_role', 'is', 'editor')]],
            ['rules' => [self::rule('content_type', 'is', 'workshop')]],
        ]];

        $this->assertTrue(LocationRules::match($rules, ['kind' => 'service', 'role' => 'editor']));
        $this->assertFalse(LocationRules::match($rules, ['kind' => 'service', 'role' => 'owner']), 'both rules in a group must hold');
        $this->assertTrue(LocationRules::match($rules, ['kind' => 'workshop', 'role' => 'owner']), 'the other group is enough on its own');
        $this->assertFalse(LocationRules::match($rules, ['kind' => 'page', 'role' => 'editor']));
    }

    public function test_no_rules_at_all_means_the_fields_apply_nowhere(): void
    {
        $this->assertFalse(LocationRules::match(['groups' => []], ['kind' => 'service']));
        $this->assertFalse(LocationRules::match([], ['kind' => 'service']));
    }

    public function test_every_attribute_decides_what_it_says_it_does(): void
    {
        $cases = [
            ['content_type', 'is', 'service', ['kind' => 'service'], true],
            ['content_type', 'is', 'service', ['kind' => 'post'], false],
            ['content_type', 'is_not', 'service', ['kind' => 'post'], true],
            ['content_type', 'is_not', 'service', ['kind' => 'service'], false],
            ['entry', 'is', 'abc', ['entryId' => 'abc'], true],
            ['entry', 'is', 'abc', ['entryId' => 'xyz'], false],
            ['entry', 'is_not', 'abc', ['entryId' => 'xyz'], true],
            ['taxonomy_term', 'contains', 't1', ['terms' => ['t1', 't2']], true],
            ['taxonomy_term', 'contains', 't3', ['terms' => ['t1']], false],
            ['taxonomy_term', 'does_not_contain', 't3', ['terms' => ['t1']], true],
            ['user_role', 'is', 'editor', ['role' => 'editor'], true],
            ['user_role', 'is', 'editor', ['role' => 'owner'], false],
            ['user_role', 'is_not', 'editor', ['role' => 'owner'], true],
        ];
        foreach ($cases as [$attribute, $operator, $value, $context, $expected]) {
            $rules = ['groups' => [['rules' => [self::rule($attribute, $operator, $value)]]]];
            $this->assertSame($expected, LocationRules::match($rules, $context), "{$attribute} {$operator} {$value}");
        }
    }

    public function test_what_a_rule_asks_about_but_the_context_does_not_know_is_simply_not_matched(): void
    {
        // Asking about terms while knowing only the kind must not throw; it just does not match.
        $rules = ['groups' => [['rules' => [self::rule('taxonomy_term', 'contains', 't1')]]]];
        $this->assertFalse(LocationRules::match($rules, ['kind' => 'service']));
        // And "does not have" holds, because what is not known to be there is not there.
        $negative = ['groups' => [['rules' => [self::rule('taxonomy_term', 'does_not_contain', 't1')]]]];
        $this->assertTrue(LocationRules::match($negative, ['kind' => 'service']));
    }

    public function test_rules_are_checked_before_they_are_stored(): void
    {
        $refused = [
            'unknown attribute' => [self::rule('phase_of_the_moon', 'is', 'full')],
            'wrong operator for the attribute' => [self::rule('content_type', 'contains', 'service')],
            'unknown operator' => [self::rule('content_type', 'resembles', 'service')],
            'empty value' => [self::rule('content_type', 'is', '  ')],
            'role that does not exist' => [self::rule('user_role', 'is', 'wizard')],
        ];
        foreach ($refused as $name => $rules) {
            try {
                LocationRules::validate(['groups' => [['rules' => $rules]]]);
                $this->fail("Accepted a rule with an {$name}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        // An empty rule group would mean "always", which is said by having no groups at all.
        $this->assertSame(['groups' => []], LocationRules::validate(['groups' => [['rules' => []]]]));
    }

    public function test_an_entry_gets_the_fields_of_the_groups_whose_rules_it_matches(): void
    {
        $this->type('service', 'Services');
        $this->type('workshop', 'Workshops');
        $this->group('service_only', ['groups' => [['rules' => [self::rule('content_type', 'is', 'service')]]]]);
        $this->group('both', ['groups' => [
            ['rules' => [self::rule('content_type', 'is', 'service')]],
            ['rules' => [self::rule('content_type', 'is', 'workshop')]],
        ]]);

        $resolver = app(FieldSchemaResolver::class);
        $this->assertSame(['both_note', 'service_only_note'], self::keys($resolver->fieldsFor($this->f['siteId'], ['kind' => 'service'])));
        $this->assertSame(['both_note'], self::keys($resolver->fieldsFor($this->f['siteId'], ['kind' => 'workshop'])));
        $this->assertSame([], $resolver->fieldsFor($this->f['siteId'], ['kind' => 'page']));
    }

    public function test_a_rule_about_one_entry_puts_fields_on_that_entry_alone(): void
    {
        $this->type('service', 'Services');
        $one = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Web'])['id'];
        $two = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'SEO'])['id'];
        $this->group('for_one', ['groups' => [['rules' => [self::rule('entry', 'is', $one)]]]]);

        $resolver = app(FieldSchemaResolver::class);
        $this->assertSame(['for_one_note'], self::keys($resolver->fieldsFor($this->f['siteId'], $resolver->contextForEntry($this->f['siteId'], $one))));
        $this->assertSame([], $resolver->fieldsFor($this->f['siteId'], $resolver->contextForEntry($this->f['siteId'], $two)));

        // And the value may only be written where the field applies.
        $fields = app(EntryFields::class);
        $this->assertSame('Only here', $fields->update($this->f['ctx'], $one, ['for_one_note' => 'Only here'])['for_one_note']);
        $this->expectException(ValidationException::class);
        $fields->update($this->f['ctx'], $two, ['for_one_note' => 'Not here']);
    }

    public function test_a_rule_about_a_category_follows_the_entrys_terms(): void
    {
        $this->type('service', 'Services', ['category']);
        $term = app(TermService::class)->create($this->f['ctx'], 'category', ['name' => 'Featured']);
        $entry = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Web'])['id'];
        $this->group('featured_extras', ['groups' => [['rules' => [self::rule('taxonomy_term', 'contains', $term['id'])]]]]);
        $resolver = app(FieldSchemaResolver::class);

        // Without the category the fields are not there.
        $this->assertSame([], $resolver->fieldsFor($this->f['siteId'], $resolver->contextForEntry($this->f['siteId'], $entry)));

        DB::table('page_terms')->insert(['site_id' => $this->f['siteId'], 'page_id' => $entry, 'term_id' => $term['id']]);
        app(FieldSchemaResolver::class)->forget($this->f['siteId']);
        $this->assertSame(
            ['featured_extras_note'],
            self::keys($resolver->fieldsFor($this->f['siteId'], $resolver->contextForEntry($this->f['siteId'], $entry)))
        );
    }

    public function test_the_rules_read_as_a_sentence(): void
    {
        $this->type('service', 'Services');
        $this->type('workshop', 'Workshops');
        $plain = $this->group('plain', ['groups' => [
            ['rules' => [self::rule('content_type', 'is', 'service')]],
            ['rules' => [self::rule('content_type', 'is', 'workshop')]],
        ]]);
        $this->assertSame('Services, or Workshops', $plain['appliesTo']);

        $narrow = $this->group('narrow', ['groups' => [
            ['rules' => [self::rule('content_type', 'is', 'service'), self::rule('user_role', 'is', 'owner')]],
        ]]);
        $this->assertSame('Services and edited by Owners', $narrow['appliesTo']);

        $nowhere = $this->group('nowhere', ['groups' => []]);
        $this->assertSame('Nowhere yet', $nowhere['appliesTo']);
    }

    public function test_the_resolver_reads_a_sites_groups_once_however_many_entries_it_is_asked_about(): void
    {
        $this->type('service', 'Services');
        foreach (range(1, 6) as $i) {
            $this->group("g{$i}", ['groups' => [['rules' => [self::rule('content_type', 'is', 'service')]]]]);
        }
        $entries = [];
        foreach (range(1, 5) as $i) {
            $entries[] = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => "Service {$i}"])['id'];
        }
        $resolver = app(FieldSchemaResolver::class);
        $resolver->forget($this->f['siteId']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $contexts = $resolver->contextsForEntries($this->f['siteId'], $entries);
        foreach ($contexts as $context) {
            $resolver->fieldsFor($this->f['siteId'], $context);
        }
        // Two for the contexts of all five entries, one for the site's groups, and whatever the
        // type registry needs once: evaluating rules themselves costs nothing.
        $this->assertLessThanOrEqual(5, count($queries), "resolving five entries ran:\n".implode("\n", $queries));
        $this->assertCount(6, $resolver->groupsFor($this->f['siteId'], ['kind' => 'service']));
    }

    public function test_a_group_may_not_bring_a_key_a_type_its_rules_reach_already_has(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text', 'settings' => (object) []]],
        ]);
        $this->expectException(ValidationException::class);
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'extras', 'name' => 'Extras', 'description' => '',
            'rules' => ['groups' => [['rules' => [self::rule('content_type', 'is', 'service')]]]],
            'fields' => [['key' => 'note', 'label' => 'Another note', 'type' => 'text', 'settings' => (object) []]],
        ]);
    }

    /** @return list<string> */
    private static function keys(array $fields): array
    {
        $keys = array_column($fields, 'key');
        sort($keys);

        return $keys;
    }
}
