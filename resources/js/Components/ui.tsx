// Shared admin building blocks: one look for buttons, fields, statuses, notices and empty
// states across the dashboard, pages, design screen and both editors. Styling comes from
// the tokens in resources/css/app.css (light and dark).
import { Link } from '@inertiajs/react';
import { forwardRef, useId, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Icon, type IconName } from './Icon';

// Primary is ink (the Arkon mark's colour): one per view, for the action that moves work
// forward. Cobalt is kept for selection, focus and links, so it never competes with it.
type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'quiet-danger';
type Size = 'sm' | 'md';

const VARIANT: Record<Variant, string> = {
    primary: 'bg-ink text-ink-fg hover:bg-ink-hover active:bg-ink shadow-hairline',
    secondary: 'border border-line-strong bg-surface text-fg hover:bg-hover hover:border-faint shadow-hairline',
    ghost: 'text-muted hover:bg-hover hover:text-fg',
    danger: 'bg-danger text-white hover:opacity-90 shadow-hairline dark:text-surface',
    'quiet-danger': 'text-danger hover:bg-danger-soft',
};
const SIZE: Record<Size, string> = {
    sm: 'h-7 gap-1.5 px-2.5 text-xs',
    md: 'h-control gap-2 px-control text-control',
};
const ICON_SIZE: Record<Size, string> = { sm: 'size-3.5', md: 'size-4' };

export function buttonClass(variant: Variant = 'secondary', size: Size = 'md', extra = '') {
    return `inline-flex shrink-0 select-none items-center justify-center rounded-md font-medium whitespace-nowrap transition-[background-color,border-color,color,box-shadow,transform] duration-100 ease-snap active:translate-y-px disabled:pointer-events-none disabled:opacity-45 ${VARIANT[variant]} ${SIZE[size]} ${extra}`;
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    size?: Size;
    icon?: IconName;
    /** Shows a spinner instead of the icon (the label stays, e.g. "Saving…"). */
    busy?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'secondary', size = 'md', icon, busy, className = '', children, type = 'button', ...rest },
    ref,
) {
    return (
        <button ref={ref} type={type} className={buttonClass(variant, size, className)} {...rest}>
            {busy ? <Spinner className={ICON_SIZE[size]} /> : icon && <Icon name={icon} className={ICON_SIZE[size]} />}
            {children}
        </button>
    );
});

export function ButtonLink({
    href,
    variant = 'secondary',
    size = 'md',
    icon,
    children,
    className = '',
}: {
    href: string;
    variant?: Variant;
    size?: Size;
    icon?: IconName;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Link href={href} className={buttonClass(variant, size, className)}>
            {icon && <Icon name={icon} className={ICON_SIZE[size]} />}
            {children}
        </Link>
    );
}

/** A square icon-only button; `label` is its accessible name and tooltip. */
export const IconButton = forwardRef<HTMLButtonElement, Omit<ButtonProps, 'children'> & { icon: IconName; label: string }>(function IconButton(
    { icon, label, variant = 'ghost', size = 'md', className = '', type = 'button', ...rest },
    ref,
) {
    const square = size === 'sm' ? 'size-7' : 'size-control';
    return (
        <button
            ref={ref}
            type={type}
            aria-label={label}
            title={label}
            className={`inline-flex shrink-0 items-center justify-center rounded-md transition-colors disabled:pointer-events-none disabled:opacity-35 ${VARIANT[variant]} ${square} ${className}`}
            {...rest}
        >
            <Icon name={icon} className={ICON_SIZE[size]} />
        </button>
    );
});

