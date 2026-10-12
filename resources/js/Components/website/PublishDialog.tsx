// Publishing a website: one button opens this dialog, which runs the readiness check (a dry run of publishing)
// and shows its outcome right there: blockers stop publishing, warnings are listed before "Publish anyway".
import { useEffect, useId, useRef, useState } from 'react';
import { Icon } from '@/Components/Icon';
import { Button, Spinner } from '@/Components/ui';
import type { Readiness } from './types';

export function PublishDialog({
    open,
    check,
    canPublish,
    onRecheck,
    onPublish,
    onClose,
}: {
    open: boolean;
    /** null while the check runs. */
    check: Readiness | null;
    canPublish: boolean;
    onRecheck(): void;
    /** Resolves to an error message to show, or null when published. */
    onPublish(): Promise<string | null>;
    onClose(): void;
}) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
        if (!open) setError(null);
    }, [open]);

    const close = () => {
        if (!pending) onClose();
    };
    const publish = async () => {
        setPending(true);
        setError(null);
        let message: string | null;
        try {
            message = await onPublish();
        } catch {
            message = 'Publishing could not be confirmed. Publish again to check the original result.';
        }
        setPending(false);
        if (message) setError(message);
        else onClose();
    };
    const warnings = (check?.pages ?? []).filter((p) => p.warnings.length > 0);
    const warningCount = warnings.reduce((n, p) => n + p.warnings.length, 0);
    const title = !check
        ? 'Checking your website…'
        : !check.ready
          ? 'Fix these before publishing'
          : !canPublish
            ? 'Ready to publish'
            : warningCount
              ? `Publish with ${warningCount} warning${warningCount === 1 ? '' : 's'}?`
              : 'Publish website?';

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(e) => {
                e.preventDefault();
                close();
            }}
            className="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl border border-line bg-surface p-0 text-left whitespace-normal text-fg shadow-pop backdrop:bg-scrim"
        >
            <div className="space-y-4 p-6">
                <h2 id={titleId} className="flex items-center gap-2 text-base font-semibold">
                    {!check ? (
                        <Spinner className="size-4" />
                    ) : (
                        <Icon
                            name={!check.ready ? 'alert' : warningCount ? 'alert' : 'check'}
                            className={`size-4 ${!check.ready ? 'text-danger' : warningCount ? 'text-changed' : 'text-live'}`}
                        />
                    )}
                    {title}
                </h2>
                <div aria-live="polite" className="ak-scroll max-h-[55vh] space-y-3 overflow-y-auto text-sm text-muted">
                    {!check ? (
                        <p>Arkon is preparing every page exactly as publishing would, without making anything live.</p>
                    ) : (
                        <>
                            {check.ready && canPublish && (
                                <p>
                                    {check.pages.length} page{check.pages.length === 1 ? '' : 's'}, with the shared header, footer, navigation, branding and
                                    form, go live together.
                                </p>
                            )}
                            {check.issues.length > 0 && (
                                <ul className="space-y-1" aria-label="Blocking problems">
                                    {check.issues.map((s, i) => (
                                        <li key={i} className="flex items-start gap-2 text-danger">
                                            <Icon name="alert" className="mt-0.5 size-3.5 shrink-0" />
                                            {s}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {warnings.length > 0 && (
                                <div className="space-y-2" aria-label="Warnings" role="group">
                                    {warnings.map((p) => (
                                        <div key={p.id}>
                                            <p className="font-medium text-fg">
                                                {p.title} <span className="font-mono text-xs font-normal text-muted">{p.path}</span>
                                            </p>
                                            <ul className="mt-0.5 space-y-0.5">
                                                {p.warnings.map((w, i) => (
                                                    <li key={i} className="flex items-start gap-2">
                                                        <Icon name="alert" className="mt-0.5 size-3.5 shrink-0 text-changed" />
                                                        {w}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ))}
                                    <p className="text-xs">Warnings don’t stop publishing. Fix them in the builder first if they matter.</p>
                                </div>
                            )}
                            {check.ready && warningCount === 0 && <p className="text-live">No problems found.</p>}
                            <details className="text-xs">
                                <summary className="cursor-pointer hover:text-fg">Page sizes</summary>
                                <ul className="mt-1.5 space-y-0.5 t-num">
                                    {check.pages.map((p) => (
                                        <li key={p.id}>
                                            <span className="text-fg">{p.title}</span> · {(p.htmlBytes / 1024).toFixed(1)} KB HTML ·{' '}
                                            {(p.cssBytes / 1024).toFixed(1)} KB CSS · {p.scripts} scripts
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-1.5">{check.note}</p>
                            </details>
                        </>
                    )}
                </div>
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2 pt-1">
                    <Button onClick={close} disabled={pending}>
                        {check && check.ready && canPublish ? 'Cancel' : 'Close'}
                    </Button>
                    {check && !check.ready && <Button onClick={onRecheck}>Check again</Button>}
                    {check?.ready && canPublish && (
                        <Button variant="primary" busy={pending} disabled={pending} onClick={() => void publish()}>
                            {pending ? 'Publishing…' : warningCount ? 'Publish anyway' : 'Publish website'}
                        </Button>
                    )}
                </div>
            </div>
        </dialog>
    );
}
