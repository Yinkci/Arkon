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
            className="m-auto w-full max-w-md rounded-xl border border-zinc-200 p-0 shadow-xl backdrop:bg-zinc-900/40"
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
                <div className="space-y-2 text-sm text-zinc-700">{children}</div>
                {requireText && (
                    <div>
                        <label htmlFor={inputId} className="block text-sm font-medium">
                            Type <code className="rounded bg-zinc-100 px-1">{requireText}</code> to confirm
                        </label>
                        <input
                            id={inputId}
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                            autoComplete="off"
                            className="mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                        />
                    </div>
                )}
                {error && (
                    <p role="alert" className="text-sm text-red-700">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={close} disabled={pending} className="rounded-md border border-zinc-300 px-3 py-1.5 text-sm hover:bg-zinc-50">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={!ready || pending}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50 ${tone === 'danger' ? 'bg-red-600 hover:bg-red-500' : 'bg-indigo-600 hover:bg-indigo-500'}`}
                    >
                        {pending ? 'Working…' : confirmLabel}
                    </button>
                </div>
            </form>
        </dialog>
    );
}
