import { useEffect, useId, useRef } from 'react';
import { Button } from '@/Components/ui';

/**
 * Confirmation before deleting a block that holds content: names the block and says its contents
 * go too. Cancel (or Escape) changes nothing; Delete is one undo step.
 */
export function DeleteDialog(props: { open: boolean; title: string; contents: number; onConfirm(): void; onCancel(): void }) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (props.open && !dialog.open) dialog.showModal?.();
        if (!props.open && dialog.open) dialog.close();
    }, [props.open]);
    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            data-testid="delete-dialog"
            onCancel={(event) => {
                event.preventDefault();
                props.onCancel();
            }}
            className="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-line bg-surface p-0 text-left text-fg shadow-pop backdrop:bg-black/45"
        >
            <div className="space-y-4 p-6">
                <h2 id={titleId} className="text-base font-semibold">
                    {props.title}?
                </h2>
                <p className="text-sm text-muted">
                    The {props.contents} block{props.contents === 1 ? '' : 's'} inside it will be deleted too. Undo (Ctrl+Z) brings everything back.
                </p>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button onClick={props.onCancel} data-testid="delete-cancel" autoFocus>
                        Cancel
                    </Button>
                    <Button variant="danger" icon="trash" onClick={props.onConfirm} data-testid="delete-confirm">
                        {props.title}
                    </Button>
                </div>
            </div>
        </dialog>
    );
}
