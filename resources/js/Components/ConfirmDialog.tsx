import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Icon } from './Icon';
import { Button } from './ui';

export interface ConfirmDialogProps {
    open: boolean;
    title: string;
    children: ReactNode;
    confirmLabel: string;
    /** When set, the user must type this exact text before confirming. */
    requireText?: string;
    tone?: 'danger' | 'default';
    /** Resolves to an error message to show, or null when done. */
    onConfirm(): Promise<string | null>;
    onClose(): void;
}

/** Modal confirmation built on <dialog>: focus is trapped and Escape cancels. */
export function ConfirmDialog({ open, title, children, confirmLabel, requireText, tone = 'default', onConfirm, onClose }: ConfirmDialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const [typed, setTyped] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const inputId = useId();
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
    }, [open]);

    const reset = () => {
        setTyped('');
        setError(null);
        setPending(false);
    };
    const close = () => {
        if (pending) return;
        reset();
        onClose();
    };
    const ready = !requireText || typed === requireText;

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                close();
            }}
            className="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-line bg-surface p-0 text-left whitespace-normal text-fg shadow-pop backdrop:bg-scrim"
        >
            <form
                method="dialog"
                className="space-y-4 p-6"
                onSubmit={async (event) => {
                    event.preventDefault();
                    if (!ready || pending) return;
                    setPending(true);
                    setError(null);
                    const message = await onConfirm();
                    setPending(false);
                    if (message) setError(message);
                    else {
                        reset();
                        onClose();
                    }
                }}
            >
                <h2 id={titleId} className="flex items-center gap-2 text-base font-semibold">
                    {tone === 'danger' && <Icon name="alert" className="size-4 text-danger" />}
                    {title}
                </h2>
                <div className="space-y-2 text-sm text-muted">{children}</div>
                {requireText && (
                    <div>
                        <label htmlFor={inputId} className="ui-label">
                            Type <code className="rounded bg-sunken px-1 font-mono text-fg">{requireText}</code> to confirm
                        </label>
                        <input id={inputId} value={typed} onChange={(e) => setTyped(e.target.value)} autoComplete="off" className="ui-input" />
                    </div>
                )}
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2 pt-1">
                    <Button onClick={close} disabled={pending}>
                        Cancel
                    </Button>
                    <Button type="submit" variant={tone === 'danger' ? 'danger' : 'primary'} busy={pending} disabled={!ready || pending}>
                        {pending ? 'Working…' : confirmLabel}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
