import '../css/app.css';
import { installThemeDefinitions, type ComponentManifest } from './arkon/components/registry';
import { usePage, createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');
const resolved = new Map<string, ComponentType<Record<string, unknown>>>();

void createInertiaApp({
    title: (title) => (title ? `${title} · Arkon` : 'Arkon'),
    resolve: async (name) => {
        if (resolved.has(name)) return resolved.get(name)!;
        const load = pages[`./Pages/${name}.tsx`];
        if (!load) throw new Error(`Unknown page ${name}`);
        const Page = (await load()).default;
        const SyncedPage = function PageWithThemeRegistry(props: Record<string, unknown>) {
            const shared = usePage().props;
            installThemeDefinitions((shared.themeComponents ?? []) as ComponentManifest[], (shared.themeAddableTypes ?? []) as string[]);
            return <Page {...props} />;
        };
        resolved.set(name, SyncedPage);
        return SyncedPage;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#4f46e5' },
});
