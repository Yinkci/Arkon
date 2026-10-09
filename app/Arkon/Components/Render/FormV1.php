<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class FormV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $id = $ctx->props['form']['id'] ?? null;
        $form = $id === null ? null : ($ctx->forms[$id] ?? null);
        if (! $form) {
            return Element::h('div', ['class' => $ctx->classes('root', 'ak-form')], $ctx->mode === 'editor' ? 'Choose a published form in Properties.' : '');
        }

        return self::element($id, $form['version'], $form['definition'], $ctx->node['id'].$ctx->occurrence, $ctx->classes('root', 'ak-form'), $ctx->mode === 'editor');
    }

    public static function element(string $id, int $version, array $def, string $prefix, string $class = 'ak-form', bool $disabled = false): Element
    {
        $fields = [];
        foreach ($def['fields'] as $f) {
            $fieldId = $prefix.'-'.$f['id'];
            $attrs = ['id' => $fieldId, 'name' => 'fields['.$f['id'].']', 'required' => $f['required'] ? 'required' : null, 'disabled' => $disabled ? 'disabled' : null, 'maxlength' => 2000];
            $control = match ($f['type']) {
                'textarea' => Element::h('textarea', [...$attrs, 'rows' => 5]),
                'select' => Element::h('select', $attrs, Element::h('option', ['value' => ''], 'Choose…'), array_map(fn ($o) => Element::h('option', ['value' => $o], $o), $f['options'] ?? [])),
                'checkbox' => Element::h('input', [...$attrs, 'type' => 'checkbox', 'value' => 'yes']),
                default => Element::h('input', [...$attrs, 'type' => $f['type'], 'autocomplete' => match ($f['type']) {
                    'email' => 'email','tel' => 'tel',default => 'on'
                }]),
            };
            $fields[] = Element::h('div', ['class' => 'ak-form__field'], Element::h('label', ['for' => $fieldId], $f['label'].($f['required'] ? ' (required)' : '')), $control);
        }

        return Element::h('form', ['method' => 'post', 'action' => '/_arkon/forms/'.$id.'/'.$version, 'class' => $class],
            Element::h('div', ['class' => 'ak-form__trap', 'aria-hidden' => 'true'], Element::h('label', ['for' => $prefix.'-website'], 'Leave this field empty'), Element::h('input', ['id' => $prefix.'-website', 'name' => 'website', 'type' => 'text', 'tabindex' => '-1', 'autocomplete' => 'off'])),
            $fields, Element::h('button', ['type' => 'submit', 'disabled' => $disabled ? 'disabled' : null], $def['submitLabel']));
    }
}
