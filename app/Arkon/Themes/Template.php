<?php

namespace App\Arkon\Themes;

use App\Arkon\Components\ComponentDefinition;
use App\Arkon\Style\StyleSchema;
use App\Arkon\Support\Json;
use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

/** A small declarative binding language compiled once, never eval/Blade/PHP or raw HTML output. */
final class Template
{
    private const TAGS = ['article', 'blockquote', 'p', 'cite', 'div', 'span', 'figure', 'figcaption', 'img', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public static function scope(array $m): string
    {
        return 'at-'.$m['type'].'-v'.$m['version'];
    }

    public static function validateManifest(array $m): void
    {
        if (! is_string($m['label'] ?? null) || strlen($m['label']) > 80 || ($m['children'] ?? null) !== false || ! is_array($m['props'] ?? null) || count($m['props']) > 20) {
            throw new RuntimeException('A theme component needs a label, children:false and up to 20 declared fields.');
        }
        foreach ($m['props'] as $key => $field) {
            if (preg_match('/^[a-z][a-zA-Z0-9]{0,30}$/D', $key) !== 1 || ! is_array($field)) {
                throw new RuntimeException('Invalid field name or definition.');
            }
            $type = $field['type'] ?? '';
            if ($type === 'style') {
                if ($key !== 'style' || ! isset($field['slots']['root'])) {
                    throw new RuntimeException('Style fields need the name style and a root slot.');
                }
                foreach ($field['slots'] as $slot => $definition) {
                    if (preg_match('/^[a-z][a-zA-Z0-9]{0,30}$/D', $slot) !== 1 || ! is_array($definition) || ! is_string($definition['label'] ?? null) || ! is_array($definition['groups'] ?? [])) {
                        throw new RuntimeException('Invalid style slot.');
                    }
                    foreach ($definition['groups'] ?? [] as $group) {
                        if (! in_array($group, array_unique(array_column(StyleSchema::properties(), 'group')), true)) {
                            throw new RuntimeException('Unknown style group.');
                        }
                    }
                }
            } elseif ($type === 'string') {
                if (! is_int($field['maxLength'] ?? null) || $field['maxLength'] < 1 || $field['maxLength'] > 5000) {
                    throw new RuntimeException('Text fields need a maxLength from 1 to 5000.');
                }
            } elseif ($type === 'enum') {
                if (! is_array($field['values'] ?? null) || count($field['values']) < 1 || count($field['values']) > 20) {
                    throw new RuntimeException('Choice fields need 1–20 values.');
                }
                foreach ($field['values'] as $value) {
                    if (! is_string($value) || preg_match('/^[a-z][a-z0-9-]{0,30}$/D', $value) !== 1) {
                        throw new RuntimeException('Choices must be lowercase identifiers.');
                    }
                }
            } elseif ($type === 'object') {
                if (($field['nullable'] ?? false) !== true || ($field['properties']['assetId']['type'] ?? '') !== 'uuid' || ($field['properties']['alt']['type'] ?? '') !== 'string' || count($field['properties'] ?? []) !== 2 || ($field['properties']['alt']['maxLength'] ?? 0) > 500 || ($field['properties']['alt']['maxLength'] ?? 0) < 1 || ! in_array($key.'.assetId', $m['mediaRefs'] ?? [], true)) {
                    throw new RuntimeException('Object fields must be nullable images with assetId, bounded alt and a media reference.');
                }
                $altCheck = array_filter($m['publishChecks'] ?? [], fn ($c) => ($c['prop'] ?? '') === $key.'.alt' && ($c['when'] ?? '') === $key && ($c['rule'] ?? '') === 'notBlank');
                if ($altCheck === []) {
                    throw new RuntimeException('Images require alternative text when present.');
                }
            } else {
                throw new RuntimeException('Supported theme fields: string, enum, nullable image and style.');
            }
        }
        foreach ($m['editor']['fields'] ?? [] as $key => $ui) {
            if (! isset($m['props'][$key]) || ! is_array($ui) || (isset($ui['label']) && (! is_string($ui['label']) || strlen($ui['label']) > 80)) || (isset($ui['multiline']) && ! is_bool($ui['multiline']))) {
                throw new RuntimeException('Invalid inspector field metadata.');
            }
        }
        foreach ($m['inlineFields'] ?? [] as $key => $inline) {
            if (($m['props'][$key]['type'] ?? '') !== 'string' || ! in_array($inline['kind'] ?? '', ['line', 'multiline'], true)) {
                throw new RuntimeException('Inline fields must name declared text fields.');
            }
        }
        foreach ($m['publishChecks'] ?? [] as $c) {
            if (! in_array($c['rule'] ?? '', ['notBlank', 'present'], true) || ! isset($m['props'][explode('.', $c['prop'] ?? '')[0]]) || ! is_string($c['message'] ?? null)) {
                throw new RuntimeException('Invalid publication check.');
            }
        }
        $definition = ComponentDefinition::fromManifest($m, '');
        [, $issues] = $definition->props->parse($definition->defaultProps);
        if ($issues !== []) {
            throw new RuntimeException('Invalid component defaults: '.Json::encode($issues));
        }
    }

    public static function compile(string $html, array $m): array
    {
        if (preg_match('/<!|<\?/', $html)) {
            throw new RuntimeException('Templates cannot contain declarations, PHP or entities.');
        }
        $dom = new DOMDocument;
        $before = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($html, LIBXML_NONET) || ! $dom->documentElement) {
                throw new RuntimeException('Template must be one well-formed HTML/XML root; close img tags with />.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        if ($dom->documentElement->tagName === 'img' || $dom->documentElement->getAttribute('data-part') !== 'root') {
            throw new RuntimeException('The template root needs data-part="root".');
        }
        $count = 0;
        $bindings = [];
        $parts = [];
        $visit = function (DOMNode $node, int $depth) use (&$visit, &$count, &$bindings, &$parts, $m): array|string {
            if (++$count > 200 || $depth > 12) {
                throw new RuntimeException('Template exceeds the node or depth limit.');
            }
            if ($node->nodeType === XML_TEXT_NODE) {
                return $node->textContent;
            }
            if (! $node instanceof DOMElement || ! in_array($node->tagName, self::TAGS, true)) {
                throw new RuntimeException('Unsupported template element.');
            }
            $attrs = [];
            foreach ($node->attributes as $attr) {
                if (! in_array($attr->name, ['class', 'data-part', 'data-field', 'data-image', 'data-variant', 'aria-label'], true)) {
                    throw new RuntimeException('Unsupported template attribute: '.$attr->name);
                }
                if ($attr->name === 'class' && preg_match('/^[a-zA-Z0-9 _-]*$/D', $attr->value) !== 1) {
                    throw new RuntimeException('Invalid template class.');
                }
                $attrs[$attr->name] = $attr->value;
            }
            if (isset($attrs['data-part']) && ! isset($m['props']['style']['slots'][$attrs['data-part']])) {
                throw new RuntimeException('Template names an undeclared style part.');
            }
            if (isset($attrs['data-part'])) {
                if (in_array($attrs['data-part'], $parts, true)) {
                    throw new RuntimeException('A design part must identify exactly one element.');
                }
                $parts[] = $attrs['data-part'];
            }
            if (isset($attrs['data-field']) && ($node->tagName === 'img' || isset($attrs['data-image']))) {
                throw new RuntimeException('An image cannot also bind a text field.');
            }
            foreach (['data-field' => 'string', 'data-image' => 'object', 'data-variant' => 'enum'] as $binding => $type) {
                if (isset($attrs[$binding])) {
                    if (($m['props'][$attrs[$binding]]['type'] ?? '') !== $type || ($binding === 'data-image' && $node->tagName !== 'img')) {
                        throw new RuntimeException('Template binding does not match a declared field: '.$attrs[$binding]);
                    }
                    $bindings[] = $attrs[$binding];
                }
            }
            if ($node->tagName === 'img' && ! isset($attrs['data-image'])) {
                throw new RuntimeException('Images must bind a managed media field.');
            }
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $visit($child, $depth + 1);
            }

            return ['tag' => $node->tagName, 'attrs' => $attrs, 'children' => $children];
        };
        $tree = $visit($dom->documentElement, 0);
        foreach ($m['inlineFields'] ?? [] as $key => $_) {
            if (! in_array($key, $bindings, true)) {
                throw new RuntimeException('Inline field has no template binding: '.$key);
            }
        }

        foreach (array_keys($m['props']['style']['slots'] ?? []) as $slot) {
            if (! in_array($slot, $parts, true)) {
                throw new RuntimeException('Design part has no template element: '.$slot);
            }
        }

        return $tree;
    }

