<?php

namespace App\Arkon\Renderer;

use App\Arkon\Components\ComponentDefinition;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\Render\ImageSizes;
use App\Arkon\Components\Render\RenderContext;
use App\Arkon\Forms\FormRuntime;
use App\Arkon\Seo\SeoDefaults;
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
    public const VERSION = 'arkon-php-8';

    /** Renderer versions this code can still produce, with their base stylesheet. */
    private const BASE_CSS = ['arkon-php-1' => BaseCss::CSS, 'arkon-php-2' => BaseCss::CSS_V2, 'arkon-php-3' => BaseCss::CSS_V2, 'arkon-php-4' => BaseCss::CSS_V2, 'arkon-php-5' => BaseCss::CSS_V2, 'arkon-php-6' => BaseCss::CSS_V2, 'arkon-php-7' => BaseCss::CSS_V2, 'arkon-php-8' => BaseCss::CSS_V2];

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
        $forms = $resources['forms'] ?? [];
        $menus = $resources['menus'] ?? [];
        $usedMenus = [];
        $usedForms = [];
        foreach ([$document, ...array_column($components, 'document')] as $formDocument) {
            foreach (Json::entries($formDocument['nodes']) as $formNode) {
                if ($formNode['type'] === 'navigation') {
                    $menuId = Json::entries($formNode['props'])['menuId'] ?? '';
                    if (isset($menus[$menuId])) {
                        $usedMenus[$menuId] = $menus[$menuId];
                    } elseif ($strict) {
                        throw new RenderException('A menu is not ready to publish', [['message' => 'Choose and publish a menu first']]);
                    }
                }
                if ($formNode['type'] === 'form') {
                    $formId = Json::entries(Json::entries($formNode['props'])['form'] ?? [])['id'] ?? '';
                    if (! isset($forms[$formId])) {
                        if ($strict) {
                            throw new RenderException('A contact form is not ready to publish', [['nodeId' => $formNode['id'], 'message' => 'Choose and publish a contact form first']]);
                        }
                    } else {
                        $usedForms[$formId] = $forms[$formId];
                    }
                }
            }
        }
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
        $styles = $legacy ? null : new StyleSheet(function (string $assetId, string $screen = 'base') use ($mediaLookup, $baseVersion) {
            $image = $mediaLookup($assetId);

            return $image === null ? null : (in_array($baseVersion, ['arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) ? BackgroundImages::url($image, $screen) : $image['url']);
        }, flexBasis: $build['flexBasis'] ?? true, responsiveBackgrounds: in_array($baseVersion, ['arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true));
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
        // Shared landmarks should not consume the first content block's image priority.
        // Gate this on the new renderer: historical publications keep their original indexes.
        $contentIndexes = [];
        if (in_array($baseVersion, ['arkon-php-3', 'arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) && ($nodes[$document['root']]['version'] ?? 0) >= 4) {
            $position = 0;
            foreach ($nodes[$document['root']]['children'] ?? [] as $childId) {
                $child = $nodes[$childId];
                if ($child['type'] === 'instance') {
                    $part = $components[Json::entries($child['props'])['componentId'] ?? ''] ?? null;
                    $fragment = $part['document'] ?? null;
                    $fragmentNodes = Json::entries($fragment['nodes'] ?? []);
                    $fragmentChildren = $fragmentNodes[$fragment['root'] ?? '']['children'] ?? [];
                    $child = count($fragmentChildren) === 1 ? $fragmentNodes[$fragmentChildren[0]] : $child;
                }
                $semantic = $child['type'] === 'group' && ($child['version'] ?? 0) >= 3 ? (Json::entries($child['props'])['element'] ?? '') : '';
                $contentIndexes[$childId] = in_array($semantic, ['header', 'footer'], true) ? -2 : $position++;
            }
        }

        $renderNode = function (string $id, array $tree, array $rootChildren, int $top, float $fraction, bool $annotate, string $occurrence = '', array $screenShares = ['base' => 1.0, 'tablet' => 1.0, 'mobile' => 1.0]) use (&$renderNode, &$usedComponents, &$usedReusable, &$priorityImages, &$mainHeading, &$priorityCauses, &$headingCause, &$motion, $mode, $protectLcp, $styles, $mediaLookup, $claimPriority, $notePriority, $components, $forms, $menus, $contentIndexes, $baseVersion): Element {
            $node = $tree[$id];
            $imagesBefore = $priorityImages;
            $causesBefore = count($priorityCauses);
            $headingBefore = $mainHeading;
            $definition = $this->validator->definitionOf($node);
            // A newly published form definition is an explicit resource change, not a page edit.
            // Renderer 6 adapts older embeddings to schema 2; older renderer versions remain exact.
            if (in_array($baseVersion, ['arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) && $node['type'] === 'form') {
                $formId = Json::entries(Json::entries($node['props'])['form'] ?? [])['id'] ?? '';
                if (($forms[$formId]['definition']['schemaVersion'] ?? 1) === 2) {
                    $definition = $this->registry->get('form', 4);
                }
            }
            $usedComponents["{$definition->type}@{$definition->version}"] = $definition;
            $props = $definition->props->parseValid($node['props']);
            $index = array_search($id, $rootChildren, true);
            $top = $index === false ? $top : ($contentIndexes[$id] ?? $index);
            $childIds = $node['children'] ?? [];
            $childFraction = $node['type'] === 'columns' && count($childIds) > 1 ? $fraction / count($childIds) : $fraction;
            $children = array_map(fn ($child, $childPosition) => $renderNode((string) $child, $tree, $rootChildren, $node['type'] === 'slider' && $childPosition > 0 ? -1 : $top, $childFraction, $annotate, $occurrence, $node['type'] === 'columns' ? ImageSizes::childShares($props, $screenShares, count($childIds), $childPosition) : $screenShares), $childIds, array_keys($childIds));

            if ($node['type'] === 'instance') {
                $resource = $components[$props['componentId']] ?? null;
                if ($resource !== null) {
                    $usedReusable[$props['componentId']] = (int) $resource['version'];
                    $fragment = $resource['document'];
                    $fragmentNodes = Json::entries($fragment['nodes']);
                    $children = array_map(
                        fn ($child) => $renderNode((string) $child, $fragmentNodes, [], $top, $fraction, false, $occurrence.'-'.$id, $screenShares),
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
                screenShares: $screenShares,
                claimPriority: $claimPriority,
                notePriority: $notePriority,
                forms: $forms,
                menus: $menus,
                occurrence: $occurrence,
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
        if ($strict) {
            $ids = [];
            $checkIds = function (Element $element) use (&$checkIds, &$ids) {
                $id = $element->attrs['id'] ?? null;
                if ($id !== null) {
                    if (isset($ids[$id])) {
                        throw new RenderException('Section anchors must be unique across the page and shared layout.', [['message' => 'Duplicate section anchor '.$id]]);
                    } $ids[$id] = true;
                }
                foreach ($element->children as $child) {
                    if ($child instanceof Element) {
                        $checkIds($child);
                    }
                }
            };
            $checkIds($tree);
        }
        $widgetTypes = array_filter(array_keys($usedComponents), fn ($key) => str_starts_with($key, 'slider@') || str_starts_with($key, 'back-to-top@'));
        $widgetVersion = isset($usedComponents['slider@8']) ? (in_array($baseVersion, ['arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) ? Widgets::VERSION : 'components-4') : ((isset($usedComponents['slider@7']) || isset($usedComponents['slider@6']) || isset($usedComponents['slider@5']) || isset($usedComponents['slider@4'])) ? 'components-3' : (isset($usedComponents['slider@3']) ? 'components-2' : 'components-1'));
        $widgetScript = in_array($baseVersion, ['arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) && $mode === 'production' && $widgetTypes !== [];
        // Enforce the production boundary after layout helpers have consumed their
        // internal annotations. Nested sticky headers can bypass page-root cleanup.
        // Historical versions retain their recorded output; new publications never
        // serialize attributes in the reserved editor namespace.
        if ($mode === 'production' && in_array($baseVersion, ['arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true)) {
            $clean = function (Element|TextNode $node) use (&$clean): void {
                if ($node instanceof TextNode) {
                    return;
                }
                foreach (array_keys($node->attrs) as $name) {
                    if (str_starts_with($name, self::EDITOR_ATTR_PREFIX)) {
                        unset($node->attrs[$name]);
                    }
                }
                foreach ($node->children as $child) {
                    $clean($child);
                }
            };
            $clean($tree);
        }
        $body = Serializer::serialize($tree);
        $css = BaseCss::minify(implode("\n", [$baseCss, ...array_map($componentCss, array_values($usedComponents))]));
        if ($motion['animated'] > 0) {
            $css .= $mode === 'editor' ? $motionAssets['editorCss'] : $motionAssets['css'];
        }
        if (in_array($baseVersion, ['arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true)) {
            $fontUsed = ($resources['tokens']['values']['font']['body'] ?? '') === 'inter' || ($resources['tokens']['values']['font']['heading'] ?? '') === 'inter';
            foreach ([$document, ...array_column($components, 'document')] as $fontDoc) {
                foreach (Json::entries($fontDoc['nodes']) as $fontNode) {
                    foreach (Json::entries(Json::entries($fontNode['props'])['style'] ?? []) as $bps) {
                        foreach (Json::entries($bps) as $values) {
                            if ((Json::entries($values)['fontFamily'] ?? '') === 'inter') {
                                $fontUsed = true;
                            }
                        }
                    }
                }
            }
            if ($fontUsed) {
                $css .= '@font-face{font-family:"Arkon Inter";font-style:normal;font-weight:100 900;font-display:swap;src:url("/fonts/inter-v4.1/InterVariable.woff2") format("woff2")}@font-face{font-family:"Arkon Inter";font-style:normal;font-weight:100 900;font-display:swap;src:url("/fonts/inter-v4.1/InterVariable-latin.woff2") format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0300-036F,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}';
            }
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
        if ($baseVersion === 'arkon-php-8') {
            $seo = SeoDefaults::resolve($seo, $page, $site);
        }
        $seoTitle = is_string($seo['title'] ?? null) ? Text::trim($seo['title']) : '';
        $title = $seoTitle !== '' ? $seoTitle : "{$page['title']} · {$site['name']}";
        $description = is_string($seo['description'] ?? null) ? Text::trim($seo['description']) : '';
        $head = implode('', [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width,initial-scale=1">',
            '<title>'.Serializer::escapeText($title).'</title>',
            in_array($baseVersion, ['arkon-php-3', 'arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) && ! empty($site['origin']) ? '<link rel="canonical" href="'.Serializer::escapeAttr($site['origin'].$page['path']).'">' : '',
            $description !== '' ? '<meta name="description" content="'.Serializer::escapeAttr($description).'">' : '',
            ($seo['noindex'] ?? false) === true ? '<meta name="robots" content="noindex">' : '',
            "<style>{$css}</style>",
            $script ? Motion::scriptTag($motionRuntime) : '',
        ]);
        // New metadata ships under a new renderer version. Earlier publications
        // retain their exact head and remain reproducible from recorded inputs.
        if (in_array($baseVersion, ['arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) && ! empty($site['origin'])) {
            $head .= SocialMetadata::head($title, $description, $site['name'], $site['origin'].$page['path']);
        }
        if (in_array($baseVersion, ['arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true)) {
            $firstContent = array_search(0, $contentIndexes, true);
            $seen = [];
            $findBackground = function (string $id) use (&$findBackground, &$seen, $nodes): ?string {
                if (isset($seen[$id]) || ! isset($nodes[$id])) {
                    return null;
                } $seen[$id] = true;
                $node = $nodes[$id];
                foreach (Json::entries(Json::entries(Json::entries($node['props'])['style'] ?? [])['root'] ?? []) as $screen => $values) {
                    if ($screen !== 'base') {
                        continue;
                    }
                    $asset = Json::entries(Json::entries($values)['backgroundImage'] ?? [])['assetId'] ?? null;
                    if ($asset) {
                        return $asset;
                    }
                }
                $children = $node['children'] ?? [];
                if ($node['type'] === 'slider') {
                    $children = array_slice($children, 0, 1);
                }
                foreach ($children as $child) {
                    if ($asset = $findBackground($child)) {
                        return $asset;
                    }
                }

                return null;
            };
            if ($firstContent !== false && ($asset = $findBackground((string) $firstContent)) && isset($media[$asset])) {
                foreach (['base' => '(min-width:900px)', 'tablet' => '(min-width:600px) and (max-width:899px)', 'mobile' => '(max-width:599px)'] as $screen => $query) {
                    $head .= '<link rel="preload" as="image" fetchpriority="high" media="'.$query.'" href="'.Serializer::escapeAttr(BackgroundImages::url($media[$asset], $screen)).'">';
                }
            }
        }
        if ($widgetScript) {
            $head .= Widgets::scriptTag($widgetVersion);
        }
        $formScript = $mode === 'production' && isset($usedComponents['form@4']) && array_filter($usedForms, fn ($form) => ($form['definition']['schemaVersion'] ?? 1) === 2) !== [];
        if ($formScript) {
            $head .= FormRuntime::tag(in_array($baseVersion, ['arkon-php-7', 'arkon-php-8'], true) ? 2 : 1);
        }
        if ($baseVersion === 'arkon-php-8') {
            $head = SeoMetadata::enhance($head, $seo, $title, $description, $site['origin'] ?? '', $page['path'], $media, $usedMedia, $site['seoDefaults']['values'] ?? []);
        }
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
            'site' => ['name' => $site['name'], 'lang' => $site['lang'] ?? 'en', ...(in_array($baseVersion, ['arkon-php-3', 'arkon-php-4', 'arkon-php-5', 'arkon-php-6', 'arkon-php-7', 'arkon-php-8'], true) && ! empty($site['origin']) ? ['origin' => $site['origin']] : [])],
            'media' => $usedMedia === [] ? new \stdClass : $usedMedia,
        ];
        if ($baseVersion === 'arkon-php-8') {
            $inputs['site']['seoDefaults'] = $site['seoDefaults'] ?? ['version' => 0, 'values' => SeoDefaults::defaults()];
        }
        if (! $legacy) {
            // The token set and the reusable component versions this output was rendered with.
            $inputs['tokens'] = ['version' => $tokens['version'], 'values' => $tokens['values'] === [] ? new \stdClass : $tokens['values']];
            $inputs['reusable'] = $usedReusable === [] ? new \stdClass : $usedReusable;
        }
        if ($widgetScript) {
            $inputs['widgets'] = $widgetVersion;
        }
        if ($usedMenus !== []) {
            ksort($usedMenus);
            $inputs['menus'] = $usedMenus;
        }
        if ($usedForms !== []) {
            ksort($usedForms);
            $inputs['forms'] = $usedForms;
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
                // Diagnostics do not alter serialized output or recorded render inputs.
                'diagnostics' => OutputDiagnostics::inspect($tree, $html, $css, $description),
                'cssBytes' => strlen($css),
                'scripts' => ($script ? 1 : 0) + ($widgetScript ? 1 : 0) + ($formScript ? 1 : 0),
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
            'forms' => Json::toArray($inputs['forms'] ?? []),
            'menus' => Json::toArray($inputs['menus'] ?? []),
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
