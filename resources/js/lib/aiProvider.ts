export interface ProviderConnection {
    ready: boolean;
    message: string;
    selectionState?: 'automatic' | 'choice_required' | 'unavailable';
    checkedAt?: string;
    provider?: string | null;
    providerName?: string | null;
    providers?: { id: string; name: string; ready: boolean; message?: string }[];
}

/** Resolve only the requested provider. An unavailable selection never falls back. */
export function selectedProvider(connection: ProviderConnection, provider?: string) {
    if (!provider || provider === connection.provider) return connection;
    const selected = connection.providers?.find((p) => p.id === provider);
    return {
        provider,
        providerName: selected?.name ?? provider,
        ready: selected?.ready ?? false,
        message: selected?.message ?? (selected?.ready ? selected.name + ' is connected.' : (selected?.name ?? provider) + ' is unavailable.'),
    };
}
