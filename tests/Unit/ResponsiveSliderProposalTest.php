<?php

namespace Tests\Unit;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\ProposalCompiler;
use App\Arkon\Components\PatternLibrary;
use App\Arkon\Support\Json;
use Tests\TestCase;

class ResponsiveSliderProposalTest extends TestCase
{
    private function compile(array $settings): array
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $id = collect($doc['nodes'])->firstWhere('type', 'slider')['id'];
        $reply = ['summary' => 'Responsive controls', 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'update', 'change' => ['id' => $id, 'type' => 'slider', 'props' => ['responsive' => $settings]]]]];

        return [app(ProposalCompiler::class)->compile($doc, $reply, [], 20), $doc, $id];
    }

    public function test_ai_settings_merge_into_the_reviewed_document_without_changing_desktop(): void
    {
        [$result,$before,$id] = $this->compile([['screen' => 'tablet', 'property' => 'arrowPlacement', 'value' => 'edges'], ['screen' => 'mobile', 'property' => 'arrows', 'value' => 'false'], ['screen' => 'mobile', 'property' => 'interval', 'value' => '1000']]);
        $props = Json::toArray($result['document']['nodes'][$id]['props']);
        $this->assertSame('edges', $props['responsive']['tablet']['arrowPlacement']);
        $this->assertSame('false', $props['responsive']['mobile']['arrows']);
        $this->assertSame('1000', $props['responsive']['mobile']['interval']);
        $this->assertSame($before['nodes'][$id]['props']['arrowPlacement'], $props['arrowPlacement']);
        $this->assertSame('inherit', $props['responsive']['tablet']['interval']);
    }

    public function test_ai_cannot_bypass_slider_rules_through_responsive_settings(): void
    {
        foreach ([['screen' => 'mobile', 'property' => 'interval', 'value' => '999'], ['screen' => 'desktop', 'property' => 'arrows', 'value' => 'false'], ['screen' => 'mobile', 'property' => 'script', 'value' => 'bad']] as $bad) {
            try {
                $this->compile([$bad]);
                $this->fail('Invalid responsive setting accepted');
            } catch (AiException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
    }
}
