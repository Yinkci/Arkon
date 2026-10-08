import { useId, useRef, useState } from 'react';
import { Icon } from '@/Components/Icon';
import { Button } from '@/Components/ui';
import type { MediaInfo } from '@/types';

/** A readable name for an uploaded image: its original file name when known. */
export function mediaName(media: MediaInfo): string {
    if (media.name) return media.name;
    return media.url.split('/').pop()?.split('?')[0] ?? 'Image';
}

/**
 * Choosing an image: the current one, the site's library as thumbnails with names, and
 * upload. Alternative text is edited next to it by the caller.
 */
export function MediaPicker({
    value,
    media,
    canEdit,
    canUpload,
    onChoose,
    onUpload,
}: {
    value: string | null;
    media: MediaInfo[];
    canEdit: boolean;
    canUpload: boolean;
    onChoose(assetId: string | null): void;
    onUpload(file: File): Promise<MediaInfo | null>;
}) {
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [browsing, setBrowsing] = useState(false);
    const libraryId = useId();
    const current = value ? media.find((m) => m.id === value) : undefined;

    return (
        <div className="space-y-2" data-testid="media-picker">
            <div className="flex items-center gap-3 rounded-lg border border-line bg-raised p-2">
                <div className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-md bg-sunken text-faint">
                    {current ? <img src={current.url} alt="" className="size-full object-cover" /> : <Icon name="image" className="size-5" />}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[0.8125rem] font-medium">
                        {current ? mediaName(current) : value ? 'Image not in the library list' : 'No image'}
                    </p>
                    {current && (
                        <p className="text-[11px] text-muted tabular-nums">
                            {current.width} × {current.height} px
                        </p>
                    )}
                </div>
                {value && canEdit && (
                    <button type="button" onClick={() => onChoose(null)} className="rounded px-1.5 py-1 text-xs text-danger hover:bg-danger-soft">
                        Remove image
                    </button>
                )}
            </div>
            <div className="flex gap-1.5">
                {canUpload && canEdit && (
                    <>
                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/avif,image/gif"
                            className="sr-only"
                            aria-label="Upload image"
                            onChange={async (e) => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (!file) return;
                                setUploading(true);
                                try {
                                    const asset = await onUpload(file);
                                    if (asset) onChoose(asset.id);
                                } finally {
                                    setUploading(false);
                                }
                            }}
                        />
                        <Button size="sm" icon="upload" busy={uploading} disabled={uploading} onClick={() => fileRef.current?.click()} className="flex-1">
                            {uploading ? 'Uploading…' : 'Upload'}
                        </Button>
                    </>
                )}
                {media.length > 0 && canEdit && (
                    <Button
                        size="sm"
                        icon="image"
                        aria-expanded={browsing}
                        aria-controls={libraryId}
                        onClick={() => setBrowsing((open) => !open)}
                        className="flex-1"
                    >
                        {browsing ? 'Close library' : 'Choose from library'}
                    </Button>
                )}
            </div>
            {browsing && (
                <ul
                    id={libraryId}
                    aria-label="Image library"
                    className="grid max-h-56 grid-cols-3 gap-1.5 overflow-y-auto rounded-lg border border-line p-1.5"
                    data-testid="media-library"
                >
                    {media.map((m) => {
                        const chosen = m.id === value;
                        return (
                            <li key={m.id}>
                                <button
                                    type="button"
                                    aria-pressed={chosen}
                                    aria-label={`Use ${mediaName(m)} (${m.width} × ${m.height})`}
                                    title={mediaName(m)}
                                    onClick={() => {
                                        onChoose(m.id);
                                        setBrowsing(false);
                                    }}
                                    className={`group block w-full overflow-hidden rounded-md border text-left ${chosen ? 'border-accent ring-2 ring-accent-soft' : 'border-line hover:border-faint'}`}
                                >
                                    <img src={m.url} alt="" loading="lazy" className="aspect-[4/3] w-full bg-sunken object-cover" />
                                    <span className="block truncate px-1 py-0.5 text-[10px] text-muted">{mediaName(m)}</span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
