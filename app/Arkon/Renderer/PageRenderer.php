<?php

namespace App\Arkon\Renderer;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\Render\RenderContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Text;

/**
 * The one renderer. Publishing, preview and the editor canvas all use it; only
 * the mode differs. Editor mode adds `data-ak-*` annotations, which production
 * output never contains.
 *
 * Every node is rendered with its own component version (manifest, renderer
 * class and stylesheet), so a stored revision renders the same today as when it
 * was published. Which versions a document may contain is a validation policy:
 * new work must be at current versions; reproduction accepts each node's own.
 */
final class PageRenderer
{
    /**
     * The renderer version new output is produced with, recorded with each
     * publication. Any change to the output for the same inputs (serializer, head,
     * base stylesheet) needs a new version, with the old behaviour kept selectable
     * here so recorded publications can still be reproduced.
     */
    public const VERSION = 'arkon-php-1';

    /** Renderer versions this code can still produce, with their base stylesheet. */
    private const BASE_CSS = ['arkon-php-1' => BaseCss::CSS];

    /** Attribute prefix reserved for editor annotations. */
    public const EDITOR_ATTR_PREFIX = 'data-ak-';

    public function __construct(private readonly ComponentRegistry $registry, private readonly DocumentValidator $validator) {}

    /**
     * @param  array  $document  raw JSON form
     * @param  'production'|'editor'  $mode
     * @param  array{title: string, path: string}  $page
     * @param  array{name: string, lang?: string}  $site
     * @param  array<string, array{id: string, url: string, width: int, height: int, mime: string}>  $media
     * @param  bool  $strict  fail when the document has publish-blocking issues (publishing)
     * @param  bool  $pinned  accept each node's own component version (reproduction, re-render) instead of requiring current ones
     * @return array{html: string, body: string, css: string, title: string, inputs: array, report: array}
     *
     * @throws RenderException
     */
    public function render(mixed $document, string $mode, array $page, array $site, array $media, bool $strict = false, bool $pinned = false, string $rendererVersion = self::VERSION): array
    {
        $baseCss = self::BASE_CSS[$rendererVersion] ?? throw new RenderException("Renderer version {$rendererVersion} is not available", [['message' => "Unknown renderer version {$rendererVersion}"]]);
        $invalid = $pinned ? $this->validator->validatePinned($document) : $this->validator->validate($document);
        if ($invalid !== []) {
            throw new RenderException('Document is invalid', $invalid);
        }
        $warnings = $this->validator->publishIssues($document);
        if ($strict && $warnings !== []) {
            throw new RenderException('Document is not ready to publish', $warnings);
        }

        $nodes = Json::entries($document['nodes']);
        $rootChildren = $nodes[$document['root']]['children'] ?? [];
        $usedComponents = [];
        $usedMedia = [];

        $renderNode = function (string $id) use (&$renderNode, &$usedComponents, &$usedMedia, $nodes, $rootChildren, $mode, $media): Element {
            $node = $nodes[$id];
            $definition = $this->validator->definitionOf($node);
            $usedComponents["{$definition->type}@{$definition->version}"] = $definition;
            $children = array_map(fn ($child) => $renderNode((string) $child), $node['children'] ?? []);
            $index = array_search($id, $rootChildren, true);
            $element = $this->registry->renderer($definition->type, $definition->version)->render(new RenderContext(
                node: $node,
                props: $definition->props->parseValid($node['props']),
                children: $children,
                mode: $mode,
                sectionIndex: $index === false ? -1 : $index,
                media: function (string $assetId) use (&$usedMedia, $media) {
                    if (isset($media[$assetId])) {
                        $usedMedia[$assetId] = $media[$assetId];
                    }

                    return $media[$assetId] ?? null;
                },
            ));
            if ($mode === 'editor') {
                $element->attrs = [...$element->attrs, 'data-ak-id' => $node['id'], 'data-ak-type' => $node['type']];
            }

            return $element;
        };

        $tree = $renderNode((string) $document['root']);
        $body = Serializer::serialize($tree);
        $css = BaseCss::minify(implode("\n", [$baseCss, ...array_map(fn ($definition) => $definition->css, array_values($usedComponents))]));

        $seo = Json::entries($document['seo']);
        $seoTitle = is_string($seo['title'] ?? null) ? Text::trim($seo['title']) : '';
        $title = $seoTitle !== '' ? $seoTitle : "{$page['title']} · {$site['name']}";
        $description = is_string($seo['description'] ?? null) ? Text::trim($seo['description']) : '';
        $head = implode('', [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width,initial-scale=1">',
            '<title>'.Serializer::escapeText($title).'</title>',
            $description !== '' ? '<meta name="description" content="'.Serializer::escapeAttr($description).'">' : '',
            ($seo['noindex'] ?? false) === true ? '<meta name="robots" content="noindex">' : '',
            "<style>{$css}</style>",
        ]);
        $lang = Serializer::escapeAttr($site['lang'] ?? 'en');
        $html = "<!doctype html><html lang=\"{$lang}\"><head>{$head}</head><body>{$body}</body></html>";

        ksort($usedMedia);
        $components = array_keys($usedComponents);
        sort($components);

        return [
            'html' => $html,
            'body' => $body,
            'css' => $css,
            'title' => $title,
            // Everything besides the revision document that this output depends on.
            'inputs' => [
                'renderer' => $rendererVersion,
                'components' => $components,
                'page' => ['title' => $page['title'], 'path' => $page['path']],
                'site' => ['name' => $site['name'], 'lang' => $site['lang'] ?? 'en'],
                'media' => $usedMedia === [] ? new \stdClass : $usedMedia,
            ],
            'report' => [
                'elements' => Serializer::countElements($tree),
                'cssBytes' => strlen($css),
                'scripts' => 0,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * Renders a publication again from its revision document and recorded
     * inputs: the recorded renderer version, each node's own component version,
     * and the site, page and media metadata as they were. Nothing is read from the
     * current site or current component versions.
     *
     * @param  array  $document  the publication's revision document, exactly as stored
     * @param  array  $inputs  the publication's render_inputs
     *
     * @throws RenderException when the inputs do not describe this document or the renderer version is gone
     */
    public function reproduce(mixed $document, array $inputs): array
    {
        $out = $this->render($document, 'production', $inputs['page'], $inputs['site'], Json::toArray($inputs['media'] ?? []), pinned: true, rendererVersion: (string) ($inputs['renderer'] ?? ''));
        $recorded = $inputs['components'] ?? [];
        sort($recorded);
        if ($out['inputs']['components'] !== $recorded) {
            throw new RenderException('The recorded component versions do not match the revision', [
                ['message' => 'Recorded '.implode(', ', $recorded).'; document uses '.implode(', ', $out['inputs']['components'])],
            ]);
        }

        return $out;
    }
}
