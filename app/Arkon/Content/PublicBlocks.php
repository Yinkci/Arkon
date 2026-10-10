<?php

namespace App\Arkon\Content;

use App\Arkon\Components\Factories;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Text;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Arkon's public block format (schema version 1): how API clients and AI actions write
 * structured content without knowing the editor's internal document. It is deliberately small
 * and stable; each block becomes an ordinary native block, so the result stays fully editable
 * in the builder and is validated and rendered like anything made there.
 *
 *   {"type": "heading",   "text": "…", "level": 1–4}
 *   {"type": "paragraph", "text": "…"}
 *   {"type": "image",     "media_id": "<id>", "alt": "…", "caption": "…"}
 *   {"type": "button",    "label": "…", "href": "/path | https://… | mailto: | tel: | #anchor"}
 *
 * Blocks are grouped into sections, a new one at every level-2 heading. Changes to the internal
 * document never change this format; a new format would be schema version 2.
 */
final class PublicBlocks
{
    public const SCHEMA_VERSION = 1;

    public const TYPES = ['heading', 'paragraph', 'image', 'button'];

    /**
     * @return list<array{type: string, text?: string, level?: int, media_id?: string, alt?: string, caption?: string, label?: string, href?: string}>
     */
    public static function validate(mixed $blocks): array
    {
        if (! Json::isList($blocks) || count($blocks) < 1 || count($blocks) > 400) {
            throw self::invalid('content.blocks', 'Provide 1 to 400 blocks.');
        }
        $out = [];
        foreach ($blocks as $i => $b) {
            $at = "content.blocks.{$i}";
            $b = is_array($b) ? $b : (is_object($b) ? (array) $b : null);
            $type = $b['type'] ?? null;
            if (! in_array($type, self::TYPES, true)) {
                throw self::invalid("{$at}.type", 'Block type must be one of: '.implode(', ', self::TYPES).'.');
            }
            $out[] = match ($type) {
                'heading' => ['type' => 'heading', 'text' => self::text($b['text'] ?? null, "{$at}.text", 1, 300), 'level' => self::level($b['level'] ?? 2, "{$at}.level")],
                'paragraph' => ['type' => 'paragraph', 'text' => self::text($b['text'] ?? null, "{$at}.text", 1, 5000)],
                'image' => [
                    'type' => 'image',
                    'media_id' => Uuid::isValid($b['media_id'] ?? null) ? $b['media_id'] : throw self::invalid("{$at}.media_id", 'An image needs the id of an image in the media library.'),
                    'alt' => self::text($b['alt'] ?? '', "{$at}.alt", 0, 300),
                    'caption' => self::text($b['caption'] ?? '', "{$at}.caption", 0, 300),
                ],
                'button' => ['type' => 'button', 'label' => self::text($b['label'] ?? null, "{$at}.label", 1, 80), 'href' => self::link($b['href'] ?? null, "{$at}.href")],
            };
        }

        return $out;
    }

    /**
     * A complete page document for these blocks: the site's shared header and footer (when
     * published) around sections of native blocks. Starts with the title as the main heading
     * unless the blocks already do.
     *
     * @param  list<array>  $blocks  validated blocks
     */
    public static function toDocument(string $siteId, array $blocks, string $title, string $contentWidth = 'default'): array
    {
        if (($blocks[0]['type'] ?? null) !== 'heading' || $blocks[0]['level'] !== 1) {
            array_unshift($blocks, ['type' => 'heading', 'text' => $title, 'level' => 1]);
        }
        $sections = [];
        $nodes = [];
        foreach ($blocks as $block) {
            $node = self::node($block);
            $last = array_key_last($sections);
            if ($last === null || ($block['type'] === 'heading' && $block['level'] === 2 && $sections[$last]['children'] !== []) || count($sections[$last]['children']) >= 30) {
                $sections[] = Factories::node('section', ['contentWidth' => $contentWidth]);
                $last = array_key_last($sections);
            }
            $sections[$last]['children'][] = $node['id'];
            $nodes[] = $node;
        }
        if (count($sections) > 48) {
            throw self::invalid('content.blocks', 'The content is too long for one page (at most 48 sections).');
        }
        [$header, $footer] = self::sharedLayout($siteId);
        $top = [...($header ? [$header] : []), ...$sections, ...($footer ? [$footer] : [])];
        $doc = Factories::pageDocument($top);
        foreach ($nodes as $node) {
            $doc['nodes'][$node['id']] = $node;
        }

        return $doc;
    }

    /**
     * Replaces a document's content with new blocks, keeping its SEO settings and the instances of
     * the site's shared header and footer where they are.
     */
    public static function replaceContent(string $siteId, mixed $current, array $blocks, string $title, string $contentWidth = 'default'): array
    {
        $next = self::toDocument($siteId, $blocks, $title, $contentWidth);
        $next['seo'] = is_array($current) ? ($current['seo'] ?? $next['seo']) : $next['seo'];

        return $next;
    }

    private static function node(array $b): array
    {
        return match ($b['type']) {
            'heading' => Factories::node('text', ['text' => $b['text'], 'element' => 'h'.$b['level']]),
            'paragraph' => Factories::node('text', ['text' => $b['text'], 'element' => 'p']),
            'image' => Factories::node('image', ['image' => ['assetId' => $b['media_id'], 'alt' => $b['alt']], 'caption' => $b['caption']]),
            'button' => Factories::node('button', ['label' => $b['label'], 'href' => $b['href']]),
        };
    }

    /** @return array{0: ?array, 1: ?array} instance nodes of the published shared header and footer */
    private static function sharedLayout(string $siteId): array
    {
        $settings = DB::table('site_website_settings')->where('site_id', $siteId)->first(['header_id', 'footer_id']);
        $published = fn (?string $id) => $id !== null && DB::table('reusable_components')->where('site_id', $siteId)->where('id', $id)->whereNotNull('published_version')->exists();

        return [
            $published($settings?->header_id) ? Factories::node('instance', ['componentId' => $settings->header_id]) : null,
            $published($settings?->footer_id) ? Factories::node('instance', ['componentId' => $settings->footer_id]) : null,
        ];
    }

    private static function text(mixed $value, string $at, int $min, int $max): string
    {
        if (! is_string($value)) {
            throw self::invalid($at, 'Expected text.');
        }
        $value = Text::trim($value);
        $length = Text::utf16Length($value);
        if ($length < $min || $length > $max) {
            throw self::invalid($at, $min > 0 ? "Expected 1 to {$max} characters." : "Expected at most {$max} characters.");
        }

        return $value;
    }

    private static function level(mixed $value, string $at): int
    {
        return is_int($value) && $value >= 1 && $value <= 4 ? $value : throw self::invalid($at, 'Heading level must be 1, 2, 3 or 4.');
    }

    private static function link(mixed $value, string $at): string
    {
        return is_string($value) && $value !== '' && Rules::matches('link', $value) ? $value : throw self::invalid($at, 'Use a safe link: /path, https://…, mailto:, tel: or #anchor.');
    }

    private static function invalid(string $path, string $message): ValidationException
    {
        return new ValidationException($message, [['path' => $path, 'message' => $message]]);
    }
}
