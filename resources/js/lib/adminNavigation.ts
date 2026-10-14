import type { IconName } from '@/Components/Icon';
export interface AdminDestination {
    href: string;
    label: string;
    group: string;
    icon: IconName;
    permission: string;
    matches?: string[];
}
// One registry drives sidebar groups and breadcrumbs.
export const adminDestinations: AdminDestination[] = [
    { href: '/admin', label: 'Dashboard', group: 'Overview', icon: 'home', permission: 'page.view' },
    { href: '/admin/pages', label: 'Pages', group: 'Content', icon: 'pages', permission: 'page.view', matches: ['/admin/editor/'] },
    { href: '/admin/posts', label: 'Posts', group: 'Content', icon: 'post', permission: 'page.view' },
    { href: '/admin/posts/categories', label: 'Categories & tags', group: 'Content', icon: 'tag', permission: 'page.view', matches: ['/admin/posts/tags'] },
    { href: '/admin/media', label: 'Media library', group: 'Content', icon: 'image', permission: 'media.view' },
    { href: '/admin/forms', label: 'Forms', group: 'Content', icon: 'form', permission: 'page.view' },
    { href: '/admin/design', label: 'Global styles', group: 'Design', icon: 'palette', permission: 'page.view' },
    {
        href: '/admin/design/components',
        label: 'Reusable components',
        group: 'Design',
        icon: 'component',
        permission: 'page.view',
        matches: ['/admin/components/'],
    },
    { href: '/admin/navigation', label: 'Navigation', group: 'Design', icon: 'menu', permission: 'page.view' },
    { href: '/admin/themes', label: 'Themes', group: 'Design', icon: 'layers', permission: 'page.view' },
    { href: '/admin/website', label: 'Build a website', group: 'AI', icon: 'sparkle', permission: 'page.edit' },
    { href: '/admin/seo', label: 'SEO overview', group: 'Site management', icon: 'globe', permission: 'page.view' },
    { href: '/admin/performance', label: 'Performance & updates', group: 'Site management', icon: 'cloud', permission: 'page.view' },
    { href: '/admin/settings/ai-connections', label: 'AI Connections', group: 'Settings', icon: 'sparkle', permission: 'page.edit' },
    {
        href: '/admin/content-types',
        label: 'Content types',
        group: 'Site management',
        icon: 'list',
        permission: 'page.view',
        matches: ['/admin/content-types/'],
    },
    { href: '/admin/settings', label: 'Site settings', group: 'Site management', icon: 'sliders', permission: 'page.publish' },
    { href: '/admin/settings/developer', label: 'Developer API', group: 'Site management', icon: 'key', permission: 'page.view' },
];
/** A content type this site defined and asked to see in the menu. */
export interface MenuContentType {
    key: string;
    plural: string;
    icon: string;
}

/**
 * A defined type joins the Content group as one entry, beside Pages and Posts. They are listed
 * after the built-in kinds and before the library, so the group keeps its shape however many
 * types a site adds, and "Content types" itself stays under Settings rather than in Content.
 */
export function visibleDestinations(can: Record<string, boolean>, contentTypes: MenuContentType[] = []): AdminDestination[] {
    const defined: AdminDestination[] = contentTypes.map((type) => ({
        href: `/admin/content/${type.key}`,
        label: type.plural,
        group: 'Content',
        icon: (type.icon || 'component') as IconName,
        permission: 'page.view',
    }));
    const items = [...adminDestinations];
    const after = items.findIndex((item) => item.href === '/admin/posts/categories');
    items.splice(after + 1, 0, ...defined);

    return items.filter((item) => can[item.permission]);
}

export function currentDestination(url: string, contentTypes: MenuContentType[] = []) {
    const path = url.split('?')[0]!;
    const defined = contentTypes.find((type) => path === `/admin/content/${type.key}`);
    if (defined) return { href: path, label: defined.plural, group: 'Content', icon: 'component' as IconName, permission: 'page.view' };

    return adminDestinations.find((item) => item.href === path || item.matches?.some((prefix) => path.startsWith(prefix)));
}
