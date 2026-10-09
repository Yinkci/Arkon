import type { ReactNode } from 'react';

/** The top of every admin screen: title, one line on what the screen is for, and its actions (aligned to the title). */
export function AdminPageHeader({
    title,
    description,
    actions,
    eyebrow,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    eyebrow?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
            <div className="min-w-0 max-w-2xl">
                {eyebrow && <p className="mb-1.5 t-meta">{eyebrow}</p>}
                <h1 className="t-page">{title}</h1>
                {description && <p className="mt-1.5 text-ui text-muted">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}
