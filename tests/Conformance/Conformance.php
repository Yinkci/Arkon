<?php

namespace Tests\Conformance;

use App\Arkon\Components\DocumentValidator;
use App\Arkon\Schema\OperationException;
use App\Arkon\Schema\Operations;
use App\Arkon\Schema\PagePath;
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
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 2, 'props' => new stdClass, 'children' => ['hero0001']],
                'hero0001' => ['id' => 'hero0001', 'type' => 'hero', 'version' => 1, 'props' => $heroProps === [] ? new stdClass : $heroProps],
            ],
            'seo' => new stdClass,
        ];

        return Json::encode(array_replace_recursive($doc, $overrides));
    }

    /**
     * A page with every new component: text, image and button at the top level, and
     * two columns holding text, image and button. `$change` edits the raw array first.
     */
    private static function layout(?callable $change = null): string
    {
        $o = new stdClass;
        $doc = [
            'schemaVersion' => 1,
            'root' => 'root0001',
            'nodes' => [
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 2, 'props' => $o, 'children' => ['text0001', 'imag0001', 'butn0001', 'cols0001']],
                'text0001' => ['id' => 'text0001', 'type' => 'text', 'version' => 1, 'props' => ['text' => "Line one\nLine two", 'element' => 'h2', 'align' => 'center']],
                'imag0001' => ['id' => 'imag0001', 'type' => 'image', 'version' => 1, 'props' => ['image' => ['assetId' => self::UUID, 'alt' => 'A phone'], 'caption' => 'Caption', 'size' => 'medium']],
                'butn0001' => ['id' => 'butn0001', 'type' => 'button', 'version' => 1, 'props' => ['label' => 'Contact', 'href' => '/contact', 'style' => 'secondary', 'newTab' => true]],
                'cols0001' => ['id' => 'cols0001', 'type' => 'columns', 'version' => 1, 'props' => ['stackOn' => 'tablet', 'gap' => 'large'], 'children' => ['colu0001', 'colu0002']],
                'colu0001' => ['id' => 'colu0001', 'type' => 'column', 'version' => 1, 'props' => $o, 'children' => ['text0002']],
                'colu0002' => ['id' => 'colu0002', 'type' => 'column', 'version' => 1, 'props' => $o, 'children' => ['imag0002', 'butn0002']],
                'text0002' => ['id' => 'text0002', 'type' => 'text', 'version' => 1, 'props' => ['text' => 'In a column']],
                'imag0002' => ['id' => 'imag0002', 'type' => 'image', 'version' => 1, 'props' => ['image' => ['assetId' => '01890a5d-ac96-774b-bcce-b302099a8058', 'alt' => 'Second']]],
                'butn0002' => ['id' => 'butn0002', 'type' => 'button', 'version' => 1, 'props' => ['label' => 'Go', 'href' => 'https://example.com/']],
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
        $nodes = ['root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 2, 'props' => new stdClass, 'children' => []]];
        foreach (array_values($hrefs) as $i => $href) {
            $id = sprintf('butn%04d', $i);
            $nodes['root0001']['children'][] = $id;
            $nodes[$id] = ['id' => $id, 'type' => 'button', 'version' => 1, 'props' => ['label' => 'Go', 'href' => $href]];
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

    /** @return array{documents: list<array>, operations: list<array>, paths: list<array>} */
    public static function cases(): array
    {
        $two = Json::decode(self::doc());
        $two['nodes']['root0001']['children'][] = 'hero0002';
        $two['nodes']['hero0002'] = ['id' => 'hero0002', 'type' => 'hero', 'version' => 1, 'props' => ['heading' => 'Second']];
        $two = Json::encode($two);
        $many = Json::decode(self::doc());
        foreach (range(1, 50) as $i) {
            $id = sprintf('extra%03d', $i);
            $many['nodes']['root0001']['children'][] = $id;
            $many['nodes'][$id] = ['id' => $id, 'type' => 'hero', 'version' => 1, 'props' => ['heading' => "H{$i}"]];
        }

        $documents = [
            'valid' => self::doc(),
            'valid: defaults omitted' => self::doc(['heading' => 'Hi']),
            'valid: image' => self::doc(['heading' => 'Hi', 'image' => ['assetId' => self::UUID, 'alt' => 'A phone']]),
            'valid: numeric-looking node ids' => '{"schemaVersion":1,"root":"1000","nodes":{"1000":{"id":"1000","type":"page","version":2,"props":{},"children":["2000"]},"2000":{"id":"2000","type":"hero","version":1,"props":{"heading":"x"}}},"seo":{}}',
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
            'invalid: node version 1.5' => str_replace('"type":"hero","version":1', '"type":"hero","version":1.5', self::doc()),
            'invalid: unknown prop' => self::doc(['heading' => 'x', 'onclick' => 'alert(1)']),
            'invalid: enum' => self::doc(['heading' => 'x', 'headingLevel' => 'h3']),
            'invalid: image uuid' => self::doc(['heading' => 'x', 'image' => ['assetId' => 'not-a-uuid', 'alt' => '']]),
            'invalid: image without alt' => self::doc(['heading' => 'x', 'image' => ['assetId' => self::UUID]]),
            'invalid: image empty object' => str_replace('"image":null', '"image":{}', self::doc()),
            'invalid: image empty list' => str_replace('"image":null', '"image":[]', self::doc()),
            'invalid: heading missing' => self::doc(['text' => 'x']),
            'invalid: heading number' => self::doc(['heading' => 5]),
            'invalid: hero with children' => self::doc(overrides: ['nodes' => ['hero0001' => ['children' => []]]]),
            'invalid: page without children' => str_replace(',"children":["hero0001"]', '', self::doc()),
            'invalid: unknown component' => self::doc(overrides: ['nodes' => ['hero0001' => ['type' => 'carousel']]]),
            'invalid: unsupported component version' => self::doc(overrides: ['nodes' => ['hero0001' => ['version' => 2]]]),
            'invalid: root is not a page' => self::doc(overrides: ['root' => 'hero0001']),
            'invalid: detached node' => str_replace('"children":["hero0001"]', '"children":[]', self::doc()),
            'invalid: shared child' => self::doc(overrides: ['nodes' => ['root0001' => ['children' => ['hero0001', 'hero0001']]]]),
            'invalid: missing child' => self::doc(overrides: ['nodes' => ['root0001' => ['children' => ['hero0001', 'gone0001']]]]),
            'invalid: key does not match id' => self::doc(overrides: ['nodes' => ['hero0001' => ['id' => 'other001']]]),
            'invalid: bad node id key' => '{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":2,"props":{},"children":[]},"a b":{"id":"a b","type":"hero","version":1,"props":{"heading":"x"}}},"seo":{}}',
            'invalid: seo types' => self::doc(overrides: ['seo' => ['title' => 5, 'noindex' => 'yes', 'keywords' => 'x']]),
            'invalid: seo title too long' => self::doc(overrides: ['seo' => ['title' => str_repeat('t', 121)]]),
            'invalid: page props not empty' => str_replace('"props":{}', '"props":{"x":1}', self::doc()),
            'invalid: editor metadata' => self::doc(overrides: ['nodes' => ['hero0001' => ['editor' => ['name' => str_repeat('n', 81), 'locked' => true]]]]),
            'invalid: 51 children' => Json::encode($many),
            'publish: blank heading (NBSP)' => self::doc(['heading' => "\u{00A0} \u{2003}"]),
            'publish: image without alt text' => self::doc(['heading' => 'x', 'image' => ['assetId' => self::UUID, 'alt' => ' ']]),
            'valid: two heroes' => $two,
            'invalid: page v1 under editing rules' => str_replace('"type":"page","version":2', '"type":"page","version":1', self::doc()),
            'valid: text, image, button and columns' => self::layout(),
            'valid: button links' => self::buttons(['/', '/contact?x=1#y', '#top', '?q=1', 'https://example.com/a', 'http://a.b', 'mailto:a@b.c', 'tel:+1(234)5-6', '']),
            'invalid: unsafe button links' => self::buttons(['javascript:alert(1)', 'JavaScript:x', '//evil.example', 'data:text/html,x', ' /x', '/x y', 'https://', 'ftp://x', "/x\u{00A0}y", "/x\u{FEFF}y", "/caf\u{E9}"]),
            'invalid: column directly in page' => self::layout(fn (&$d) => $d['nodes']['root0001']['children'][] = 'colu0001'),
            'invalid: columns inside a column' => self::layout(function (&$d) {
                $d['nodes']['colu0001']['children'][] = 'cols0002';
                $d['nodes']['cols0002'] = ['id' => 'cols0002', 'type' => 'columns', 'version' => 1, 'props' => new stdClass, 'children' => ['colu0003']];
                $d['nodes']['colu0003'] = ['id' => 'colu0003', 'type' => 'column', 'version' => 1, 'props' => new stdClass, 'children' => []];
            }),
            'invalid: hero inside a column' => self::layout(function (&$d) {
                $d['nodes']['colu0001']['children'][] = 'hero0009';
                $d['nodes']['hero0009'] = ['id' => 'hero0009', 'type' => 'hero', 'version' => 1, 'props' => ['heading' => 'x']];
            }),
            'invalid: text inside columns (not a column)' => self::layout(function (&$d) {
                $d['nodes']['cols0001']['children'][] = 'text0009';
                $d['nodes']['text0009'] = ['id' => 'text0009', 'type' => 'text', 'version' => 1, 'props' => ['text' => 'x']];
            }),
            'invalid: columns without columns' => self::layout(fn (&$d) => [$d['nodes']['cols0001']['children'] = [], $d['nodes']['root0001']['children'] = array_values(array_diff($d['nodes']['root0001']['children'], [])), $d['nodes']['colu0001']['children'] = [], $d['nodes']['colu0002']['children'] = []] && self::detach($d, ['colu0001', 'colu0002', 'text0002', 'imag0002', 'butn0002'])),
            'invalid: five columns' => self::layout(function (&$d) {
                foreach (['colu0005', 'colu0006', 'colu0007'] as $id) {
                    $d['nodes']['cols0001']['children'][] = $id;
                    $d['nodes'][$id] = ['id' => $id, 'type' => 'column', 'version' => 1, 'props' => new stdClass, 'children' => []];
                }
            }),
            'invalid: text with children' => self::layout(fn (&$d) => $d['nodes']['text0001']['children'] = []),
            'invalid: new component props' => self::layout(function (&$d) {
                $d['nodes']['text0001']['props'] = ['text' => 5, 'element' => 'h1', 'align' => 'left'];
                $d['nodes']['butn0001']['props'] = ['label' => str_repeat('b', 81), 'style' => 'ghost', 'newTab' => 'yes'];
                $d['nodes']['imag0001']['props'] = ['image' => ['assetId' => 'x'], 'size' => 'huge'];
            }),
            'publish: empty blocks' => self::layout(function (&$d) {
                $d['nodes']['text0001']['props'] = ['text' => " \u{00A0}"];
                $d['nodes']['butn0001']['props'] = ['label' => ' ', 'href' => ''];
                $d['nodes']['imag0001']['props'] = ['image' => null];
                $d['nodes']['imag0002']['props'] = ['image' => ['assetId' => self::UUID, 'alt' => '']];
            }),
        ];

        $operations = [
            'updateProps' => [self::doc(), '[{"op":"updateProps","nodeId":"hero0001","set":{"heading":"New","text":"Added"}}]'],
            'updateProps unset' => [self::doc(), '[{"op":"updateProps","nodeId":"hero0001","set":{},"unset":["text"]}]'],
            'updateProps unset everything' => [self::doc(['heading' => 'x']), '[{"op":"updateProps","nodeId":"hero0001","set":{},"unset":["heading"]}]'],
            'updateSeo' => [self::doc(overrides: ['seo' => ['title' => 'Old']]), '[{"op":"updateSeo","set":{"description":"Desc"},"unset":["title"]}]'],
            'updateSeo to empty' => [self::doc(overrides: ['seo' => ['title' => 'Old']]), '[{"op":"updateSeo","set":{},"unset":["title"]}]'],
            'insertNode' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":1,"nodes":[{"id":"newh0001","type":"hero","version":1,"props":{"heading":"N"}}]}]'],
            'insertNode duplicate id' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":0,"nodes":[{"id":"hero0001","type":"hero","version":1,"props":{}}]}]'],
            'insertNode index out of range' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":5,"nodes":[{"id":"newh0001","type":"hero","version":1,"props":{}}]}]'],
            'insertNode into a component without children' => [self::doc(), '[{"op":"insertNode","parentId":"hero0001","index":0,"nodes":[{"id":"newh0001","type":"hero","version":1,"props":{}}]}]'],
            'removeNode' => [$two, '[{"op":"removeNode","nodeId":"hero0001"}]'],
            'removeNode root' => [self::doc(), '[{"op":"removeNode","nodeId":"root0001"}]'],
            'removeNode missing' => [self::doc(), '[{"op":"removeNode","nodeId":"missing1"}]'],
            'moveNode within parent' => [$two, '[{"op":"moveNode","nodeId":"hero0001","parentId":"root0001","index":1}]'],
            'moveNode root' => [$two, '[{"op":"moveNode","nodeId":"root0001","parentId":"root0001","index":0}]'],
            'moveNode out of range' => [$two, '[{"op":"moveNode","nodeId":"hero0001","parentId":"root0001","index":2}]'],
            'sequence' => [$two, '[{"op":"updateProps","nodeId":"hero0002","set":{"heading":"1"}},{"op":"moveNode","nodeId":"hero0002","parentId":"root0001","index":0},{"op":"removeNode","nodeId":"hero0001"},{"op":"updateSeo","set":{"noindex":true}}]'],
            'insert columns subtree' => [self::doc(), '[{"op":"insertNode","parentId":"root0001","index":1,"nodes":[{"id":"cols0009","type":"columns","version":1,"props":{},"children":["colu0009","colu0010"]},{"id":"colu0009","type":"column","version":1,"props":{},"children":[]},{"id":"colu0010","type":"column","version":1,"props":{},"children":[]}]}]'],
            'move text between columns' => [self::layout(), '[{"op":"moveNode","nodeId":"text0002","parentId":"colu0001","index":0}]'],
            'move a column into a column' => [self::layout(), '[{"op":"moveNode","nodeId":"colu0002","parentId":"colu0001","index":0}]'],
            'move columns into its own column' => [self::layout(), '[{"op":"moveNode","nodeId":"cols0001","parentId":"colu0001","index":0}]'],
            'remove columns with content' => [self::layout(), '[{"op":"removeNode","nodeId":"cols0001"}]'],
            'numeric-looking ids' => ['{"schemaVersion":1,"root":"1000","nodes":{"1000":{"id":"1000","type":"page","version":2,"props":{},"children":["2000"]},"2000":{"id":"2000","type":"hero","version":1,"props":{"heading":"x"}}},"seo":{}}', '[{"op":"updateProps","nodeId":"2000","set":{"heading":"y"}}]'],
        ];

        $paths = ['/', '/about', '/services/whatsapp-sales', '/site', '', 'about', '/About', '/a/', '/a--b', '/admin', '/admin/x', '/media', '/login',
            '/logout', '/api/v1', '/preview', '/build', '/_next/x', '/about us', '/é', '/'.str_repeat('a', 200), '//double', '/a//b'];

        return ['documents' => $documents, 'operations' => $operations, 'paths' => $paths];
    }

    public static function evaluate(array $cases): array
    {
        $validator = app(DocumentValidator::class);
        $out = ['documents' => [], 'operations' => [], 'paths' => []];
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
