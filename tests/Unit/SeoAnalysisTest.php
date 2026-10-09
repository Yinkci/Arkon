<?php

namespace Tests\Unit;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\ProposalCompiler;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Components\Factories;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Schema\Operations;
use App\Arkon\Seo\SeoAnalysis;
use App\Arkon\Seo\SeoDefaults;
use App\Arkon\Support\Json;
use Tests\TestCase;

final class SeoAnalysisTest extends TestCase
{
    public function test_alt_suggestion_changes_only_the_selected_image_description(): void
    {
        $hero = Factories::heroNode(['image' => ['assetId' => '00000000-0000-4000-8000-000000000001', 'alt' => 'Existing description']]);
        $doc = Factories::pageDocument([$hero]);
        $compiled = app(ProposalCompiler::class)->compile($doc, ['summary' => 'Describe the saved image', 'notes' => [], 'changes' => [['action' => 'alt', 'nodeId' => $hero['id'], 'value' => 'Accurate description']]], ['00000000-0000-4000-8000-000000000001'], 5);
        ProposalService::assertSeoScope('ARKON_SEO_ALT_ONLY:'.$hero['id']."\nGenerate", $compiled, $doc);
        $this->assertSame('Accurate description', $compiled['operations'][0]['set']['image']['alt']);
        $compiled['operations'][0]['set']['image']['assetId'] = '00000000-0000-4000-8000-000000000002';
        $this->expectException(AiException::class);
        ProposalService::assertSeoScope('ARKON_SEO_ALT_ONLY:'.$hero['id']."\nGenerate", $compiled, $doc);
    }

    public function test_multiple_ai_metadata_fields_are_one_exact_save_batch(): void
    {
        $doc = Factories::pageDocument([Factories::heroNode()]);
        $compiled = app(ProposalCompiler::class)->compile($doc, ['summary' => 'SEO', 'notes' => [], 'changes' => [['action' => 'seo', 'field' => 'title', 'value' => 'Our work'], ['action' => 'seo', 'field' => 'description', 'value' => 'A useful summary.']]], [], 5);
        $this->assertCount(1, $compiled['operations']);
        $this->assertSame(['title' => 'Our work', 'description' => 'A useful summary.'], $compiled['operations'][0]['set']);
        $this->assertStringContainsString('SEO title', $compiled['changes'][0]);
        $this->assertStringContainsString('SEO description', $compiled['changes'][0]);
    }

    private function html(string $body = '<h1>Our services</h1><h2>Contact</h2><img alt=""><a href="/contact">Contact us</a>', string $extra = ''): string
    {
        return '<html><head><title>Our services</title><meta name="description" content="Learn about our services and contact us."><meta name="viewport" content="width=device-width"><link rel="canonical" href="https://site.test/"><meta property="og:title" content="Services"><meta name="twitter:title" content="Services">'.$extra.'</head><body>'.$body.'</body></html>';
    }

    public function test_scoring_is_explainable_and_does_not_penalize_intentional_noindex_or_decorative_images(): void
    {
        $a = new SeoAnalysis;
        $r = $a->analyze($this->html(extra: '<meta name="robots" content="noindex,follow">'), ['pageType' => 'contact'], ['/contact']);
        $this->assertSame(100, $r['score']);
        $this->assertSame(100, array_sum(array_column($r['categories'], 'possible')));
        $this->assertTrue($r['noindex']);
        $this->assertSame('Excellent', $r['label']);
        $this->assertSame($r['score'], array_sum(array_column($r['checks'], 'earned')));
    }

    public function test_missing_title_heading_alt_and_internal_destinations_have_separate_checks(): void
    {
        $h = str_replace('<title>Our services</title>', '', $this->html('<h2>Services</h2><h4>Details</h4><img><a href="/missing">Missing</a><a href="#">TODO</a>'));
        $r = (new SeoAnalysis)->analyze($h, [], ['/contact']);
        $checks = array_column($r['checks'], null, 'id');
        foreach (['title', 'h1', 'hierarchy', 'alt', 'links'] as $key) {
            $this->assertNotSame('passed', $checks[$key]['status']);
        }$this->assertSame(['/missing'], $r['brokenLinks']);
        $this->assertSame(37, $r['score']);
    }

