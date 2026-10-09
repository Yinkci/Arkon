<?php

namespace App\Arkon\Renderer;

/** Basic social previews from the same escaped, recorded page inputs as canonical SEO. */
final class SocialMetadata
{
    public static function head(string $title, string $description, string $siteName, string $url): string
    {
        $tags = ['og:type' => 'website', 'og:title' => $title, 'og:site_name' => $siteName, 'og:url' => $url];
        if ($description !== '') {
            $tags['og:description'] = $description;
        }
        $out = '';
        foreach ($tags as $key => $value) {
            $out .= '<meta property="'.$key.'" content="'.Serializer::escapeAttr($value).'">';
        }
        $out .= '<meta name="twitter:card" content="summary"><meta name="twitter:title" content="'.Serializer::escapeAttr($title).'">';
        if ($description !== '') {
            $out .= '<meta name="twitter:description" content="'.Serializer::escapeAttr($description).'">';
        }

        return $out;
    }
}
