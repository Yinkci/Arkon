<?php

namespace App\Arkon\Seo;

/** Local HTML inspection, not a crawler or a ranking prediction. No network requests. */
final class SeoAnalysis
{
    /** Small, read-only sitemap policy from stored publication head, never draft state. */
    public static function deliveryPolicy(string $html): array
    {
        $head = explode('</head>', $html, 2)[0];
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$head.'</head><body></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);

        return ['noindex' => str_contains(strtolower((string) $xp->evaluate('string(//head/meta[@name="robots"]/@content)')), 'noindex'), 'canonical' => trim((string) $xp->evaluate('string(//head/link[@rel="canonical"]/@href)'))];
    }

    public function analyze(string $html, array $seo = [], array $knownPaths = [], string $origin = ''): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);
        $meta = fn ($name, $property = false) => trim((string) $xp->evaluate('string(//head/meta[@'.($property ? 'property' : 'name').'="'.$name.'"]/@content)'));
        $title = trim((string) $xp->evaluate('string(//head/title)'));
        $description = $meta('description');
        $canonical = trim((string) $xp->evaluate('string(//head/link[@rel="canonical"]/@href)'));
        $noindex = str_contains(strtolower($meta('robots')), 'noindex');
        $checks = [];
        $add = function ($id, $category, $weight, $passed, $message, $fix = null, $severity = 'important') use (&$checks) {
            $checks[] = ['id' => $id, 'category' => $category, 'weight' => $weight, 'earned' => $passed ? $weight : 0, 'status' => $passed ? 'passed' : $severity, 'message' => $message, 'fixType' => $passed ? null : $fix];
        };
        $add('title', 'Metadata', 18, $title !== '', $title !== '' ? 'Search title is present.' : 'Add a descriptive search title.', 'title', 'critical');
        $add('description', 'Metadata', 12, $description !== '', $description !== '' ? 'Meta description is present.' : 'Add a description that accurately summarizes this page.', 'description');
        $headings = $xp->query('//body//h1|//body//h2|//body//h3|//body//h4|//body//h5|//body//h6');
        $h1 = 0;
        $logical = true;
        $level = 0;
        foreach ($headings as $heading) {
            $next = (int) substr($heading->nodeName, 1);
            if ($next === 1) {
                $h1++;
            } if ($level > 0 && $next > $level + 1) {
                $logical = false;
            } $level = $next;
        }
        $add('h1', 'Structure', 15, $h1 === 1, $h1 === 1 ? 'One primary heading is present.' : "Found {$h1} primary headings. Review the page’s heading structure.", null);
        $add('hierarchy', 'Structure', 10, $logical, $logical ? 'Heading levels do not skip a level.' : 'Review skipped heading levels; do not change headings just for appearance.');
        $validCanonical = filter_var($canonical, FILTER_VALIDATE_URL) && in_array(parse_url($canonical, PHP_URL_SCHEME), ['http', 'https'], true);
        $add('canonical', 'Technical', 15, (bool) $validCanonical, $validCanonical ? 'Canonical URL is present.' : 'Configure a site URL or a valid canonical URL.', null, 'critical');
        $add('viewport', 'Technical', 5, $meta('viewport') !== '', $meta('viewport') !== '' ? 'Mobile viewport metadata is present.' : 'Mobile viewport metadata is missing.');
        $missingAlt = 0;
        foreach ($xp->query('//body//img') as $image) {
            if (! $image->hasAttribute('alt')) {
                $missingAlt++;
            }
        }
        $add('alt', 'Images', 10, $missingAlt === 0, $missingAlt === 0 ? 'Images declare alt text; empty alt is valid for decorative images.' : "{$missingAlt} images have no alt attribute. Review them in the builder.", 'alt');
        $broken = [];
        $placeholders = 0;
        $internal = 0;
        foreach ($xp->query('//body//a[@href]') as $link) {
            $href = trim($link->getAttribute('href'));
            if ($href === '' || $href === '#') {
                $placeholders++;

                continue;
            }
            $local = str_starts_with($href, '/') && ! str_starts_with($href, '//');
            if ($origin !== '' && str_starts_with($href, $origin.'/')) {
                $href = substr($href, strlen($origin));
                $local = true;
            }
            if ($local) {
                $internal++;
                $path = parse_url($href, PHP_URL_PATH);
                if ($knownPaths !== [] && ! in_array($path, $knownPaths, true) && ! str_starts_with($path, '/media/')) {
                    $broken[] = $path;
                }
            }
        }
        $add('links', 'Links', 10, $broken === [] && $placeholders === 0, $broken !== [] ? 'Review internal links with no live page: '.implode(', ', array_slice(array_unique($broken), 0, 5)) : ($placeholders > 0 ? "{$placeholders} links still use a placeholder destination." : 'No unresolved internal destinations were detected.'), null);
        $social = $meta('og:title', true) !== '' && $meta('twitter:title') !== '';
        $add('social', 'Social', 5, $social, $social ? 'Social title defaults are present.' : 'Social title metadata is missing.', 'socialTitle');
        foreach ([['title-length', $title, 20, 70, 'Search title'], ['description-length', $description, 50, 180, 'Meta description']] as [$id, $text, $min, $max, $label]) {
            if ($text !== '' && (mb_strlen($text) < $min || mb_strlen($text) > $max)) {
                $add($id, 'Metadata', 0, false, "{$label} is unusually short or long. This is display guidance, not a search engine limit.", $id === 'title-length' ? 'title' : 'description', 'suggestion');
            }
        }
        if ($noindex) {
            $add('indexing', 'Technical', 0, false, 'This page deliberately asks search engines not to index it. Confirm that this is intended.', null, 'notice');
        }
        if ($internal === 0) {
            $add('internal-links', 'Links', 0, false, 'Consider a useful link to another relevant page; only if it helps visitors.', null, 'suggestion');
        }
        $schemas = $xp->query('//script[@type="application/ld+json"]');
        $schemaValid = true;
        foreach ($schemas as $script) {
            $data = json_decode($script->textContent, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data) || ! isset($data['@context']) || (! isset($data['@type']) && ! isset($data['@graph']))) {
                $schemaValid = false;
            }
        }
        $add('schema', 'Technical', 0, $schemas->length > 0 && $schemaValid, $schemas->length === 0 ? 'No structured data is declared. Auto can add a basic page description.' : ($schemaValid ? 'Structured data is valid JSON with a declared type. Eligibility still needs external verification.' : 'Structured data is malformed; repair the JSON before publishing.'), null, $schemaValid ? 'suggestion' : 'critical');
        $topic = trim($seo['focusTopic'] ?? '');
        if ($topic !== '') {
            $body = trim((string) $xp->evaluate('string(//body)'));
            $present = mb_stripos($title.' '.$description.' '.$body, $topic) !== false;
            $add('topic', 'Structure', 0, $present, $present ? 'The focus topic appears in the page text. Use it naturally.' : 'Review whether this page addresses the focus topic; related language may be equally appropriate.', null, 'suggestion');
        }
        if (in_array($seo['schemaType'] ?? 'Auto', ['Article', 'BlogPosting'], true) && ($seo['pageType'] ?? 'standard') !== 'article') {
            $add('schema-purpose', 'Technical', 0, false, 'Article schema is selected on a non-article page. Confirm that the content is actually an article.', null, 'suggestion');
        }
        $score = array_sum(array_column($checks, 'earned'));
        $categories = [];
        foreach ($checks as $check) {
            $c = $check['category'];
            $categories[$c] ??= ['earned' => 0, 'possible' => 0];
            $categories[$c]['earned'] += $check['earned'];
            $categories[$c]['possible'] += $check['weight'];
        }

        return ['score' => $score, 'label' => $score >= 85 ? 'Excellent' : ($score >= 70 ? 'Good' : ($score >= 50 ? 'Needs improvement' : 'Needs attention')), 'checks' => $checks, 'categories' => $categories, 'title' => $title, 'description' => $description, 'canonical' => $canonical, 'noindex' => $noindex, 'nofollow' => str_contains(strtolower($meta('robots')), 'nofollow'), 'h1' => $h1, 'brokenLinks' => array_values(array_unique($broken)), 'socialTitle' => $meta('og:title', true), 'socialDescription' => $meta('og:description', true), 'socialImage' => $meta('og:image', true), 'schemaPresent' => $xp->query('//script[@type="application/ld+json"]')->length > 0];
    }
}
