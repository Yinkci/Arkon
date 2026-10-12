import { useId } from 'react';
import { Link } from '@inertiajs/react';
import { selectedProvider, type ProviderConnection } from '@/lib/aiProvider';

export function AiProviderPicker({
    connection,
    value,
    onChange,
    disabled = false,
    label = 'AI provider for this task',
}: {
    connection: ProviderConnection;
    value?: string;
    onChange(value: string | undefined): void;
    disabled?: boolean;
    label?: string;
}) {
    const id = useId();
    const selected = selectedProvider(connection, value);
    const available = connection.providers?.filter((p) => p.ready) ?? [];
    const needsChoice = available.length > 1 || !!value;
    return (
        <div className="space-y-1 text-xs">
            {needsChoice ? (
                <>
                    <label htmlFor={id} className="ui-label">
                        {label}
                    </label>
                    <select id={id} className="ui-input" value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value || undefined)}>
                        <option value="">Choose a connected AI</option>
                        {connection.providers?.map((p) => (
                            <option key={p.id} value={p.id} disabled={!p.ready}>
                                {p.name}
                                {!p.ready ? ' — unavailable' : ''}
                            </option>
                        ))}
                    </select>
                </>
            ) : null}
            <p className="text-muted">
                {selected.ready ? 'Using ' + selected.providerName + '.' : selected.message}{' '}
                <Link className="text-accent underline" href="/admin/settings/ai-connections">
                    {available.length ? 'AI Connections' : 'Connect an AI'}
                </Link>
            </p>
        </div>
    );
}