    public static function css(string $css, array $m): string
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $remaining = preg_replace('/([^{}]+)\{([^{}]*)\}/', '', $css);
        if (trim($remaining) !== '') {
            throw new RuntimeException('CSS supports scoped rules only; no imports or at-rules.');
        }
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $out = '';
        $allowed = ['display', 'grid-template-columns', 'flex-direction', 'flex-wrap', 'gap', 'align-items', 'justify-content', 'text-align', 'color', 'background-color', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'padding', 'margin', 'border', 'border-radius', 'width', 'height', 'max-width', 'min-width', 'min-height', 'object-fit', 'white-space'];
        foreach ($rules as $rule) {
            $selectors = array_map('trim', explode(',', $rule[1]));
            foreach ($selectors as &$selector) {
                if (preg_match('/^\.component(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*(?:\s+\.[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*)*$/D', $selector) !== 1) {
                    throw new RuntimeException('Every CSS selector must start with .component; only descendant classes are supported.');
                }
                $selector = ':where(.'.self::scope($m).substr($selector, 10).')';
            }
            unset($selector);
            $declarations = [];
            foreach (explode(';', $rule[2]) as $declaration) {
                if (trim($declaration) === '') {
                    continue;
                }
                $pair = explode(':', $declaration, 2);
                $property = trim($pair[0]);
                $value = trim($pair[1] ?? '');
                if (! in_array($property, $allowed, true) || preg_match('/^[a-zA-Z0-9#.%(), -]+$/D', $value) !== 1 || preg_match('/url|expression|image-set/i', $value)) {
                    throw new RuntimeException('Unsupported CSS property or value: '.$property);
                }
                $declarations[] = $property.':'.$value;
            }
            $out .= implode(',', $selectors).'{'.implode(';', $declarations).'}';
        }

        return $out;
    }
}
