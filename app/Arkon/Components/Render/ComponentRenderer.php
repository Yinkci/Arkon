<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** Renders one version of one component to IR. Never returns strings. */
interface ComponentRenderer
{
    public function render(RenderContext $ctx): Element;
}
