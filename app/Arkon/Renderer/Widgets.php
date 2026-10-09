<?php

namespace App\Arkon\Renderer;

final class Widgets
{
    public const VERSION = 'components-5';

    public const PATH = '/_arkon/components-5.js';

    public const INTEGRITY = 'sha384-qyRcFAh8PuBJoBThztHWGy85r5uYCtqmdTXDNNAhNm4PLLDFU7uQ+bvsCHzzdid/';

    public static function scriptTag(string $version = self::VERSION): string
    {
        if ($version === 'components-1') {
            return '<script defer src="/_arkon/components-1.js" integrity="sha384-UrKc4lszb/rJ+ceqZ6vrko3ElVRmY0n7pKc8uxph9Tfqa82cDo7N6fvDdijHY5mH"></script>';
        }
        if ($version === 'components-2') {
            return '<script defer src="/_arkon/components-2.js" integrity="sha384-fVRW+O488MBVQFAvze8eWMCFWCg8mziBMLOkIL/kulktJpbg0z2/9dE0oWp90Lfc"></script>';
        }
        if ($version === 'components-3') {
            return '<script defer src="/_arkon/components-3.js" integrity="sha384-gEqWtycr6TW1J8JqsNx/Yf14SstKtWw37FsxuVbAbPizmLcXUZ60EANydpYpFlZb"></script>';
        }
        if ($version === 'components-4') {
            return '<script defer src="/_arkon/components-4.js" integrity="sha384-BHtezGZICERSIhKkx5j1WtEIG6aXk+qAJAr+AsgrLKkWmHhntzOSLaDmtw42izSR"></script>';
        }
        if ($version !== self::VERSION) {
            throw new \InvalidArgumentException('Unknown widget runtime');
        }

        return '<script defer src="'.self::PATH.'" integrity="'.self::INTEGRITY.'"></script>';
    }

    public static function scriptSrc(string $html, string $origin): string
    {
        if (str_contains($html, self::scriptTag('components-1'))) {
            return $origin.'/_arkon/components-1.js';
        }

        if (str_contains($html, self::scriptTag('components-2'))) {
            return $origin.'/_arkon/components-2.js';
        }

        if (str_contains($html, self::scriptTag('components-3'))) {
            return $origin.'/_arkon/components-3.js';
        }

        if (str_contains($html, self::scriptTag('components-4'))) {
            return $origin.'/_arkon/components-4.js';
        }

        return str_contains($html, self::scriptTag()) ? $origin.self::PATH : '';
    }
}
