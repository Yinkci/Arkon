<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** Form presentation only: submissions still use the existing versioned native form service. */
final class FormV3 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $id = $ctx->props['form']['id'] ?? null;
        $form = $id === null ? null : ($ctx->forms[$id] ?? null);
        if (! $form) {

            return Element::h('div', ['class' => $ctx->classes('root', 'ak-form')], $ctx->mode === 'editor' ? 'Choose a published form in Properties.' : '');
        }
        $native = FormV1::element($id, $form['version'], $form['definition'], $ctx->node['id'].$ctx->occurrence, 'ak-form3 ak-form3--'.$ctx->props['layout'], $ctx->mode === 'editor');

        $native->attrs = [...$native->attrs, ...ResponsiveOptions::attributes($ctx->props, ['layout'])];

        return Element::h('div', ['class' => $ctx->classes('root', 'ak-form-block')],
            $native,
            $ctx->props['purpose'] === 'newsletter' ? Element::h('p', ['class' => 'ak-form__notice'], $ctx->props['notice']) : null);
    }
}
