import type { IconName } from '@/Components/Icon';
export interface AdminDestination {
    href: string;
    label: string;
    group: string;
    icon: IconName;
    permission: string;
    matches?: string[];
}
// One registry drives sidebar groups, breadcrumbs and navigation commands.
export const adminDestinations: AdminDestination[] = [
    { href: '/admin', label: 'Dashboard', group: 'Overview', icon: 'home', permission: 'page.view' },
    { href: '/admin/pages', label: 'Pages', group: 'Content', icon: 'pages', permission: 'page.view', matches: ['/admin/editor/'] },
    { href: '/admin/media', label: 'Media library', group: 'Content', icon: 'image', permission: 'media.view' },
    { href: '/admin/forms', label: 'Forms & enquiries', group: 'Content', icon: 'pages', permission: 'page.view' },
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
    { href: '/admin/settings', label: 'Site settings', group: 'Site management', icon: 'sliders', permission: 'page.publish' },
];
export const visibleDestinations = (can: Record<string, boolean>) => adminDestinations.filter((item) => can[item.permission]);
export function currentDestination(url: string) {
    const path = url.split('?')[0]!;
    return adminDestinations.find((item) => item.href === path || item.matches?.some((prefix) => path.startsWith(prefix)));
}
