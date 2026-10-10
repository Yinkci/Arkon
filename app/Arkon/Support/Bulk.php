<?php

namespace App\Arkon\Support;

use App\Arkon\Errors\ArkonException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\ValidationException;

/**
 * One action applied to several items of a list (Trash, Restore, Delete permanently). Each item
 * runs on its own, through the same service method as a single action, so one item that cannot be
 * changed (it is in use, changed meanwhile, already gone) never blocks or rolls back the others.
 * A missing permission is not an item problem: it fails the whole request.
 */
final class Bulk
{
    public const MAX = 100;

    /**
     * @param  mixed  $items  list of {id, version?} from the request
     * @param  callable(string $id, ?int $version): void  $each
     * @return array{done: list<string>, failed: list<array{id: string, message: string}>}
     */
    public static function run(mixed $items, callable $each): array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX) {
            throw new ValidationException('Choose between 1 and '.self::MAX.' items.');
        }
        $done = [];
        $failed = [];
        foreach ($items as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            $version = is_array($item) && isset($item['version']) ? $item['version'] : null;
            if (! is_string($id) || ! Uuid::isValid($id) || ($version !== null && (! is_int($version) || $version < 1))) {
                throw new ValidationException('Each item needs a valid id (and version, where one applies).');
            }
            try {
                $each($id, $version);
                $done[] = $id;
            } catch (ForbiddenException $e) {
                throw $e;
            } catch (ArkonException $e) {
                $failed[] = ['id' => $id, 'message' => $e->getMessage()];
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