    public function test_schema_json_is_checked_and_topic_guidance_cannot_change_score(): void
    {
        $r = (new SeoAnalysis)->analyze($this->html(extra: '<script type="application/ld+json">{bad}</script>'), ['focusTopic' => 'unrelated']);
        $this->assertSame('critical', array_column($r['checks'], null, 'id')['schema']['status']);
        $this->assertSame(100, $r['score']);
    }

    public function test_renderer_eight_inherits_published_defaults_and_escapes_overrides_without_changing_version_seven(): void
    {
        $doc = Factories::pageDocument([Factories::heroNode()]);
        $site = ['name' => 'Example', 'origin' => 'https://site.test', 'seoDefaults' => ['version' => 2, 'values' => [...SeoDefaults::defaults(), 'titlePattern' => '{page} | {site}', 'description' => 'A real company.', 'noindex' => true, 'organizationName' => 'A < B']]];
        $renderer = app(PageRenderer::class);
        $args = [$doc, 'production', ['title' => 'Home', 'path' => '/'], $site, []];
        $out = $renderer->render(...$args);
        $this->assertStringContainsString('<title>Home | Example</title>', $out['html']);
        $this->assertStringContainsString('content="noindex,follow"', $out['html']);
        $this->assertStringContainsString('"publisher"', $out['html']);
        $this->assertStringContainsString('A \\u003C B', $out['html']);
        $this->assertSame($out['html'], $renderer->reproduce($doc, $out['inputs'])['html']);
        $old = $renderer->render(...[...$args, false, false, 'arkon-php-7']);
        $this->assertStringNotContainsString('application/ld+json', $old['html']);
        $this->assertSame($old['html'], $renderer->reproduce($doc, $old['inputs'])['html']);
        $doc['seo'] = ['title' => 'Specific', 'description' => 'Summary', 'noindex' => false, 'nofollow' => true, 'canonical' => 'https://other.test/exact', 'socialTitle' => 'Social < title', 'schemaType' => 'ContactPage'];
        $next = $renderer->render(...[$doc, ...array_slice($args, 1)]);
        $this->assertStringContainsString('content="index,nofollow"', $next['html']);
        $this->assertStringContainsString('href="https://other.test/exact"', $next['html']);
        $this->assertStringContainsString('content="Social &lt; title"', $next['html']);
        $this->assertStringContainsString('"@type":"ContactPage"', $next['html']);
    }

    public function test_ai_seo_text_uses_normal_reversible_operations(): void
    {
        $doc = Factories::pageDocument([Factories::heroNode()]);
        $compiled = app(ProposalCompiler::class)->compile($doc, ['summary' => 'SEO summary', 'notes' => [], 'changes' => [['action' => 'seo', 'field' => 'description', 'value' => 'An accurate summary.']]], [], 5);
        ProposalService::assertSeoScope("ARKON_SEO_METADATA_ONLY:description\nGenerate", $compiled);
        $applied = Operations::apply($doc, $compiled['operations']);
        $this->assertSame('An accurate summary.', Json::entries($applied['doc']['seo'])['description']);
        $this->assertEquals($doc, Operations::apply($applied['doc'], $applied['inverse'])['doc']);
    }

    public function test_seo_field_request_cannot_change_other_metadata_or_content(): void
    {
        $this->expectException(AiException::class);
        ProposalService::assertSeoScope("ARKON_SEO_METADATA_ONLY:description\nGenerate", ['tokenChanges' => [], 'operations' => [['op' => 'updateSeo', 'set' => ['title' => 'Other']]]]);
    }

    public function test_seo_request_cannot_change_canonical_robots_or_design_tokens(): void
    {
        foreach ([['op' => 'updateSeo', 'set' => ['canonical' => 'https://other.test/']], ['op' => 'updateSeo', 'set' => ['noindex' => true]], ['op' => 'removeNode', 'nodeId' => 'hero0001']] as $op) {
            try {
                ProposalService::assertSeoScope("ARKON_SEO_METADATA_ONLY:all\nImprove", ['tokenChanges' => [], 'operations' => [$op]]);
                $this->fail('Unsafe change was accepted');
            } catch (AiException $e) {
                $this->assertSame(AiException::INVALID_OUTPUT, $e->code());
            }
        }
    }
}
