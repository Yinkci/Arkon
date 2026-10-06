<?php

namespace App\Arkon\Schema;

use App\Arkon\Support\Rules;
use App\Arkon\Support\Text;

/**
 * Page URL paths. The reserved first segments are the application's own routes
 * (admin, login, media, …); the public catch-all route excludes the same list.
 */
final class PagePath
{
    /** @return list<string> problems, empty when valid */
    public static function issues(mixed $path): array
    {
        if (! is_string($path)) {
            return [Rules::message('expectedString')];
        }
        $issues = [];
        $max = Rules::get('limits.path');
        if (Text::utf16Length($path) > $max) {
            $issues[] = Rules::message('tooLong', ['max' => $max]);
        }
        if (! ($path === '/' || (str_starts_with($path, '/') && ! str_ends_with($path, '/')))) {
            $issues[] = Rules::message('pathShape');
        }
        if ($path !== '/') {
            foreach (explode('/', substr($path, 1)) as $segment) {
                if (! Rules::matches('pathSegment', $segment)) {
                    $issues[] = Rules::message('pathSegments');
                    break;
                }
            }
        }
        if (self::isReservedFirstSegment(explode('/', $path)[1] ?? '')) {
            $issues[] = Rules::message('pathReserved');
        }

        return $issues;
    }

    public static function isReservedFirstSegment(string $segment): bool
    {
        return in_array($segment, Rules::get('reservedPathSegments'), true);
    }

    /** Regex for the public route: anything whose first segment is not reserved. */
    public static function publicRoutePattern(): string
    {
        $reserved = implode('|', array_map('preg_quote', Rules::get('reservedPathSegments')));

        return "^(?!(?:{$reserved})(?:/|$)).*$";
    }
}
