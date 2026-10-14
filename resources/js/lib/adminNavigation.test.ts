import { expect, test } from 'vitest';
import { currentDestination, visibleDestinations } from './adminNavigation';
test('navigation uses exact section boundaries and existing builder routes', () => {
    expect(currentDestination('/admin/design/components')?.label).toBe('Reusable components');
    expect(currentDestination('/admin/components/123')?.group).toBe('Design');
    expect(currentDestination('/admin/editor/123')?.label).toBe('Pages');
    expect(currentDestination('/admin/pages?status=changed')?.label).toBe('Pages');
    expect(currentDestination('/admin/design-unrelated')).toBeUndefined();
});
test('a defined content type joins the Content group without displacing the built-in kinds', () => {
    const types = [{ key: 'service', plural: 'Services', icon: 'component' }];
    const items = visibleDestinations({ 'page.view': true, 'media.view': true }, types);
    const content = items.filter((item) => item.group === 'Content').map((item) => item.label);
    expect(content).toEqual(['Pages', 'Posts', 'Categories & tags', 'Services', 'Media library', 'Forms']);
    expect(currentDestination('/admin/content/service', types)?.label).toBe('Services');
    // A type nobody defined is not a destination.
    expect(currentDestination('/admin/content/ghost', types)).toBeUndefined();
    // Without any defined types the menu is exactly what it was.
    expect(visibleDestinations({ 'page.view': true }).some((item) => item.href.startsWith('/admin/content/'))).toBe(false);
});

test('viewer and editor navigation reflects capabilities, not invented roles', () => {
    const viewer = visibleDestinations({ 'page.view': true, 'media.view': true });
    expect(viewer.some((p) => p.href === '/admin/media')).toBe(true);
    expect(viewer.some((p) => p.group === 'AI' || p.href === '/admin/settings')).toBe(false);
    const editor = visibleDestinations({ 'page.view': true, 'page.edit': true });
    expect(editor.some((p) => p.group === 'AI')).toBe(true);
    expect(editor.some((p) => p.href === '/admin/settings')).toBe(false);
});
