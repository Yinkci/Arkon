<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class NavigationV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $menu = $ctx->menus[$ctx->props['menuId']] ?? null;
        if (! $menu) {
            return Element::h('nav', ['class' => 'ak-navigation', 'aria-label' => $ctx->props['label']], $ctx->mode === 'editor' ? 'Choose and publish a menu in Navigation.' : '');
        }
        $items = $menu['definition']['items'];
        $list = function () use ($items) {
            $out = [];
            foreach ($items as $i) {
                if ($i['parentId'] !== null) {
                    continue;
                }
                $link = fn ($v) => Element::h('a', ['href' => $v['resolvedHref'] ?? '#'], $v['label']);
                $children = array_values(array_filter($items, fn ($v) => $v['parentId'] === $i['id']));
                $out[] = Element::h('li', [], $children === [] ? $link($i) : Element::h('details', [], Element::h('summary', [], $i['label']), Element::h('ul', ['class' => 'ak-navigation__dropdown'], Element::h('li', [], $link($i)), array_map(fn ($c) => Element::h('li', [], $link($c)), $children))));
            }

            return Element::h('ul', [], $out);
        };

        return Element::h('nav', ['class' => $ctx->classes('root', 'ak-navigation'), 'aria-label' => $ctx->props['label']], Element::h('div', ['class' => 'ak-navigation__desktop'], $list()), Element::h('details', ['class' => 'ak-navigation__mobile'], Element::h('summary', [], $ctx->props['mobileLabel']), $list()));
    }
}
