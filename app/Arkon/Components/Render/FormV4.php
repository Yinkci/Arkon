<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Forms\FormDefinition;
use App\Arkon\Renderer\Element;

final class FormV4 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $id = $ctx->props['form']['id'] ?? null;
        $form = $ctx->forms[$id] ?? null;
        if ($form && ($form['definition']['schemaVersion'] ?? 1) !== 2) {
            return (new FormV3)->render($ctx);
        }
        if (! $form) {
            return Element::h('div', ['class' => $ctx->classes('root', 'ak-form')], $ctx->mode === 'editor' ? 'Select a published form in Properties.' : '');
        }
        $native = self::element($id, $form['version'], $form['definition'], $ctx->node['id'].$ctx->occurrence, $ctx->mode === 'editor');
        $native->attrs = [...$native->attrs, 'data-form-layout' => $ctx->props['layout'], ...ResponsiveOptions::attributes($ctx->props, ['layout'])];

        return Element::h('div', ['class' => $ctx->classes('root', 'ak-form-block')], $native, $ctx->props['purpose'] === 'newsletter' ? Element::h('p', ['class' => 'ak-form__notice'], $ctx->props['notice']) : null);
    }

    public static function element(string $id, int $version, array $def, string $prefix, bool $disabled = false, array $values = [], array $errors = []): Element
    {
        if (($def['schemaVersion'] ?? 1) !== 2) {
            return FormV1::element($id, $version, $def, $prefix, 'ak-form3', $disabled);
        }
        if (! $def['active']) {
            return Element::h('p', ['role' => 'status'], 'This form is not accepting submissions.');
        }
        $initial = [];
        foreach ($def['fields'] as $f) {
            $initial[$f['id']] = $values[$f['id']] ?? $f['defaultValue'];
        }
        $visible = array_column(FormDefinition::visibleFields($def, $initial), 'id');
        $rows = [];
        foreach ($def['fields'] as $f) {
            $fieldDisabled = $disabled || ! in_array($f['id'], $visible, true);
            $fieldId = $prefix.'-'.$f['id'];
            $name = 'fields['.$f['id'].']';
            $v = $initial[$f['id']];
            $error = collect($errors)->firstWhere('path', $f['id'])['message'] ?? null;
            $a = ['id' => $fieldId, 'name' => $name, 'required' => $f['required'] ? 'required' : null, 'disabled' => $fieldDisabled ? 'disabled' : null, 'placeholder' => $f['placeholder'] ?: null, 'maxlength' => 2000, 'aria-invalid' => $error ? 'true' : null, 'aria-describedby' => ($f['description'] !== '' || $error) ? $fieldId.'-hint' : null];
            if (in_array($f['type'], ['radio', 'checkboxes'], true)) {
                $control = Element::h('fieldset', ['class' => 'ak-form4__choices', 'aria-invalid' => $error ? 'true' : null, 'aria-describedby' => ($f['description'] !== '' || $error) ? $fieldId.'-hint' : null], Element::h('legend', [], $f['label'].($f['required'] ? ' (required)' : '')), array_map(function ($c, $i) use ($f, $fieldId, $name, $v, $fieldDisabled) {
                    $key = $fieldId.'-'.$i;
                    $many = $f['type'] === 'checkboxes';
                    $checked = $many ? in_array($c['value'], is_array($v) ? $v : [], true) : $v === $c['value'];

                    return Element::h('label', ['for' => $key], Element::h('input', ['id' => $key, 'type' => $many ? 'checkbox' : 'radio', 'name' => $many ? $name.'[]' : $name, 'value' => $c['value'], 'required' => ! $many && $f['required'] ? 'required' : null, 'disabled' => $fieldDisabled ? 'disabled' : null, 'checked' => $checked ? 'checked' : null]), $c['label']);
                }, $f['choices'], array_keys($f['choices'])));
            } elseif ($f['type'] === 'section') {
                $control = Element::h('h3', [], $f['label']);
            } elseif ($f['type'] === 'divider') {
                $control = Element::h('hr', []);
            } else {
                $input = match ($f['type']) {
                    'textarea' => Element::h('textarea', [...$a, 'rows' => 5], is_array($v) ? '' : $v),'select' => Element::h('select', $a, Element::h('option', ['value' => ''], $f['placeholder'] ?: 'Choose…'), array_map(fn ($c) => Element::h('option', ['value' => $c['value'], 'selected' => $v === $c['value'] ? 'selected' : null], $c['label']), $f['choices'])),'checkbox' => Element::h('input', [...$a, 'type' => 'checkbox', 'value' => 'yes', 'checked' => $v === 'yes' ? 'checked' : null]),default => Element::h('input', [...$a, 'type' => $f['type'], 'value' => is_array($v) ? '' : $v, 'min' => $f['min'] ?? null, 'max' => $f['max'] ?? null, 'step' => $f['type'] === 'number' ? ($f['step'] ?? 'any') : null])
                };
                $control = Element::h('label', ['for' => $fieldId], Element::h('span', [], $f['label'].($f['required'] ? ' (required)' : '')), $input);
            }
            $rows[$f['row']][] = Element::h('div', ['class' => 'ak-form4__field ak-form4__field--'.$f['width'], 'data-field' => $f['id'], 'hidden' => ! in_array($f['id'], $visible, true) ? 'hidden' : null], $control, ($f['description'] !== '' || $error) ? Element::h('p', ['id' => $fieldId.'-hint', 'class' => 'ak-form4__hint'], $error ?? $f['description']) : null);
        }
        $rules = array_map(fn ($f) => ['id' => $f['id'], 'condition' => $f['condition'], 'required' => $f['required'], 'type' => $f['type']], $def['fields']);

        return Element::h('form', ['class' => 'ak-form4', 'method' => 'post', 'action' => '/_arkon/forms/'.$id.'/'.$version, 'data-form-rules' => json_encode($rules, JSON_THROW_ON_ERROR)],
            $def['description'] !== '' ? Element::h('p', [], $def['description']) : null,
            Element::h('div', ['class' => 'ak-form__trap', 'aria-hidden' => 'true'], Element::h('label', ['for' => $prefix.'-website'], 'Leave this empty'), Element::h('input', ['name' => 'website', 'id' => $prefix.'-website', 'type' => 'text', 'tabindex' => -1, 'autocomplete' => 'off'])),
            Element::h('input', ['type' => 'hidden', 'name' => 'requestKey', 'value' => '']),
            array_map(fn ($fields) => Element::h('div', ['class' => 'ak-form4__row'], $fields), array_values($rows)),
            Element::h('button', ['type' => 'submit', 'disabled' => $disabled ? 'disabled' : null], $def['submitLabel']), Element::h('p', ['role' => 'status', 'aria-live' => 'polite', 'class' => 'ak-form4__status']));
    }
}
