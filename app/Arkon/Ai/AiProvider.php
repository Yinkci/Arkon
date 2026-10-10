<?php

namespace App\Arkon\Ai;

interface AiProvider extends AiRunner
{
    public function id(): string;
}
