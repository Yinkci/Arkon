<?php

namespace App\Arkon\Forms;

final class FormRuntime
{
    public static function tag(int $version = 1): string
    {
        if ($version === 2) {
            return '<script defer src="/_arkon/forms-2.js" integrity="sha384-HR+0CBQZT51P0nuBp+T6FUDzikY1T7V+q+6xys83IW8YupCeT6JF4sus2CwACze8"></script>';
        }

        return '<script defer src="/_arkon/forms-1.js" integrity="sha384-/OzePax+79WiK1kelfm7SHBl3fAuk5nRnd6/GHg71cRJOice2iLQ+BwcgRs3wYMH"></script>';
    }
}
