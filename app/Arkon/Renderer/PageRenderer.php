<?php

namespace App\Arkon\Renderer;

use App\Arkon\Components\ComponentDefinition;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\Render\RenderContext;
use App\Arkon\Style\StyleSheet;
use App\Arkon\Style\Tokens;
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
     *
     * arkon-php-2: design tokens (`:root` variables for the tokens used, from the
     * site's published token set), generated style classes, reusable components.
     */
    public const VERSION = 'arkon-php-2';

    /** Renderer versions this code can still produce, with their base stylesheet. */
    private const BASE_CSS = ['arkon-php-1' => BaseCss::CSS, 'arkon-php-2' => BaseCss::CSS_V2];

    /**
     * Earlier development builds of a renderer version, kept so the publications they made
     * still reproduce byte for byte. Selectable only for reproduction, and only for a
     * publication with a render compatibility record (publication_render_compat) naming it.
     *
     * arkon-php-2-pre-basis: arkon-php-2 before hero v2's parts used the --ak-basis flex
     * basis (they had a fixed 0% basis) and before direction settings wrote --ak-basis.
     */
    public const COMPAT_BUILDS = [
        'arkon-php-2-pre-basis' => ['renderer' => 'arkon-php-2', 'componentCss' => ['hero@2' => 'hero-v2-pre-basis.css'], 'flexBasis' => false],
    ];

    /** Attribute prefix reserved for editor annotations. */
    public const EDITOR_ATTR_PREFIX = 'data-ak-';

    public function __construct(private readonly ComponentRegistry $registry, private readonly DocumentValidator $validator) {}

    /**
     * @param  array  $document  raw JSON form
     * @param  'production'|'editor'  $mode
     * @param  array{title: string, path: string}  $page
     * @param  array{name: string, lang?: string}  $site
     * @param  array<string, array{id: string, url: string, width: int, height: int, mime: string, variants?: list<array{url: string, width: int}>}>  $media
     * @param  bool  $strict  fail when the document has publish-blocking issues (publishing)
     * @param  bool  $pinned  accept each node's own component version (reproduction, re-render) instead of requiring current ones
     * @param  array{tokens?: array{version: int|null, values: array}, components?: array<string, array{version: int, document: mixed}>}  $resources  the site's published design tokens and the published versions of reusable components the page uses
     * @param  string  $motionRuntime  the animation runtime (CSS and script) used if the page has entrance animations
     * @param  bool  $protectLcp  leave animations off blocks holding the likely LCP (false only for a reusable component's own canvas, which is not a page)
     * @return array{html: string, body: string, css: string, title: string, inputs: array, report: array}
     *
     * @throws RenderException
     */
    public function render(mixed $document, string $mode, array $page, array $site, array $media, bool $strict = false, bool $pinned = false, string $rendererVersion = self::VERSION, array $resources = [], string $motionRuntime = Motion::RUNTIME, bool $protectLcp = true): array
    {
        $motionAssets = Motion::RUNTIMES[$motionRuntime] ?? throw new RenderException("Animation runtime {$motionRuntime} is not available", [['message' => "Unknown animation runtime {$motionRuntime}"]]);
        $build = self::COMPAT_BUILDS[$rendererVersion] ?? null;
        if ($build !== null && ! $pinned) {
            throw new RenderException("Renderer build {$rendererVersion} only reproduces earlier publications", [['message' => "Renderer build {$rendererVersion} is for reproduction only"]]);
        }
        $baseVersion = $build['renderer'] ?? $rendererVersion;
        $baseCss = self::BASE_CSS[$baseVersion] ?? throw new RenderException("Renderer version {$rendererVersion} is not available", [['message' => "Unknown renderer version {$rendererVersion}"]]);
        $legacy = $baseVersion === 'arkon-php-1';
        $componentCss = fn (ComponentDefinition $definition) => isset($build['componentCss']["{$definition->type}@{$definition->version}"])
            ? (string) file_get_contents(resource_path('arkon/compat/'.$build['componentCss']["{$definition->type}@{$definition->version}"]))
            : $definition->css;
        $invalid = $pinned ? $this->validator->validatePinned($document) : $this->validator->validate($document);
        if ($invalid !== []) {
            throw new RenderException('Document is invalid', $invalid);
        }
        $nodes = Json::entries($document['nodes']);
        $components = $resources['components'] ?? [];
        $warnings = $this->validator->publishIssues($document);
        foreach ($nodes as $node) {
            if ($node['type'] === 'instance' && ! isset($components[(string) (Json::entries($node['props'])['componentId'] ?? '')])) {
                $warnings[] = ['nodeId' => $node['id'], 'message' => 'This reusable component has not been published'];
            }
        }
        if ($strict && $warnings !== []) {
            throw new RenderException('Document is not ready to publish', $warnings);
        }

        $usedComponents = [];
        $usedMedia = [];
        $usedReusable = [];
        $mediaLookup = function (string $assetId) use (&$usedMedia, $media) {
            if (isset($media[$assetId])) {
                $usedMedia[$assetId] = $media[$assetId];
            }

            return $media[$assetId] ?? null;
        };
        $styles = $legacy ? null : new StyleSheet(fn (string $assetId) => $mediaLookup($assetId)['url'] ?? null, flexBasis: $build['flexBasis'] ?? true);
        $claimed = false;
        $claimPriority = function () use (&$claimed): bool {
            if ($claimed) {
                return false;
            }

            return $claimed = true;
        };
        // Entrance animations never delay or hide the likely LCP: a block is not animated when it
        // holds (or is) an image fetched with high priority or the page's first h1 heading.
        $priorityImages = 0;
        $notePriority = function () use (&$priorityImages): void {
            $priorityImages++;
        };
        $mainHeading = false;
        // Which node is the likely LCP (its own image, or its h1), so the editor can name it.
        $priorityCauses = [];
        $headingCause = null;
        $motion = ['animated' => 0, 'reveal' => false, 'suppressed' => [], 'protected' => []];

        /**
         * @param  array<string, array>  $tree  the nodes of the document being rendered (page or reusable component)
         * @param  bool  $annotate  editor annotations (only the page's own nodes are selectable)
         */
        $renderNode = function (string $id, array $tree, array $rootChildren, int $top, float $fraction, bool $annotate) use (&$renderNode, &$usedComponents, &$usedReusable, &$priorityImages, &$mainHeading, &$priorityCauses, &$headingCause, &$motion, $mode, $protectLcp, $styles, $mediaLookup, $claimPriority, $notePriority, $components): Element {
            $node = $tree[$id];
            $imagesBefore = $priorityImages;
            $causesBefore = count($priorityCauses);
            $headingBefore = $mainHeading;
            $definition = $this->validator->definitionOf($node);
            $usedComponents["{$definition->type}@{$definition->version}"] = $definition;
            $props = $definition->props->parseValid($node['props']);
            $index = array_search($id, $rootChildren, true);
            $top = $index === false ? $top : $index;
            $childIds = $node['children'] ?? [];
            $childFraction = $node['type'] === 'columns' && count($childIds) > 1 ? $fraction / count($childIds) : $fraction;
            $children = array_map(fn ($child) => $renderNode((string) $child, $tree, $rootChildren, $top, $childFraction, $annotate), $childIds);

            if ($node['type'] === 'instance') {
                $resource = $components[$props['componentId']] ?? null;
                if ($resource !== null) {
                    $usedReusable[$props['componentId']] = (int) $resource['version'];
                    $fragment = $resource['document'];
                    $fragmentNodes = Json::entries($fragment['nodes']);
                    $children = array_map(
                        fn ($child) => $renderNode((string) $child, $fragmentNodes, [], $top, $fraction, false),
                        $fragmentNodes[$fragment['root']]['children'] ?? [],
                    );
                } elseif ($mode === 'editor') {
                    $children = [Element::h('div', ['class' => 'ak-instance__missing', 'data-ak-placeholder' => 'This reusable component has not been published yet'])];
                }
            }

            $imagesBeforeOwn = $priorityImages;
            $element = $this->registry->renderer($definition->type, $definition->version)->render(new RenderContext(
                node: $node,
                props: $props,
                children: $children,
                mode: $mode,
                sectionIndex: $index === false ? -1 : $index,
                media: $mediaLookup,
                styles: $styles,
                topIndex: $top,
                widthFraction: $fraction,
                claimPriority: $claimPriority,
                notePriority: $notePriority,
            ));
            if ($priorityImages > $imagesBeforeOwn) {
                $priorityCauses[] = $node['id'];
            }
            if (! $mainHeading && (($node['type'] === 'text' && ($props['element'] ?? null) === 'h1') || ($node['type'] === 'hero' && ($props['headingLevel'] ?? null) === 'h1'))) {
                $mainHeading = true;
                $headingCause = $node['id'];
            }
            // Protected: this block is or holds the likely LCP (an image fetched first, or the first h1). Reported for
            // every block of the page (the editor explains it before any effect is chosen); animations are left off.
            $protected = ! $protectLcp ? null : ($priorityImages > $imagesBefore
                ? ['reason' => 'image', 'cause' => $priorityCauses[$causesBefore] ?? $node['id']]
                : (! $headingBefore && $mainHeading ? ['reason' => 'heading', 'cause' => $headingCause] : null));
            if ($protected !== null && $annotate) {
                $motion['protected'][$node['id']] = $protected;
            }
            $rootStyle = is_array($props['style'] ?? null) ? ($props['style']['root'] ?? null) : null;
            if ($styles !== null && is_array($rootStyle) && self::animates($rootStyle)) {
                if ($protected !== null) {
                    $motion['suppressed'][$node['id']] = $protected;
                } elseif (($class = $styles->motionClassFor($rootStyle)) !== null) {
                    $reveal = ($rootStyle['base']['animationTrigger'] ?? 'load') === 'view';
                    $element->attrs['class'] = trim(($element->attrs['class'] ?? '').' ak-anim'.($reveal ? ' ak-reveal' : '')." {$class}");
                    $motion['animated']++;
                    $motion['reveal'] = $motion['reveal'] || $reveal;
                }
            }
            if ($mode === 'editor' && $annotate) {
                $element->attrs = [...$element->attrs, 'data-ak-id' => $node['id'], 'data-ak-type' => $node['type']];
            }

            return $element;
        };

        foreach ($components as $componentId => $resource) {
            $issues = $this->validator->validateFragment($resource['document'], pinned: true);
            if ($issues !== []) {
                throw new RenderException("Reusable component {$componentId} is invalid", $issues);
            }
        }
        $tree = $renderNode((string) $document['root'], $nodes, $nodes[$document['root']]['children'] ?? [], -1, 1.0, true);
        $body = Serializer::serialize($tree);
        $css = BaseCss::minify(implode("\n", [$baseCss, ...array_map($componentCss, array_values($usedComponents))]));
        if ($motion['animated'] > 0) {
            $css .= $mode === 'editor' ? $motionAssets['editorCss'] : $motionAssets['css'];
        }
        // The runtime's script: on pages with a "when scrolled into view" block, or (motion-3 on) with any entrance.
        $script = $mode === 'production' && ($motionAssets['scriptFor'] === 'any' ? $motion['animated'] > 0 : $motion['reveal']);
        $tokens = null;
        if (! $legacy) {
            $css .= $styles->css();
            $tokens = ['version' => $resources['tokens']['version'] ?? null, 'values' => $resources['tokens']['values'] ?? []];
            $css = Tokens::rootCss(Tokens::resolve($tokens['values']), $css).$css;
        }

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
            $script ? Motion::scriptTag($motionRuntime) : '',
        ]);
        $lang = Serializer::escapeAttr($site['lang'] ?? 'en');
        $html = "<!doctype html><html lang=\"{$lang}\"><head>{$head}</head><body>{$body}</body></html>";

        ksort($usedMedia);
        ksort($usedReusable);
        $componentKeys = array_keys($usedComponents);
        sort($componentKeys);
        $inputs = [
            'renderer' => $rendererVersion,
            'components' => $componentKeys,
            'page' => ['title' => $page['title'], 'path' => $page['path']],
            'site' => ['name' => $site['name'], 'lang' => $site['lang'] ?? 'en'],
            'media' => $usedMedia === [] ? new \stdClass : $usedMedia,
        ];
        if (! $legacy) {
            // The token set and the reusable component versions this output was rendered with.
            $inputs['tokens'] = ['version' => $tokens['version'], 'values' => $tokens['values'] === [] ? new \stdClass : $tokens['values']];
            $inputs['reusable'] = $usedReusable === [] ? new \stdClass : $usedReusable;
        }
        if ($motion['animated'] > 0) {
            // The animation CSS and runtime this output uses (recorded only when there are animations).
            $inputs['motion'] = $motionRuntime;
        }

        return [
            'html' => $html,
            'body' => $body,
            'css' => $css,
            'title' => $title,
            // Everything besides the revision document that this output depends on.
            'inputs' => $inputs,
            'report' => [
                'elements' => Serializer::countElements($tree),
                'cssBytes' => strlen($css),
                'scripts' => $script ? 1 : 0,
                'warnings' => $warnings,
                // Blocks animated, whether the viewport runtime is needed, and animations left off (node id → image | heading).
                'motion' => ['animated' => $motion['animated'], 'runtime' => $script ? $motionRuntime : null, 'suppressed' => $motion['suppressed'], 'protected' => $motion['protected']],
            ],
        ];
    }

    /** Whether a root style animates on any screen (an animation other than none). */
    private static function animates(array $rootStyle): bool
    {
        foreach ($rootStyle as $declarations) {
            $animation = is_array($declarations) ? ($declarations['animation'] ?? 'none') : 'none';
            if (is_string($animation) && $animation !== 'none') {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders a publication again from its revision document and recorded
     * inputs: the recorded renderer version, each node's own component version,
     * the site, page and media metadata, the token set and the reusable component
     * versions as they were. Nothing is read from the current site.
     *
     * @param  array  $document  the publication's revision document, exactly as stored
     * @param  array  $inputs  the publication's render_inputs
     * @param  array<string, array{version: int, document: mixed}>  $components  the recorded reusable component versions (immutable rows)
     *
     * @throws RenderException when the inputs do not describe this document or the renderer version is gone
     */
    public function reproduce(mixed $document, array $inputs, array $components = [], ?string $build = null): array
    {
        $renderer = (string) ($inputs['renderer'] ?? '');
        if ($build !== null) {
            // A compatibility record names the development build of the recorded version that made the output.
            if ((self::COMPAT_BUILDS[$build]['renderer'] ?? null) !== $renderer) {
                throw new RenderException("Renderer build {$build} is not a build of {$renderer}", [['message' => "Renderer build {$build} does not match the recorded renderer {$renderer}"]]);
            }
            $renderer = $build;
        }
        $resources = [
            'tokens' => ['version' => $inputs['tokens']['version'] ?? null, 'values' => Json::toArray($inputs['tokens']['values'] ?? [])],
            'components' => $components,
        ];
        $out = $this->render($document, 'production', $inputs['page'], $inputs['site'], Json::toArray($inputs['media'] ?? []), pinned: true, rendererVersion: $renderer, resources: $resources, motionRuntime: (string) ($inputs['motion'] ?? Motion::RUNTIME));
        if (($out['inputs']['motion'] ?? null) !== ($inputs['motion'] ?? null)) {
            throw new RenderException('The recorded animation runtime does not match the revision', [['message' => 'Recorded '.json_encode($inputs['motion'] ?? null).'; document needs '.json_encode($out['inputs']['motion'] ?? null)]]);
        }
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
