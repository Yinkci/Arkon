// Short confirmations after an action ("Page moved to Trash."). One polite live region in the admin
// layout; messages leave on their own. Problems that need a decision stay inline (Notice), not here.
import { useEffect, useState } from 'react';
import { Icon } from './Icon';

type Toast = { id: number; message: string; tone: 'success' | 'error' };
let toasts: Toast[] = [];
let next = 1;
const listeners = new Set<(items: Toast[]) => void>();
const emit = () => listeners.forEach((listener) => listener(toasts));

export function toast(message: string, tone: Toast['tone'] = 'success') {
    const item = { id: next++, message, tone };
    toasts = [...toasts.slice(-2), item];
    emit();
    setTimeout(() => dismiss(item.id), tone === 'error' ? 8000 : 4000);
}

function dismiss(id: number) {
    toasts = toasts.filter((t) => t.id !== id);
    emit();
}

export function Toaster() {
    const [items, setItems] = useState(toasts);
    useEffect(() => {
        listeners.add(setItems);
        return () => void listeners.delete(setItems);
    }, []);
    return (
        <div aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2">
            {items.map((t) => (
                <div
                    key={t.id}
                    role={t.tone === 'error' ? 'alert' : 'status'}
                    data-testid="toast"
                    className="pointer-events-auto flex items-start gap-2.5 rounded-lg border border-line bg-surface px-4 py-3 text-sm text-fg shadow-pop"
                >
                    <Icon
                        name={t.tone === 'error' ? 'alert' : 'check'}
                        className={`mt-0.5 size-4 shrink-0 ${t.tone === 'error' ? 'text-danger' : 'text-live'}`}
                    />
                    <p className="min-w-0 flex-1">{t.message}</p>
                    <button type="button" onClick={() => dismiss(t.id)} className="-m-1 rounded p-1 text-faint hover:text-fg" aria-label="Dismiss">
                        <Icon name="close" className="size-3.5" />
                    </button>
                </div>
            ))}
        </div>
    );
}
