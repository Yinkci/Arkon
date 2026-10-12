<?php

namespace Tests\Unit;

use App\Arkon\Ai\FormChanges;
use App\Arkon\Ai\ProposalSchema;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Ai\WebsiteRevisions;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Forms\FormDefinition;
use App\Arkon\Support\Rules;
use Tests\TestCase;

/**
 * AI may do what the builder can do. These checks read the same registries the builder uses and fail
 * when the builder gains a block property, style property, SEO setting, form field type or form setting
 * that AI proposals cannot express, unless it is a listed deliberate exception with its reason.
 */
class AiBuilderParityTest extends TestCase
{
    private function schema(): array
    {
        return app(ProposalSchema::class)->schema(['00000000-0000-4000-8000-000000000001'], ['00000000-0000-4000-8000-000000000002'], null);
    }

    public function test_every_block_type_and_property_the_builder_edits_can_be_proposed(): void
    {
        $schema = $this->schema();
        $registry = app(ComponentRegistry::class);
        $types = array_map(fn ($k) => substr($k, 7), array_values(array_filter(array_keys($schema['$defs']), fn ($k) => str_starts_with($k, 'update_'))));
        $this->assertNotEmpty($types);
        foreach ($types as $type) {
            $offered = array_keys($schema['$defs']["update_{$type}"]['properties']['props']['properties']);
            $this->assertSame([], array_values(array_diff(array_keys($registry->current($type)->props->fields()), $offered)), "{$type}: builder properties AI cannot set");
            $this->assertArrayHasKey("block_{$type}", $schema['$defs'], "{$type}: AI can add it");
        }
    }

    public function test_every_design_property_and_seo_setting_can_be_proposed(): void
    {
        $schema = $this->schema();
        $style = json_encode($schema['$defs']['style']);
        foreach (array_keys(Rules::get('style')['properties']) as $property) {
            $this->assertStringContainsString('"'.$property.'"', $style, "style property {$property}");
        }
        $seo = $schema['properties']['changes']['items']['anyOf'][1]['properties']['field']['enum'];
        $this->assertEqualsCanonicalizing(array_keys(Rules::get('seo')), $seo, 'every page SEO setting');
    }

    public function test_every_form_editor_setting_can_be_proposed_except_the_human_only_ones(): void
    {
        $schema = FormChanges::schema(['f']);
        $this->assertEqualsCanonicalizing(array_keys(FormDefinition::registry()['types']), array_values(array_filter($schema['items']['properties']['type']['enum'])), 'every field type');
        $editable = [...array_values(FormChanges::SETTINGS), 'type', 'choices', 'condition', 'id'];
        $fieldKeys = array_keys(FormDefinition::modern(['name' => 'n', 'submitLabel' => 's', 'successMessage' => 'm', 'fields' => [['id' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false]]])['fields'][0]);
        $this->assertSame([], array_values(array_diff($fieldKeys, $editable, ['options'])), 'field settings AI cannot change');
        $formKeys = array_keys(FormDefinition::modern(['name' => 'n', 'submitLabel' => 's', 'successMessage' => 'm', 'fields' => []]));
        $this->assertSame([], array_values(array_diff($formKeys, ['schemaVersion', 'fields', ...array_keys(FormChanges::FORM_SETTINGS), ...array_keys(FormChanges::HUMAN_ONLY)])), 'form settings AI cannot change');
        foreach (FormChanges::HUMAN_ONLY as $setting => $reason) {
            $this->assertNotSame('', $reason, "{$setting} needs a reason");
        }
    }

    public function test_page_revisions_offer_page_settings_forms_and_components(): void
    {
        $service = app(WebsiteProposalService::class);
        $snapshot = ['assets' => [], 'componentIds' => [], 'themeTypes' => null, 'pages' => ['p' => []], 'form' => ['id' => 'f', 'version' => 1, 'definition' => null],
            'shared' => ['header' => ['id' => 'h', 'version' => 1], 'footer' => ['id' => 'x', 'version' => 1]], 'baseline' => null,
            'scope' => ['siteForm' => true, 'forms' => [], 'shared' => ['header'], 'components' => []], 'revision' => ['scope' => 'page', 'pageId' => 'p', 'nodeId' => null, 'label' => 'Page']];
        $properties = app(WebsiteRevisions::class)->format($service, $snapshot)['schema']['properties'];
        $this->assertSame(['title', 'path'], array_keys($properties['settings']['properties']));
        $this->assertArrayHasKey('formChanges', $properties);
        $this->assertArrayHasKey('components', $properties);
    }
}
