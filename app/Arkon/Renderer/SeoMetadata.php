<?php

namespace App\Arkon\Renderer;

use App\Arkon\Errors\ValidationException;

/** Renderer 8 metadata only. Earlier heads remain byte-identical. */
final class SeoMetadata
{
    public static function enhance(string $head, array $seo, string $title, string $description, string $origin, string $path, array $media, array &$usedMedia, array $identity = []): string
    {
        $canonical = trim($seo['canonical'] ?? '') ?: $origin.$path;
        if ($origin !== '' || trim($seo['canonical'] ?? '') !== '') {
            $head = preg_replace('~<link rel="canonical"[^>]*>~', '', $head);
            $head .= '<link rel="canonical" href="'.Serializer::escapeAttr($canonical).'">';
        }
        $head = preg_replace('~<meta property="og:url"[^>]*>~', '', $head);
        if ($origin !== '' || trim($seo['canonical'] ?? '') !== '') {
            $head .= '<meta property="og:url" content="'.Serializer::escapeAttr($canonical).'">';
        }
        $head = preg_replace('~<meta name="robots"[^>]*>~', '', $head);
        $head .= '<meta name="robots" content="'.(($seo['noindex'] ?? false) ? 'noindex' : 'index').','.(($seo['nofollow'] ?? false) ? 'nofollow' : 'follow').'">';
        $socialTitle = trim($seo['socialTitle'] ?? '') ?: $title;
        $socialDescription = trim($seo['socialDescription'] ?? '') ?: $description;
        foreach (['og:title' => $socialTitle, 'og:description' => $socialDescription, 'twitter:title' => $socialTitle, 'twitter:description' => $socialDescription] as $name => $value) {
            $attribute = str_starts_with($name, 'og:') ? 'property' : 'name';
            $head = preg_replace('~<meta '.$attribute.'="'.preg_quote($name, '~').'"[^>]*>~', '', $head);
            if ($value !== '') {
                $head .= '<meta '.$attribute.'="'.$name.'" content="'.Serializer::escapeAttr($value).'">';
            }
        }
        $id = $seo['socialImage'] ?? '';
        if ($id !== '' && ! isset($media[$id])) {
            throw new ValidationException('Choose a social image from this site.');
        }
        if ($id !== '' && isset($media[$id])) {
            $image = $media[$id];
            $usedMedia[$id] = $image;
            $url = $image['url'];
            if (str_starts_with($url, '/')) {
                $url = $origin.$url;
            }
            $head .= '<meta property="og:image" content="'.Serializer::escapeAttr($url).'"><meta name="twitter:image" content="'.Serializer::escapeAttr($url).'">';
            $head = str_replace('name="twitter:card" content="summary"', 'name="twitter:card" content="summary_large_image"', $head);
        }
        $type = $seo['schemaType'] ?? 'Auto';
        if ($type === 'Auto') {
            $type = match ($seo['pageType'] ?? 'standard') {
                'article' => 'Article', 'contact' => 'ContactPage', 'about' => 'AboutPage', default => 'WebPage'
            };
        }
        if ($type !== 'None' && $origin !== '') {
            $data = ['@context' => 'https://schema.org', '@type' => $type, 'name' => $title, 'url' => $canonical];
            if (in_array($type, ['Article', 'BlogPosting'], true)) {
                $data['headline'] = $title;
            }
            if ($description !== '') {
                $data['description'] = $description;
            }
            if (trim($identity['organizationName'] ?? '') !== '') {
                $data['publisher'] = ['@type' => $identity['organizationType'] ?? 'Organization', 'name' => $identity['organizationName'], 'url' => $origin];
            }
            $head .= '<script type="application/ld+json">'.json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'</script>';
        }

        return $head;
    }
}
