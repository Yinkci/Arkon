import type { ReactNode } from 'react';
export function AdminPageHeader({ title, description, actions }: { title: string; description: string; actions?: ReactNode }) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="max-w-2xl">
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                <p className="mt-1 text-sm text-muted">{description}</p>
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </header>
    );
}
