<?php

namespace App\Arkon\Support;

use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Schema\PagePath;
use Illuminate\Support\Facades\Validator;

/**
 * Service-level input validation. Services validate their own input (HTTP is not
 * the only caller), with Laravel's validator for plain fields and the shared
 * rules for domain values (paths, titles, request keys).
 */
final class Input
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed> the validated fields
     */
    public static function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            $issues = [];
            foreach ($validator->errors()->messages() as $path => $messages) {
                foreach ($messages as $message) {
                    $issues[] = ['path' => $path, 'message' => $message];
                }
            }
            throw new ValidationException(implode(' ', array_column($issues, 'message')), $issues);
        }

        return $validator->validated();
    }

    /** Laravel rule for a client-generated request key (save, publish, create). */
    public static function requestKeyRule(): array
    {
        return ['required', 'string', 'regex:/'.Rules::get('patterns.requestKey').'/D'];
    }

    /** Ids from URLs: malformed ones are simply not found. */
    public static function id(mixed $value, string $what = 'Page'): string
    {
        return Uuid::isValid($value) ? $value : throw new NotFoundException($what);
    }

    /** Trimmed page title, 1–120 characters (UTF-16 units, like the editor). */
    public static function title(mixed $value): string
    {
        if (! is_string($value)) {
            throw new ValidationException(Rules::message('titleRequired'), [['path' => 'title', 'message' => Rules::message('titleRequired')]]);
        }
        $title = Text::trim($value);
        $max = Rules::get('limits.pageTitle');
        $message = match (true) {
            $title === '' => Rules::message('titleRequired'),
            Text::utf16Length($title) > $max => Rules::message('tooLong', ['max' => $max]),
            default => null,
        };
        if ($message !== null) {
            throw new ValidationException($message, [['path' => 'title', 'message' => $message]]);
        }

        return $title;
    }

    public static function path(mixed $value): string
    {
        $problems = PagePath::issues($value);
        if ($problems !== []) {
            throw new ValidationException(implode(' ', $problems), array_map(fn ($m) => ['path' => 'path', 'message' => $m], $problems));
        }

        return $value;
    }
}
