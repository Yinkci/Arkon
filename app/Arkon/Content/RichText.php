<?php

namespace App\Arkon\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Rich text is the one field that keeps markup, so it is cleaned when it is stored rather than
 * when it is shown: whatever reaches the database is already safe for the renderer, the API and
 * any headless consumer, whoever wrote it — a person, the API or AI.
 *
 * An allow list, not a block list: anything not named here is dropped, including every
 * attribute, so a tag or scheme nobody thought of cannot arrive later and be trusted.
 */
final class RichText
{
    /** Tags a writer may use. Anything else is unwrapped, keeping its text. */
    private const TAGS = ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'blockquote', 'code', 'pre'];

    /** The only attribute kept, on the only tag allowed to carry it. */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The wrapper keeps the fragment from being treated as a whole document, and the
        // encoding hint stops DOMDocument reading UTF-8 bytes as Latin-1.
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="ak-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('ak-root');
        if (! $root instanceof DOMElement) {
            return '';
        }
        self::clean($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    /** Walks the tree once, dropping what is not allowed and unwrapping what is merely unknown. */
    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $name = strtolower($child->nodeName);
                if (in_array($name, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math'], true)) {
                    // Content included: a dropped script must not leave its source behind as text.
                    $child->parentNode?->removeChild($child);

                    continue;
                }
                self::clean($child);
                if (! in_array($name, self::TAGS, true)) {
                    self::unwrap($child);

                    continue;
                }
                self::attributes($child, $name);
            } elseif (! $child instanceof \DOMText) {
                // Comments, processing instructions and CDATA carry no content worth keeping.
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /** Replaces an element with its children, so unknown markup loses the tag but not the words. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }
        $parent->removeChild($element);
    }

    private static function attributes(DOMElement $element, string $name): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $element->removeAttribute($attribute->nodeName);
            if ($name === 'a' && strtolower($attribute->nodeName) === 'href' && self::link($attribute->nodeValue ?? '')) {
                $element->setAttribute('href', trim($attribute->nodeValue ?? ''));
            }
        }
        if ($name === 'a' && $element->getAttribute('href') === '') {
            self::unwrap($element);
        } elseif ($name === 'a') {
            // A link written elsewhere is not trusted to decide what the opener may reach.
            $element->setAttribute('rel', 'noopener nofollow ugc');
        }
    }

    private static function link(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return true; // A path on this site.
        }
        if (str_starts_with($href, '#')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, self::LINK_SCHEMES, true);
    }
}
