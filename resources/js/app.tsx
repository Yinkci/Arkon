import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');

void createInertiaApp({
    title: (title) => (title ? `${title} · Arkon` : 'Arkon'),
    resolve: async (name) => {
        const load = pages[`./Pages/${name}.tsx`];
        if (!load) throw new Error(`Unknown page ${name}`);
        return (await load()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#4f46e5' },
});
