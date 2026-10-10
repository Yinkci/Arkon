import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Icon } from './Icon';
import { Button } from './ui';

export interface ConfirmDialogProps {
    open: boolean;
    title: string;
    children?: ReactNode;
    confirmLabel: string;
    /** Shown on the confirm button while the action runs, e.g. "Moving to Trash…". */
    busyLabel?: string;
    tone?: 'danger' | 'default';
    /** Resolves to an error message to show, or null when done. */
    onConfirm(): Promise<string | null>;
    onClose(): void;
}

/**
 * The one confirmation dialog of the admin (Move to Trash, Restore, Delete permanently, Unpublish):
 * built on <dialog>, so focus is trapped and Escape cancels. While the action runs it cannot be
 * closed or confirmed twice; a failure stays in the dialog with the server's reason.
 */
export function ConfirmDialog({ open, title, children, confirmLabel, busyLabel = 'Working…', tone = 'default', onConfirm, onClose }: ConfirmDialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
    }, [open]);

    const close = () => {
        if (pending) return;
        setError(null);
        onClose();
    };

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
                    if (pending) return;
                    setPending(true);
                    setError(null);
                    let message: string | null;
                    try {
                        message = await onConfirm();
                    } catch {
                        message = 'The outcome could not be confirmed (network problem). Check the list, then try again.';
                    }
                    setPending(false);
                    if (message) setError(message);
                    else onClose();
                }}
            >
                <h2 id={titleId} className="flex items-center gap-2 text-base font-semibold">
                    {tone === 'danger' && <Icon name="alert" className="size-4 text-danger" />}
                    {title}
                </h2>
                {children && <div className="space-y-2 text-sm text-muted">{children}</div>}
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2 pt-1">
                    <Button onClick={close} disabled={pending}>
                        Cancel
                    </Button>
                    <Button type="submit" variant={tone === 'danger' ? 'danger' : 'primary'} busy={pending} disabled={pending}>
                        {pending ? busyLabel : confirmLabel}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
