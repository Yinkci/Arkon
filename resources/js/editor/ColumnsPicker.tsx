import { COLUMN_PRESETS, type ColumnPreset } from '@/arkon/editor/columns';

/** A small drawing of a layout: one bar per column, as wide as its share. */
export function LayoutGlyph({ count, tracks, className = 'h-5 w-12' }: { count: number; tracks: string | null; className?: string }) {
    const shares = tracks ? tracks.split(' ').map((t) => parseFloat(t)) : Array.from({ length: count }, () => 1);
    return (
        <span aria-hidden className={`flex gap-[2px] ${className}`}>
            {shares.map((share, i) => (
                <span key={i} className="rounded-[2px] bg-current opacity-70" style={{ flexGrow: share, flexBasis: 0 }} />
            ))}
        </span>
    );
}

/**
 * The layout picker shown when adding Columns: one click creates the Columns block with its
 * real, editable Column blocks and the chosen widths (phones stack them by default).
 */
export function ColumnsPicker({ onPick, onCancel, disabled }: { onPick(preset: ColumnPreset): void; onCancel(): void; disabled?: boolean }) {
    return (
        <div
            className="mt-2 rounded-md border border-accent-line bg-accent-soft/40 p-2"
            data-testid="columns-picker"
            role="group"
            aria-label="Choose a column layout"
        >
            <div className="mb-1.5 flex items-center justify-between">
                <p className="text-2xs font-medium">Choose a layout</p>
                <button type="button" onClick={onCancel} className="rounded px-1 text-2xs text-muted hover:bg-hover hover:text-fg">
                    Cancel
                </button>
            </div>
            <div className="grid grid-cols-5 gap-1">
                {COLUMN_PRESETS.map((preset) => (
                    <button
                        key={preset.id}
                        type="button"
                        disabled={disabled}
                        onClick={() => onPick(preset)}
                        aria-label={`Add Columns: ${preset.label}`}
                        title={preset.label}
                        data-testid={`columns-preset-${preset.id.replaceAll(' ', '-')}`}
                        className="flex h-12 flex-col items-center justify-center gap-1 rounded-md border border-line bg-surface px-1 text-3xs text-muted hover:border-accent hover:text-accent disabled:opacity-40"
                    >
                        <LayoutGlyph count={preset.count} tracks={preset.tracks} className="h-4 w-10" />
                        <span className="truncate">{preset.tracks ? preset.tracks.replaceAll('fr', '').replaceAll(' ', ':') : `${preset.count} equal`}</span>
                    </button>
                ))}
            </div>
            <p className="mt-1.5 text-3xs leading-snug text-muted">Columns stack on phones. Change the number and widths later in the properties.</p>
        </div>
    );
}
