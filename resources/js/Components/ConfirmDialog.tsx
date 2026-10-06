import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

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
            className="m-auto w-full max-w-md rounded-xl text-left whitespace-normal border border-line bg-surface p-0 text-fg shadow-pop backdrop:bg-black/40"
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
                <h2 id={titleId} className="text-lg font-semibold">
                    {title}
                </h2>
                <div className="space-y-2 text-sm text-muted">{children}</div>
                {requireText && (
                    <div>
                        <label htmlFor={inputId} className="block text-sm font-medium">
                            Type <code className="rounded bg-line px-1">{requireText}</code> to confirm
                        </label>
                        <input
                            id={inputId}
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                            autoComplete="off"
                            className="mt-1 w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-sm text-fg outline-none placeholder:text-faint focus:border-accent focus:ring-2 focus:ring-accent-soft"
                        />
                    </div>
                )}
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={close}
                        disabled={pending}
                        className="rounded-md border border-line-strong px-3 py-1.5 text-sm hover:bg-line/50"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={!ready || pending}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50 ${tone === 'danger' ? 'bg-danger text-surface hover:opacity-90' : 'bg-accent text-accent-fg hover:bg-accent-hover'}`}
                    >
                        {pending ? 'Working…' : confirmLabel}
                    </button>
                </div>
            </form>
        </dialog>
    );
}