export function Spinner({ className = 'size-3.5' }: { className?: string }) {
    return (
        <svg viewBox="0 0 16 16" className={`shrink-0 animate-spin ${className}`} aria-hidden="true">
            <circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" strokeOpacity="0.25" strokeWidth="2" />
            <path d="M14 8a6 6 0 0 0-6-6" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}

/** A labelled field: the label, the control (given the id), and an optional hint or error. */
export function Field({
    label,
    hint,
    error,
    children,
    className = '',
    labelAside,
}: {
    label: ReactNode;
    hint?: ReactNode;
    error?: string | null;
    children: (props: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode;
    className?: string;
    labelAside?: ReactNode;
}) {
    const id = useId();
    const describedBy = error || hint ? `${id}-hint` : undefined;
    return (
        <div className={className}>
            <div className="flex items-baseline justify-between gap-2">
                <label htmlFor={id} className="ui-label">
                    {label}
                </label>
                {labelAside}
            </div>
            {children({ id, describedBy, invalid: !!error })}
            {(error || hint) && (
                <p id={`${id}-hint`} role={error ? 'alert' : undefined} className={`mt-1 text-xs ${error ? 'text-danger' : 'text-muted'}`}>
                    {error ?? hint}
                </p>
            )}
        </div>
    );
}

/** A row of mutually exclusive choices (aria-pressed buttons). */
export function Segmented<T extends string>({
    label,
    value,
    options,
    onChange,
    size = 'md',
    className = '',
}: {
    label: string;
    value: T;
    options: { value: T; label: string; icon?: IconName; hideLabel?: boolean; badge?: ReactNode }[];
    onChange(value: T): void;
    size?: Size;
    className?: string;
}) {
    return (
        <div role="group" aria-label={label} className={`inline-flex rounded-md border border-line bg-sunken p-0.5 ${className}`}>
            {options.map((option) => {
                const active = option.value === value;
                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        title={option.hideLabel ? option.label : undefined}
                        aria-label={option.hideLabel ? option.label : undefined}
                        onClick={() => onChange(option.value)}
                        className={`inline-flex items-center justify-center gap-1.5 rounded font-medium whitespace-nowrap transition-[background-color,color,box-shadow] duration-100 ${size === 'sm' ? 'h-5.5 px-2 text-2xs' : 'h-7 px-2.5 text-xs'} ${
                            active ? 'bg-surface text-fg shadow-raise' : 'text-muted hover:text-fg'
                        }`}
                    >
                        {option.icon && <Icon name={option.icon} className="size-3.5" />}
                        {!option.hideLabel && option.label}
                        {option.badge}
                    </button>
                );
            })}
        </div>
    );
}

export type Tone = 'neutral' | 'live' | 'changed' | 'danger' | 'accent' | 'ai' | 'site';

const TONE_TEXT: Record<Tone, string> = {
    neutral: 'text-muted',
    live: 'text-live',
    changed: 'text-changed',
    danger: 'text-danger',
    accent: 'text-accent',
    ai: 'text-ai',
    site: 'text-site',
};
const TONE_BG: Record<Tone, string> = {
    neutral: 'bg-sunken text-muted',
    live: 'bg-live-soft text-live',
    changed: 'bg-changed-soft text-changed',
    danger: 'bg-danger-soft text-danger',
    accent: 'bg-accent-soft text-accent',
    ai: 'bg-ai-soft text-ai',
    site: 'bg-site-soft text-site',
};

/** A status with an icon and words (never colour alone). */
export function StatusPill({
    tone,
    icon,
    children,
    busy,
    className = '',
    ...rest
}: {
    tone: Tone;
    icon?: IconName;
    children: ReactNode;
    busy?: boolean;
    className?: string;
    [data: `data-${string}`]: string | undefined;
}) {
    return (
        <span className={`inline-flex h-6 max-w-full items-center gap-1.5 rounded-md px-2 text-xs font-medium ${TONE_BG[tone]} ${className}`} {...rest}>
            {busy ? <Spinner className="size-3" /> : icon && <Icon name={icon} className="size-3" />}
            <span className="truncate">{children}</span>
        </span>
    );
}

export function toneText(tone: Tone) {
    return TONE_TEXT[tone];
}

/** A banner for outcomes and problems: icon, message, optional details and actions. */
export function Notice({
    tone,
    title,
    children,
    onDismiss,
    action,
    className = '',
    ...rest
}: {
    tone: 'error' | 'success' | 'info' | 'warning' | 'ai' | 'site';
    title?: ReactNode;
    children?: ReactNode;
    onDismiss?(): void;
    action?: ReactNode;
    className?: string;
    [data: `data-${string}`]: string | undefined;
}) {
    const styles: [string, string, IconName] = (
        {
            error: ['border-danger/30 bg-danger-soft', 'text-danger', 'alert' as const],
            success: ['border-live/25 bg-live-soft', 'text-live', 'check' as const],
            info: ['border-accent-line bg-accent-soft', 'text-accent', 'info' as const],
            warning: ['border-changed/30 bg-changed-soft', 'text-changed', 'alert' as const],
            ai: ['border-ai/25 bg-ai-soft', 'text-ai', 'sparkle' as const],
            site: ['border-site/25 bg-site-soft', 'text-site', 'globe' as const],
        } satisfies Record<string, [string, string, IconName]>
    )[tone];
    return (
        <div
            role={tone === 'error' ? 'alert' : 'status'}
            className={`flex items-start gap-3 rounded-lg border p-3 text-ui text-fg ${styles[0]} ${className}`}
            {...rest}
        >
            <Icon name={styles[2]} className={`mt-0.5 size-4 ${styles[1]}`} />
            <div className="min-w-0 flex-1 space-y-1">
                {title && <p className="font-semibold">{title}</p>}
                {children}
            </div>
            {action}
            {onDismiss && <IconButton icon="close" label="Dismiss" size="sm" onClick={onDismiss} className="-my-1 -mr-1" />}
        </div>
    );
}

export function EmptyState({
    icon,
    title,
    children,
    action,
    compact,
}: {
    icon: IconName;
    title: string;
    children?: ReactNode;
    action?: ReactNode;
    compact?: boolean;
}) {
    return (
        <div className={`flex flex-col items-center text-center ${compact ? 'px-4 py-6' : 'px-6 py-12'}`}>
            <span className="grid size-9 place-items-center rounded-lg border border-line bg-raised text-muted shadow-hairline">
                <Icon name={icon} className="size-4" />
            </span>
            <p className="mt-3 t-title">{title}</p>
            {children && <div className="mt-1 max-w-sm t-meta">{children}</div>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

/** A titled panel section; collapsible when `collapsible` (closed by default unless `defaultOpen`). */
export function PanelSection({
    title,
    aside,
    children,
    collapsible,
    defaultOpen = true,
    className = '',
    ...rest
}: {
    title: ReactNode;
    aside?: ReactNode;
    children: ReactNode;
    collapsible?: boolean;
    defaultOpen?: boolean;
    className?: string;
    [data: `data-${string}`]: string | undefined;
}) {
    if (collapsible) {
        return (
            <details open={defaultOpen || undefined} className={`group border-t border-line ${className}`} {...rest}>
                <summary className="flex h-10 cursor-pointer list-none items-center gap-2 px-4 text-xs font-semibold text-fg select-none hover:bg-hover [&::-webkit-details-marker]:hidden">
                    <Icon name="chevronRight" className="size-3.5 text-faint transition-transform group-open:rotate-90" />
                    <span className="min-w-0 flex-1">{title}</span>
                    {aside}
                </summary>
                <div className="space-y-3 px-4 pb-4">{children}</div>
            </details>
        );
    }
    return (
        <section className={`border-t border-line p-4 first:border-t-0 ${className}`} {...rest}>
            <div className="mb-3 flex min-h-5 items-center gap-2">
                <h3 className="min-w-0 flex-1 text-xs font-semibold text-fg">{title}</h3>
                {aside}
            </div>
            <div className="space-y-3">{children}</div>
        </section>
    );
}

export function Kbd({ children }: { children: ReactNode }) {
    return (
        <kbd className="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-sm border border-line bg-raised px-1 font-sans text-3xs text-muted">
            {children}
        </kbd>
    );
}

/**
 * The content column of an admin screen: the same gutters and top rhythm everywhere, so
 * page titles line up when moving between screens. `wide` is for screens built around a
 * large preview or a two-pane editor.
 */
export function PageShell({ children, width = 'default', className = '' }: { children: ReactNode; width?: 'default' | 'wide'; className?: string }) {
    return <div className={`ak-page ${width === 'wide' ? 'max-w-[96rem]' : ''} ${className}`}>{children}</div>;
}

/** A section heading inside a screen: title, optional one-line description and a trailing action. */
export function SectionHeader({
    id,
    title,
    description,
    action,
    className = '',
}: {
    id?: string;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div className={`flex min-h-8 items-end justify-between gap-4 ${className}`}>
            <div className="min-w-0">
                <h2 id={id} className="t-section">
                    {title}
                </h2>
                {description && <p className="mt-0.5 t-meta">{description}</p>}
            </div>
            {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
        </div>
    );
}

/** A distinct object or group on a page: one surface, one hairline, no nesting of panels in panels. */
export function Panel({
    children,
    className = '',
    padded = true,
    as: Tag = 'div',
    ...rest
}: {
    children: ReactNode;
    className?: string;
    padded?: boolean;
    as?: 'div' | 'section' | 'article' | 'aside';
    [attr: `aria-${string}` | `data-${string}`]: string | undefined;
}) {
    return (
        <Tag className={`rounded-lg border border-line bg-surface shadow-hairline ${padded ? 'p-4 sm:p-5' : ''} ${className}`} {...rest}>
            {children}
        </Tag>
    );
}

/** A loading placeholder in the shape of the content it stands for. */
export function Skeleton({ className = 'h-4 w-full' }: { className?: string }) {
    return <span aria-hidden="true" className={`block animate-pulse rounded-md bg-sunken ${className}`} />;
}

/** Initials in a small square, for people in lists and the account menu. */
export function Avatar({ name, className = 'size-7 text-2xs' }: { name?: string | null; className?: string }) {
    const initials =
        (name ?? '?')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]!.toUpperCase())
            .join('') || '?';
    return (
        <span aria-hidden="true" className={`grid shrink-0 place-items-center rounded-md bg-sunken font-semibold text-muted ${className}`}>
            {initials}
        </span>
    );
}

/** The Arkon mark (follows the theme). */
export function ArkonMark({ className = 'size-6', inverse }: { className?: string; /** On the dark navigation rail. */ inverse?: boolean }) {
    return (
        <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
            <rect width="24" height="24" rx="6" fill={inverse ? 'var(--ak-nav-fg)' : 'var(--ak-fg)'} />
            <path
                d="M6.5 17.5 12 6l5.5 11.5M8.6 13.2h6.8"
                fill="none"
                stroke={inverse ? 'var(--ak-nav)' : 'var(--ak-canvas)'}
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
