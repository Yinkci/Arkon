<?php

namespace Tests\Conformance;

use App\Arkon\Components\DocumentValidator;
use App\Arkon\Schema\OperationException;
use App\Arkon\Schema\Operations;
use App\Arkon\Schema\PagePath;
use App\Arkon\Style\Tokens;
use App\Arkon\Support\Json;
use stdClass;

/**
 * Inputs for the PHP/TypeScript conformance fixtures and their evaluation with
 * the PHP implementation. Documents are written as JSON strings so `{}` and `[]`
 * stay distinct, exactly as they arrive from the editor.
 */
final class Conformance
{
    private const UUID = '01890a5d-ac96-774b-bcce-b302099a8057';

    private static function doc(array $heroProps = ['heading' => 'Hello', 'headingLevel' => 'h1', 'text' => '', 'image' => null], array $overrides = []): string
    {
        $doc = [
            'schemaVersion' => 1,
            'root' => 'root0001',
            'nodes' => [
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new stdClass, 'children' => ['hero0001']],
                'hero0001' => ['id' => 'hero0001', 'type' => 'hero', 'version' => 4, 'props' => $heroProps === [] ? new stdClass : $heroProps, 'children' => []],
            ],
            'seo' => new stdClass,
        ];

        return Json::encode(array_replace_recursive($doc, $overrides));
    }

    /** A hero with the given style prop (raw: lists stay lists, `{}` must be written as stdClass). */
    private static function styled(mixed $style, string $type = 'hero', array $props = []): string
    {
        $o = new stdClass;
        $nodes = ['root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => $o, 'children' => ['node0001']]];
        $nodes['node0001'] = match ($type) {
            'hero' => ['id' => 'node0001', 'type' => 'hero', 'version' => 4, 'props' => ['heading' => 'Styled', 'image' => ['assetId' => self::UUID, 'alt' => 'A phone'], ...$props, 'style' => $style], 'children' => []],
            'text' => ['id' => 'node0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Styled', ...$props, 'style' => $style]],
            'image' => ['id' => 'node0001', 'type' => 'image', 'version' => 4, 'props' => ['image' => ['assetId' => self::UUID, 'alt' => 'A phone'], ...$props, 'style' => $style]],
            'group' => ['id' => 'node0001', 'type' => 'group', 'version' => 5, 'props' => [...$props, 'style' => $style], 'children' => []],
        };

        return Json::encode(['schemaVersion' => 1, 'root' => 'root0001', 'nodes' => $nodes, 'seo' => $o]);
    }

    /** One block of `$type` at `$version` with the given style (a section, group or columns get no children). */
    private static function animated(string $type, int $version, array $style): string
    {
        $o = new stdClass;
        $node = ['id' => 'node0001', 'type' => $type, 'version' => $version, 'props' => match ($type) {
            'text' => ['text' => 'Animated', 'style' => $style],
            'image' => ['image' => ['assetId' => self::UUID, 'alt' => 'A phone'], 'style' => $style],
            'button' => ['label' => 'Go', 'href' => '/go', 'style' => $style],
            'hero' => ['heading' => 'Animated', 'style' => $style],
            default => ['style' => $style],
        }];
        $nodes = ['root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => $o, 'children' => ['node0001']], 'node0001' => $node];
        if ($type === 'columns') {
            $nodes['node0001']['children'] = ['colu0001'];
            $nodes['colu0001'] = ['id' => 'colu0001', 'type' => 'column', 'version' => 6, 'props' => ['style' => ['root' => ['base' => ['animation' => 'fade-up', 'animationTrigger' => 'view']]]], 'children' => []];
        } elseif (in_array($type, ['section', 'group', 'hero'], true)) {
            $nodes['node0001']['children'] = [];
        }

        return Json::encode(['schemaVersion' => 1, 'root' => 'root0001', 'nodes' => $nodes, 'seo' => $o]);
    }

    /**
     * A page with every component: section, group, text, image and button at the top level, and
     * two columns holding text, image, button and a nested group. `$change` edits the raw array first.
     */
    private static function layout(?callable $change = null): string
    {
        $o = new stdClass;
        $doc = [
            'schemaVersion' => 1,
            'root' => 'root0001',
            'nodes' => [
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => $o, 'children' => ['text0001', 'imag0001', 'butn0001', 'cols0001', 'sect0001']],
                'text0001' => ['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => "Line one\nLine two", 'element' => 'h2', 'style' => ['root' => ['base' => ['textAlign' => 'center']]]]],
                'imag0001' => ['id' => 'imag0001', 'type' => 'image', 'version' => 4, 'props' => ['image' => ['assetId' => self::UUID, 'alt' => 'A phone'], 'caption' => 'Caption', 'style' => ['root' => ['base' => ['maxWidth' => '48rem']]]]],
                'butn0001' => ['id' => 'butn0001', 'type' => 'button', 'version' => 5, 'props' => ['label' => 'Contact', 'href' => '/contact', 'variant' => 'secondary', 'size' => 'large', 'newTab' => true]],
                'cols0001' => ['id' => 'cols0001', 'type' => 'columns', 'version' => 3, 'props' => ['style' => ['root' => ['base' => ['gap' => '@space.xl', 'columns' => '1fr 2fr'], 'tablet' => ['columns' => '1']]]], 'children' => ['colu0001', 'colu0002']],
                'colu0001' => ['id' => 'colu0001', 'type' => 'column', 'version' => 6, 'props' => $o, 'children' => ['text0002']],
                'colu0002' => ['id' => 'colu0002', 'type' => 'column', 'version' => 6, 'props' => $o, 'children' => ['imag0002', 'butn0002', 'grup0001']],
                'text0002' => ['id' => 'text0002', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'In a column']],
                'imag0002' => ['id' => 'imag0002', 'type' => 'image', 'version' => 4, 'props' => ['image' => ['assetId' => '01890a5d-ac96-774b-bcce-b302099a8058', 'alt' => 'Second']]],
                'butn0002' => ['id' => 'butn0002', 'type' => 'button', 'version' => 5, 'props' => ['label' => 'Go', 'href' => 'https://example.com/']],
                'grup0001' => ['id' => 'grup0001', 'type' => 'group', 'version' => 5, 'props' => ['style' => ['root' => ['base' => ['direction' => 'row', 'gap' => '12px'], 'mobile' => ['direction' => 'column']]]], 'children' => ['text0003']],
                'text0003' => ['id' => 'text0003', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'In a group']],
                'sect0001' => ['id' => 'sect0001', 'type' => 'section', 'version' => 5, 'props' => ['contentWidth' => 'narrow', 'style' => ['root' => ['base' => ['backgroundColor' => '@color.surface', 'backgroundImage' => ['assetId' => '01890a5d-ac96-774b-bcce-b302099a8059'], 'backgroundOverlay' => '#00000080']]]], 'children' => ['text0004']],
                'text0004' => ['id' => 'text0004', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'In a section', 'style' => ['root' => ['base' => ['color' => '#fff']]]]],
            ],
            'seo' => $o,
        ];
        if ($change) {
            $change($doc);
        }

        return Json::encode($doc);
    }

    /** One button per href, in a page. */
    private static function buttons(array $hrefs): string
    {
        $nodes = ['root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new stdClass, 'children' => []]];
        foreach (array_values($hrefs) as $i => $href) {
            $id = sprintf('butn%04d', $i);
            $nodes['root0001']['children'][] = $id;
            $nodes[$id] = ['id' => $id, 'type' => 'button', 'version' => 5, 'props' => ['label' => 'Go', 'href' => $href]];
        }

        return Json::encode(['schemaVersion' => 1, 'root' => 'root0001', 'nodes' => $nodes, 'seo' => new stdClass]);
    }

    /** Groups nested `$depth` deep below the page (the page is level 1). */
    private static function nested(int $depth): string
    {
        $nodes = ['root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new stdClass, 'children' => ['grup0001']]];
        for ($i = 1; $i <= $depth; $i++) {
            $id = sprintf('grup%04d', $i);
            $nodes[$id] = ['id' => $id, 'type' => 'group', 'version' => 5, 'props' => new stdClass, 'children' => $i < $depth ? [sprintf('grup%04d', $i + 1)] : []];
        }

        return Json::encode(['schemaVersion' => 1, 'root' => 'root0001', 'nodes' => $nodes, 'seo' => new stdClass]);
    }

    /** Removes nodes from the map (they are detached from their parents by the caller). */
    private static function detach(array &$doc, array $ids): bool
    {
        foreach ($ids as $id) {
            unset($doc['nodes'][$id]);
        }

        return true;
    }

    /** @return array{documents: list<array>, operations: list<array>, paths: list<array>, tokens: list<string>} */
    public static function cases(): array
    {
        $o = new stdClass;
        $two = Json::decode(self::doc());
        $two['nodes']['root0001']['children'][] = 'hero0002';
        $two['nodes']['hero0002'] = ['id' => 'hero0002', 'type' => 'hero', 'version' => 4, 'props' => ['heading' => 'Second'], 'children' => []];
        $two = Json::encode($two);
        $many = Json::decode(self::doc());
        foreach (range(1, 50) as $i) {
            $id = sprintf('extra%03d', $i);
            $many['nodes']['root0001']['children'][] = $id;
            $many['nodes'][$id] = ['id' => $id, 'type' => 'hero', 'version' => 4, 'props' => ['heading' => "H{$i}"], 'children' => []];
        }
        $acceptance = [
            'root' => ['base' => ['direction' => 'row', 'align' => 'center'], 'mobile' => ['direction' => 'column', 'align' => 'stretch']],
            'media' => ['base' => ['height' => '500px', 'objectFit' => 'cover', 'objectPosition' => 'center']],
        ];

        $documents = [
            'valid' => self::doc(),
            'valid: contact form reference' => self::doc(overrides: ['nodes' => ['root0001' => ['children' => ['hero0001', 'form0001']], 'form0001' => ['id' => 'form0001', 'type' => 'form', 'version' => 2, 'props' => ['form' => ['id' => self::UUID]]]]]),
            'valid: unconfigured contact form draft' => self::doc(overrides: ['nodes' => ['root0001' => ['children' => ['hero0001', 'form0001']], 'form0001' => ['id' => 'form0001', 'type' => 'form', 'version' => 2, 'props' => new stdClass]]]),
            'invalid: contact form reference uuid' => self::doc(overrides: ['nodes' => ['root0001' => ['children' => ['hero0001', 'form0001']], 'form0001' => ['id' => 'form0001', 'type' => 'form', 'version' => 2, 'props' => ['form' => ['id' => 'foreign-string']]]]]),
            'valid: gradient and hover' => self::styled(['root' => ['base' => ['backgroundGradient' => '90deg #102030ff #10203000', 'hoverColor' => '#ffffff', 'position' => 'sticky', 'top' => '0px', 'zIndex' => '10']]], 'group'),
            'valid: clear gradient' => self::styled(['root' => ['base' => ['backgroundGradient' => 'none']]], 'group'),
            'invalid: gradient css injection' => self::styled(['root' => ['base' => ['backgroundGradient' => '90deg #102030 #ffffff;url(https://bad.test)']]], 'group'),
            'invalid: unbounded z index' => self::styled(['root' => ['base' => ['zIndex' => '9999']]], 'group'),
            'valid: defaults omitted' => self::doc(['heading' => 'Hi']),
            'valid: image' => self::doc(['heading' => 'Hi', 'image' => ['assetId' => self::UUID, 'alt' => 'A phone']]),
            'valid: numeric-looking node ids' => '{"schemaVersion":1,"root":"1000","nodes":{"1000":{"id":"1000","type":"page","version":6,"props":{},"children":["2000"]},"2000":{"id":"2000","type":"hero","version":4,"props":{"heading":"x"},"children":[]}},"seo":{}}',
            'valid: schemaVersion written as 1.0' => str_replace('"schemaVersion":1', '"schemaVersion":1.0', self::doc()),
            'valid: 80 emoji is 160 UTF-16 units' => self::doc(['heading' => str_repeat('😀', 80)]),
            'invalid: 81 emoji is 162 UTF-16 units' => self::doc(['heading' => str_repeat('😀', 81)]),
            'invalid: heading 161 chars' => self::doc(['heading' => str_repeat('x', 161)]),
            'invalid: not an object' => '"<h1>hi</h1>"',
            'invalid: a list' => '[]',
            'invalid: props is a list' => str_replace('"props":{}', '"props":[]', self::doc()),
            'invalid: seo is a list' => str_replace('"seo":{}', '"seo":[]', self::doc()),
            'invalid: nodes is a list' => '{"schemaVersion":1,"root":"root0001","nodes":[],"seo":{}}',
            'invalid: unknown document key' => self::doc(overrides: ['foo' => 1]),
            'invalid: schema version 2' => self::doc(overrides: ['schemaVersion' => 2]),
            'invalid: node version 4.5' => str_replace('"type":"hero","version":4', '"type":"hero","version":4.5', self::doc()),
            'invalid: unknown prop' => self::doc(['heading' => 'x', 'onclick' => 'alert(1)']),
            'invalid: enum' => self::doc(['heading' => 'x', 'headingLevel' => 'h3']),
            'invalid: image uuid' => self::doc(['heading' => 'x', 'image' => ['assetId' => 'not-a-uuid', 'alt' => '']]),
            'invalid: image without alt' => self::doc(['heading' => 'x', 'image' => ['assetId' => self::UUID]]),
            'invalid: image empty object' => str_replace('"image":null', '"image":{}', self::doc()),
            'invalid: image empty list' => str_replace('"image":null', '"image":[]', self::doc()),
            'invalid: heading missing' => self::doc(['text' => 'x']),
            'invalid: heading number' => self::doc(['heading' => 5]),
            'invalid: hero without its children list' => str_replace(',"children":[]', '', self::doc()),
            'invalid: text inside a hero' => self::doc(overrides: ['nodes' => ['hero0001' => ['children' => ['text0001']], 'text0001' => ['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'x']]]]),
            'invalid: page without children' => str_replace(',"children":["hero0001"]', '', self::doc()),
            'invalid: unknown component' => self::doc(overrides: ['nodes' => ['hero0001' => ['type' => 'carousel']]]),
            'invalid: unsupported component version' => self::doc(overrides: ['nodes' => ['hero0001' => ['version' => 9]]]),
            'invalid: root is not a page' => self::doc(overrides: ['root' => 'hero0001']),
            'invalid: detached node' => str_replace('"children":["hero0001"]', '"children":[]', self::doc()),
            'invalid: shared child' => self::doc(overrides: ['nodes' => ['root0001' => ['children' => ['hero0001', 'hero0001']]]]),
            'invalid: missing child' => self::doc(overrides: ['nodes' => ['root0001' => ['children' => ['hero0001', 'gone0001']]]]),
            'invalid: key does not match id' => self::doc(overrides: ['nodes' => ['hero0001' => ['id' => 'other001']]]),
            'invalid: bad node id key' => '{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":6,"props":{},"children":[]},"a b":{"id":"a b","type":"hero","version":4,"props":{"heading":"x"},"children":[]}},"seo":{}}',
            'invalid: seo types' => self::doc(overrides: ['seo' => ['title' => 5, 'noindex' => 'yes', 'keywords' => 'x']]),
            'invalid: seo title too long' => self::doc(overrides: ['seo' => ['title' => str_repeat('t', 121)]]),
            'invalid: page props unknown' => str_replace('"props":{}', '"props":{"x":1}', self::doc()),
            'invalid: editor metadata' => self::doc(overrides: ['nodes' => ['hero0001' => ['editor' => ['name' => str_repeat('n', 81), 'locked' => true]]]]),
            'invalid: 51 children' => Json::encode($many),
            'publish: blank heading (NBSP)' => self::doc(['heading' => "\u{00A0} \u{2003}"]),
            'publish: image without alt text' => self::doc(['heading' => 'x', 'image' => ['assetId' => self::UUID, 'alt' => ' ']]),
            'valid: two heroes' => $two,
            'invalid: page v2 under editing rules' => str_replace('"type":"page","version":6', '"type":"page","version":2', self::doc()),
            'invalid: hero v1 under editing rules' => str_replace(['"type":"hero","version":4', ',"children":[]'], ['"type":"hero","version":1', ''], self::doc()),
            'valid: every component' => self::layout(),
            'invalid: image v3 under editing rules' => str_replace('"type":"image","version":4', '"type":"image","version":3', self::layout()),
            'valid: button links' => self::buttons(['/', '/contact?x=1#y', '#top', '?q=1', 'https://example.com/a', 'http://a.b', 'mailto:a@b.c', 'tel:+1(234)5-6', '']),
            'invalid: unsafe button links' => self::buttons(['javascript:alert(1)', 'JavaScript:x', '//evil.example', 'data:text/html,x', ' /x', '/x y', 'https://', 'ftp://x', "/x\u{00A0}y", "/x\u{FEFF}y", "/caf\u{E9}"]),
            // Browsers treat \ as / in http(s) URLs: /\host and /\\host lead to another site.
            'invalid: backslash links' => self::buttons(['/\\example.com', '/\\\\example.com', '/\\/example.com', '/a\\b', '#\\x', '?\\x', 'https://\\evil.example', 'http://a.b\\c', 'mailto:a\\b@c.d']),
            'valid: links with brackets and percent-encoded backslash' => self::buttons(['/a[1]', '/x]y', '/%5Cexample.com', '/a?b=%5C']),
            'invalid: column directly in page' => self::layout(fn (&$d) => $d['nodes']['root0001']['children'][] = 'colu0001'),
            'valid: columns inside a column' => self::layout(function (&$d) {
                $d['nodes']['colu0001']['children'][] = 'cols0002';
                $d['nodes']['cols0002'] = ['id' => 'cols0002', 'type' => 'columns', 'version' => 3, 'props' => new stdClass, 'children' => ['colu0003']];
                $d['nodes']['colu0003'] = ['id' => 'colu0003', 'type' => 'column', 'version' => 6, 'props' => new stdClass, 'children' => []];
            }),
            'invalid: hero inside a column' => self::layout(function (&$d) {
                $d['nodes']['colu0001']['children'][] = 'hero0009';
                $d['nodes']['hero0009'] = ['id' => 'hero0009', 'type' => 'hero', 'version' => 4, 'props' => ['heading' => 'x'], 'children' => []];
            }),
            'invalid: section inside a group' => self::layout(function (&$d) {
                $d['nodes']['grup0001']['children'][] = 'sect0009';
                $d['nodes']['sect0009'] = ['id' => 'sect0009', 'type' => 'section', 'version' => 5, 'props' => new stdClass, 'children' => []];
            }),
            'invalid: text inside columns (not a column)' => self::layout(function (&$d) {
                $d['nodes']['cols0001']['children'][] = 'text0009';
                $d['nodes']['text0009'] = ['id' => 'text0009', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'x']];
            }),
            'invalid: columns without columns' => self::layout(fn (&$d) => [$d['nodes']['cols0001']['children'] = [], $d['nodes']['colu0001']['children'] = [], $d['nodes']['colu0002']['children'] = []] && self::detach($d, ['colu0001', 'colu0002', 'text0002', 'imag0002', 'butn0002', 'grup0001', 'text0003'])),
            'invalid: seven columns' => self::layout(function (&$d) {
                foreach (['colu0005', 'colu0006', 'colu0007', 'colu0008', 'colu0009'] as $id) {
                    $d['nodes']['cols0001']['children'][] = $id;
                    $d['nodes'][$id] = ['id' => $id, 'type' => 'column', 'version' => 6, 'props' => new stdClass, 'children' => []];
                }
            }),
            'invalid: text with children' => self::layout(fn (&$d) => $d['nodes']['text0001']['children'] = []),
            'invalid: component props' => self::layout(function (&$d) {
                $d['nodes']['text0001']['props'] = ['text' => 5, 'element' => 'h5', 'align' => 'left'];
                $d['nodes']['butn0001']['props'] = ['label' => str_repeat('b', 81), 'variant' => 'ghost', 'size' => 'huge', 'newTab' => 'yes'];
                $d['nodes']['imag0001']['props'] = ['image' => ['assetId' => 'x'], 'size' => 'full', 'loading' => 'soon'];
                $d['nodes']['sect0001']['props'] = ['element' => 'main', 'contentWidth' => 'huge'];
            }),
            'publish: empty blocks' => self::layout(function (&$d) {
                $d['nodes']['text0001']['props'] = ['text' => " \u{00A0}"];
                $d['nodes']['butn0001']['props'] = ['label' => ' ', 'href' => ''];
                $d['nodes']['imag0001']['props'] = ['image' => null];
                $d['nodes']['imag0002']['props'] = ['image' => ['assetId' => self::UUID, 'alt' => '']];
            }),
            'valid: eight levels deep' => self::nested(7),
            'invalid: nine levels deep' => self::nested(8),
            'valid: a reusable component instance' => '{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":6,"props":{},"children":["inst0001"]},"inst0001":{"id":"inst0001","type":"instance","version":2,"props":{"componentId":"'.self::UUID.'","style":{"root":{"base":{"marginTop":"@space.xl"}}}}}},"seo":{}}',
            'invalid: instance without a component' => '{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":6,"props":{},"children":["inst0001"]},"inst0001":{"id":"inst0001","type":"instance","version":2,"props":{"componentId":"nope","style":{"root":{"base":{"color":"#fff"}}}}}},"seo":{}}',
            'invalid: a fragment inside a page' => '{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":6,"props":{},"children":["frag0001"]},"frag0001":{"id":"frag0001","type":"fragment","version":1,"props":{},"children":[]}},"seo":{}}',

            // The shared styling model.
            'style: the hero acceptance case' => self::styled($acceptance),
            'style: every kind of value' => self::styled([
                'root' => ['base' => [
                    'display' => 'flex', 'direction' => 'row-reverse', 'wrap' => 'wrap', 'justify' => 'space-between', 'align' => 'baseline', 'gap' => '@space.lg',
                    'width' => '100%', 'minWidth' => '0', 'maxWidth' => '@container.wide', 'height' => 'auto', 'minHeight' => '80vh', 'maxHeight' => 'none', 'aspectRatio' => '16/9',
                    'paddingTop' => '2.5rem', 'paddingRight' => '0', 'paddingBottom' => '@space.section', 'paddingLeft' => '12.125px', 'marginTop' => '0', 'marginBottom' => '1em',
                    'marginLeft' => 'auto', 'marginRight' => 'auto', 'backgroundColor' => '#0f766E', 'backgroundImage' => ['assetId' => '01890a5d-ac96-774b-bcce-b302099a8058'],
                    'backgroundOverlay' => '#0008', 'backgroundSize' => 'contain', 'backgroundPosition' => 'top left', 'borderWidth' => '1px', 'borderStyle' => 'dashed',
                    'borderColor' => 'transparent', 'borderRadius' => '@radius.lg', 'boxShadow' => '@shadow.md',
                ], 'tablet' => ['direction' => 'column', 'gap' => '8px'], 'mobile' => ['boxShadow' => 'none', 'paddingTop' => '@space.md']],
                'heading' => ['base' => ['fontFamily' => 'serif', 'fontSize' => '@fontSize.display', 'fontWeight' => '800', 'lineHeight' => '1.05', 'letterSpacing' => '-0.02em', 'textAlign' => 'center', 'color' => '@color.primary'], 'mobile' => ['fontSize' => '2rem', 'letterSpacing' => 'normal']],
                'text' => ['base' => ['maxWidth' => '40ch', 'fontFamily' => '@font.body']],
                'content' => ['base' => ['alignSelf' => 'center', 'textAlign' => 'end']],
                'actions' => ['base' => ['justify' => 'center']],
                'media' => ['base' => ['aspectRatio' => '4/3', 'objectFit' => 'contain', 'objectPosition' => 'bottom right', 'borderRadius' => '50%', 'boxShadow' => 'strong']],
            ]),
            'style: grid columns' => self::styled(['root' => ['base' => ['display' => 'grid', 'columns' => '3'], 'tablet' => ['columns' => '1fr 2fr'], 'mobile' => ['columns' => '1']]], 'group'),
            'style: empty objects' => self::styled(['root' => ['base' => $o, 'mobile' => $o], 'media' => $o]),
            'style: not an object' => self::styled([['slot' => 'root']]),
            'style: string' => self::styled('height:500px'),
            'style: unknown slot, screen and property' => self::styled(['footer' => ['base' => ['color' => '#fff']], 'root' => ['desktop' => ['gap' => '1px'], 'base' => ['transform' => 'rotate(1deg)']]]),
            'style: property not allowed in this slot' => self::styled(['heading' => ['base' => ['height' => '500px']], 'media' => ['base' => ['color' => '#fff']]]),
            'style: screens are objects' => self::styled(['root' => ['base' => [], 'mobile' => 'column']]),
            'style: lengths out of range or in the wrong unit' => self::styled(['root' => ['base' => ['minHeight' => '5000px', 'paddingTop' => '-1px', 'gap' => '10pt', 'width' => '101%', 'maxWidth' => '1e3px', 'height' => '500', 'marginTop' => '.5rem']]]),
            'style: raw CSS is refused' => self::styled(['root' => ['base' => ['backgroundColor' => 'red;position:fixed', 'width' => 'calc(100% - 1px)', 'minHeight' => '10px;', 'height' => ' 500px']], 'media' => ['base' => ['objectFit' => 'cover!important']]]),
            'style: bad colours, numbers and ratios' => self::styled(['heading' => ['base' => ['color' => '#12345', 'lineHeight' => '5', 'fontWeight' => '450', 'letterSpacing' => '3em']], 'media' => ['base' => ['aspectRatio' => '16:9']]]),
            'style: non-string values' => self::styled(['heading' => ['base' => ['fontWeight' => 700, 'lineHeight' => 1.2, 'color' => null]]]),
            'style: unknown and misused tokens' => self::styled(['root' => ['base' => ['backgroundColor' => '@color.brand', 'gap' => '@color.primary', 'paddingTop' => '@space', 'height' => '@space.lg', 'borderRadius' => '@@radius.lg']]]),
            'style: background image only for all screens' => self::styled(['root' => ['base' => ['backgroundColor' => '#fff'], 'mobile' => ['backgroundImage' => ['assetId' => self::UUID], 'backgroundOverlay' => '#000']]]),
            'style: bad background image references' => self::styled(['root' => ['base' => ['backgroundImage' => 'url(/x.png)']], 'content' => ['base' => ['width' => '1px']]]).'',
            'style: background image with extra keys' => self::styled(['root' => ['base' => ['backgroundImage' => ['assetId' => self::UUID, 'url' => '//evil.example/x.png']]]]),
            'style: bad grid columns' => self::styled(['root' => ['base' => ['columns' => '7'], 'tablet' => ['columns' => '0fr 1fr'], 'mobile' => ['columns' => '1fr  1fr']]], 'group'),
            'style: too many column tracks' => self::styled(['root' => ['base' => ['columns' => '1fr 1fr 1fr 1fr 1fr 1fr 1fr']]], 'group'),
            'style: text v2 cannot style an image slot' => self::styled(['media' => ['base' => ['height' => '10px']]], 'text'),
            // Columns: one fraction width per column on every screen; counts are free; Group grids are not concerned.
            'columns: five widths for two columns' => self::layout(function (&$d) {
                $d['nodes']['cols0001']['props']['style']['root']['base']['columns'] = '1fr 1fr 1fr 1fr 1fr';
            }),
            'columns: a third column without a third width' => self::layout(function (&$d) {
                $d['nodes']['cols0001']['children'][] = 'colu0003';
                $d['nodes']['colu0003'] = ['id' => 'colu0003', 'type' => 'column', 'version' => 6, 'props' => new stdClass, 'children' => []];
            }),
            'columns: tablet and mobile widths for the wrong count' => self::layout(function (&$d) {
                $d['nodes']['cols0001']['props']['style']['root']['tablet'] = ['columns' => '1fr 2fr 1fr'];
                $d['nodes']['cols0001']['props']['style']['root']['mobile'] = ['columns' => '3fr'];
            }),
            'columns: counts stay free (stacking, per row, more than columns)' => self::layout(function (&$d) {
                $d['nodes']['cols0001']['props']['style']['root'] = ['base' => ['columns' => '2'], 'tablet' => ['columns' => '4'], 'mobile' => ['columns' => '1']];
            }),
            'columns: a group grid takes any widths' => self::styled(['root' => ['base' => ['display' => 'grid', 'columns' => '1fr 1fr 1fr 1fr 1fr']]], 'group'),

            // Entrance animations (the "motion" group): root slot of the current block versions only.
            'motion: every setting on a section' => self::animated('section', 5, ['root' => ['base' => ['animation' => 'fade-up', 'animationTrigger' => 'view', 'animationDuration' => '800ms', 'animationDelay' => '0ms', 'animationEasing' => 'smooth'], 'tablet' => ['animation' => 'fade'], 'mobile' => ['animation' => 'none']]]),
            'motion: every preset and bound' => self::animated('group', 5, ['root' => ['base' => ['animation' => 'zoom', 'animationTrigger' => 'load', 'animationDuration' => '150ms', 'animationDelay' => '2000ms', 'animationEasing' => 'linear'], 'tablet' => ['animation' => 'fade-left'], 'mobile' => ['animation' => 'fade-right']]]),
            'motion: four-second entrance and maximum delay' => self::animated('section', 5, ['root' => ['base' => ['animation' => 'fade', 'animationTrigger' => 'view', 'animationDuration' => '4000ms', 'animationDelay' => '2000ms']]]),
            'motion: out of range, wrong units and unknown presets' => self::animated('text', 3, ['root' => ['base' => ['animation' => 'spin', 'animationTrigger' => 'scroll', 'animationDuration' => '4001ms', 'animationDelay' => '1s', 'animationEasing' => 'bounce']]]),
            'motion: too short, negative and unitless times' => self::animated('image', 4, ['root' => ['base' => ['animationDuration' => '100ms', 'animationDelay' => '-1ms']], 'media' => ['base' => ['animation' => 'fade']]]),
            'motion: raw CSS and scripts are refused' => self::animated('button', 5, ['root' => ['base' => ['animation' => 'fade-up;opacity:0', 'animationDuration' => 'calc(1s)', 'animationEasing' => 'cubic-bezier(0,0,1,1)', 'animationDelay' => '600']]]),
            'motion: duration, delay, easing and trigger only for all screens' => self::animated('columns', 3, ['root' => ['base' => ['animation' => 'fade'], 'mobile' => ['animationDuration' => '300ms', 'animationTrigger' => 'view', 'animationDelay' => '0ms', 'animationEasing' => 'ease']]]),
            'motion: not on a part, and not on older versions' => self::animated('hero', 4, ['heading' => ['base' => ['animation' => 'fade']], 'root' => ['base' => ['animation' => 'fade-down', 'animationTrigger' => 'view']]]),
            'motion: an older text version cannot animate' => self::animated('text', 2, ['root' => ['base' => ['animation' => 'fade']]]),
            'motion: non-string values' => self::animated('section', 5, ['root' => ['base' => ['animation' => true, 'animationDuration' => 600, 'animationDelay' => null]]]),
            'style: image fit on an image' => self::styled(['media' => ['base' => ['height' => '500px', 'objectFit' => 'cover']], 'caption' => ['base' => ['textAlign' => 'center']]], 'image'),
        ];

        $operations = [
            'updateProps' => [self::doc(), '[{"op":"updateProps","nodeId":"hero0001","set":{"heading":"New","text":"Added"}}]'],
            'updateProps unset' => [self::doc(), '[{"op":"updateProps","nodeId":"hero0001","set":{},"unset":["text"]}]'],
            'updateProps unset everything' => [self::doc(['heading' => 'x']), '[{"op":"updateProps","nodeId":"hero0001","set":{},"unset":["heading"]}]'],
            'updateProps style' => [self::doc(), '[{"op":"updateProps","nodeId":"hero0001","set":{"style":{"root":{"mobile":{"direction":"column"}},"media":{"base":{"height":"500px","objectFit":"cover"}}}}}]'],
            'updateProps invalid style' => [self::doc(), '[{"op":"updateProps","nodeId":"hero0001","set":{"style":{"media":{"base":{"height":"9000px"}}}}}]'],
            'updateSeo' => [self::doc(overrides: ['seo' => ['title' => 'Old']]), '[{"op":"updateSeo","set":{"description":"Desc"},"unset":["title"]}]'],
            'updateSeo to empty' => [self::doc(overrides: ['seo' => ['title' => 'Old']]), '[{"op":"updateSeo","set":{},"unset":["title"]}]'],
            'insertNode' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":1,"nodes":[{"id":"newh0001","type":"hero","version":4,"props":{"heading":"N"},"children":[]}]}]'],
            'insertNode duplicate id' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":0,"nodes":[{"id":"hero0001","type":"hero","version":4,"props":{}}]}]'],
            'insertNode index out of range' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":5,"nodes":[{"id":"newh0001","type":"hero","version":4,"props":{}}]}]'],
            'insertNode into a component without children' => [self::layout(), '[{"op":"insertNode","parentId":"text0001","index":0,"nodes":[{"id":"newt0001","type":"text","version":3,"props":{}}]}]'],
            'insertNode a button into a hero' => [self::doc(), '[{"op":"insertNode","parentId":"hero0001","index":0,"nodes":[{"id":"butn0009","type":"button","version":5,"props":{"label":"Go"}}]}]'],
            'removeNode' => [$two, '[{"op":"removeNode","nodeId":"hero0001"}]'],
            'removeNode root' => [self::doc(), '[{"op":"removeNode","nodeId":"root0001"}]'],
            'removeNode missing' => [self::doc(), '[{"op":"removeNode","nodeId":"missing1"}]'],
            'moveNode within parent' => [$two, '[{"op":"moveNode","nodeId":"hero0001","parentId":"root0001","index":1}]'],
            'moveNode root' => [$two, '[{"op":"moveNode","nodeId":"root0001","parentId":"root0001","index":0}]'],
            'moveNode out of range' => [$two, '[{"op":"moveNode","nodeId":"hero0001","parentId":"root0001","index":2}]'],
            'sequence' => [$two, '[{"op":"updateProps","nodeId":"hero0002","set":{"heading":"1"}},{"op":"moveNode","nodeId":"hero0002","parentId":"root0001","index":0},{"op":"removeNode","nodeId":"hero0001"},{"op":"updateSeo","set":{"noindex":true}}]'],
            'insert columns subtree' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":1,"nodes":[{"id":"cols0009","type":"columns","version":3,"props":{},"children":["colu0009","colu0010"]},{"id":"colu0009","type":"column","version":6,"props":{},"children":[]},{"id":"colu0010","type":"column","version":6,"props":{},"children":[]}]}]'],
            'move text between columns' => [self::layout(), '[{"op":"moveNode","nodeId":"text0002","parentId":"colu0001","index":0}]'],
            'move a group out of a column into a section' => [self::layout(), '[{"op":"moveNode","nodeId":"grup0001","parentId":"sect0001","index":0}]'],
            'move a column into a column' => [self::layout(), '[{"op":"moveNode","nodeId":"colu0002","parentId":"colu0001","index":0}]'],
            'move a column to another block without widths' => [self::layout(function (&$d) {
                $d['nodes']['cols0002'] = ['id' => 'cols0002', 'type' => 'columns', 'version' => 3, 'props' => ['style' => ['root' => ['base' => ['columns' => '1fr 3fr']]]], 'children' => ['colu0008', 'colu0009']];
                $d['nodes']['colu0008'] = ['id' => 'colu0008', 'type' => 'column', 'version' => 6, 'props' => new stdClass, 'children' => []];
                $d['nodes']['colu0009'] = ['id' => 'colu0009', 'type' => 'column', 'version' => 6, 'props' => new stdClass, 'children' => []];
                $d['nodes']['root0001']['children'][] = 'cols0002';
            }), '[{"op":"moveNode","nodeId":"colu0008","parentId":"cols0001","index":2}]'],
            'move columns into its own column' => [self::layout(), '[{"op":"moveNode","nodeId":"cols0001","parentId":"colu0001","index":0}]'],
            'remove columns with content' => [self::layout(), '[{"op":"removeNode","nodeId":"cols0001"}]'],
            'numeric-looking ids' => ['{"schemaVersion":1,"root":"1000","nodes":{"1000":{"id":"1000","type":"page","version":6,"props":{},"children":["2000"]},"2000":{"id":"2000","type":"hero","version":4,"props":{"heading":"x"},"children":[]}},"seo":{}}', '[{"op":"updateProps","nodeId":"2000","set":{"heading":"y"}}]'],
        ];

        $paths = ['/', '/about', '/services/whatsapp-sales', '/site', '', 'about', '/About', '/a/', '/a--b', '/admin', '/admin/x', '/media', '/login',
            '/logout', '/api/v1', '/preview', '/build', '/_next/x', '/about us', '/é', '/'.str_repeat('a', 200), '//double', '/a//b'];

        $tokens = [
            '{}',
            '{"color":{"primary":"#0f766e","background":"#fafaf9"},"font":{"heading":"serif"},"space":{"lg":"3rem"},"radius":{"full":"9999px"},"shadow":{"md":"strong"}}',
            '{"color":{"brand":"#fff"},"motion":{"fast":"100ms"}}',
            '{"color":{"primary":"red"},"fontSize":{"base":"200px"},"space":{"md":"-1rem"},"container":{"default":"@container.wide"},"font":{"body":"Comic Sans"},"shadow":{"sm":"0 0 1px #000"}}',
            '{"color":[],"space":"1rem"}',
            '[]',
            '{"color":{"primary":5}}',
        ];

        foreach (['services', "services\n", '1-services', '', 'services team'] as $anchor) {
            $doc = Json::decode(self::layout());
            $doc['nodes']['sect0001']['props']['anchor'] = $anchor;
            $documents['section anchor '.json_encode($anchor)] = Json::encode($doc);
        }
        $doc = Json::decode(self::layout());
        $doc['nodes']['sect0001']['props']['anchor'] = 'services';
        $doc['nodes']['sect0002'] = [...$doc['nodes']['sect0001'], 'id' => 'sect0002', 'children' => []];
        $doc['nodes']['root0001']['children'][] = 'sect0002';
        $documents['duplicate section anchors'] = Json::encode($doc);

        return ['documents' => $documents, 'operations' => $operations, 'paths' => $paths, 'tokens' => $tokens];
    }

    public static function evaluate(array $cases): array
    {
        $validator = app(DocumentValidator::class);
        $out = ['documents' => [], 'operations' => [], 'paths' => [], 'tokens' => []];
        foreach ($cases['documents'] as $name => $json) {
            $doc = Json::decode($json);
            $issues = $validator->validate($doc);
            $out['documents'][] = [
                'name' => $name,
                'document' => $json,
                'issues' => self::sortIssues($issues),
                'publishIssues' => $issues === [] ? self::sortIssues($validator->publishIssues($doc)) : null,
                'mediaRefs' => $issues === [] ? $validator->mediaRefs($doc) : null,
            ];
        }
        foreach ($cases['operations'] as $name => [$json, $opsJson]) {
            $doc = Json::decode($json);
            $ops = Json::decode($opsJson);
            try {
                $result = Operations::apply($doc, $ops);
                $expected = ['result' => Json::canonical($result['doc']), 'error' => null, 'issues' => self::sortIssues($validator->validate($result['doc']))];
            } catch (OperationException $error) {
                $expected = ['result' => null, 'error' => $error->getMessage(), 'issues' => null];
            }
            $out['operations'][] = ['name' => $name, 'document' => $json, 'operations' => $opsJson, ...$expected];
        }
        foreach ($cases['paths'] as $path) {
            $out['paths'][] = ['path' => $path, 'issues' => PagePath::issues($path)];
        }
        foreach ($cases['tokens'] as $json) {
            [, $issues] = Tokens::parse(Json::decode($json));
            $out['tokens'][] = ['tokens' => $json, 'issues' => self::sortIssues($issues)];
        }

        return $out;
    }

    /** Issue order follows object key order, which PHP and JavaScript differ on for numeric keys; compare as sets. */
    public static function sortIssues(array $issues): array
    {
        $issues = array_map(fn ($i) => array_filter(['nodeId' => $i['nodeId'] ?? null, 'path' => $i['path'] ?? null, 'message' => $i['message']], fn ($v) => $v !== null), $issues);
        usort($issues, fn ($a, $b) => strcmp(Json::canonical($a), Json::canonical($b)));

        return array_values($issues);
    }
}
