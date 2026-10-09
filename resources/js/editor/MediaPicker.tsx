import { useEffect, useId, useRef, useState } from 'react';
import { api } from '@/lib/api';
import { Icon } from '@/Components/Icon';
import { Button } from '@/Components/ui';
import type { MediaInfo } from '@/types';
import { uploadHelp } from '@/lib/mediaPolicy';

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
    scopeKey = '',
}: {
    value: string | null;
    media: MediaInfo[];
    canEdit: boolean;
    canUpload: boolean;
    onChoose(assetId: string | null, asset?: MediaInfo): void;
    onUpload(file: File, progress?: (percent: number) => void): Promise<MediaInfo | null>;
    scopeKey?: string;
}) {
    const busyRef = useRef(false);
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const mounted = useRef(false);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const latest = useRef({ scopeKey, value, canEdit, canUpload, onChoose });
    const generation = useRef(0);
    if (latest.current.scopeKey !== scopeKey || latest.current.value !== value || latest.current.canEdit !== canEdit || latest.current.canUpload !== canUpload)
        generation.current++;
    latest.current = { scopeKey, value, canEdit, canUpload, onChoose };
    const [browsing, setBrowsing] = useState(false);
    const [query, setQuery] = useState(''),
        [page, setPage] = useState(1),
        [pages, setPages] = useState(1),
        [results, setResults] = useState<MediaInfo[] | null>(null),
        [searching, setSearching] = useState(false);
    useEffect(() => {
        if (!browsing) return;
        let stale = false;
        const timer = setTimeout(async () => {
            setSearching(true);
            try {
                const result = await api<{ items: MediaInfo[]; pages: number; page: number }>(`/media-library?q=${encodeURIComponent(query)}&page=${page}`);
                if (stale) return;
                if (result.ok) {
                    setResults(result.data.items);
                    setPages(result.data.pages);
                } else setError(result.message);
            } catch {
                if (!stale) setError('The library could not be loaded. Try again.');
            } finally {
                if (!stale) setSearching(false);
            }
        }, 250);
        return () => {
            stale = true;
            clearTimeout(timer);
        };
    }, [query, page, browsing, media]);
    const choices = results ?? media;
    const libraryId = useId();
    const current = value ? [...media, ...(results ?? [])].find((m) => m.id === value) : undefined;

    return (
        <div className="space-y-2" data-testid="media-picker">
            <div className="flex items-center gap-3 rounded-lg border border-line bg-raised p-2">
                <div className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-md bg-sunken text-faint">
                    {current ? <img src={current.url} alt="" className="size-full object-cover" /> : <Icon name="image" className="size-5" />}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-ui font-medium">{current ? mediaName(current) : value ? 'Image not in the library list' : 'No image'}</p>
                    {current && (
                        <p className="text-2xs text-muted tabular-nums">
                            {current.width} × {current.height} px
                        </p>
                    )}
                </div>
                {value && canEdit && (
                    <button
                        type="button"
                        disabled={uploading}
                        onClick={() => onChoose(null)}
                        className="rounded px-1.5 py-1 text-xs text-danger hover:bg-danger-soft"
                    >
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
                            disabled={uploading || !canEdit || !canUpload}
                            accept="image/jpeg,image/png,image/webp,image/avif,image/gif"
                            className="sr-only"
                            aria-label="Upload image"
                            onChange={async (e) => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (!file || busyRef.current || !canEdit || !canUpload) return;
                                busyRef.current = true;
                                const startedAt = generation.current;
                                setUploading(true);
                                setError(null);
                                setProgress(0);
                                try {
                                    const asset = await onUpload(file, (percent) => {
                                        if (mounted.current) setProgress(percent);
                                    });
                                    if (asset && mounted.current && generation.current === startedAt && latest.current.canEdit) {
                                        latest.current.onChoose(asset.id);
                                        if (asset.optimizationWarning) setError(asset.optimizationWarning);
                                    } else if (!asset && mounted.current) setError('The upload failed. Please try again.');
                                } catch (error) {
                                    if (mounted.current) setError(error instanceof Error ? error.message : 'The upload failed. Please try again.');
                                } finally {
                                    busyRef.current = false;
                                    if (mounted.current) {
                                        setUploading(false);
                                        setProgress(null);
                                    }
                                }
                            }}
                        />
                        <Button
                            aria-label="Upload a new image"
                            size="sm"
                            icon="upload"
                            busy={uploading}
                            disabled={uploading}
                            onClick={() => fileRef.current?.click()}
                            className="flex-1"
                        >
                            {uploading ? (progress === 100 ? 'Processing image…' : 'Uploading…') : 'Upload image'}
                        </Button>
                    </>
                )}
                {media.length > 0 && canEdit && (
                    <Button
                        size="sm"
                        icon="image"
                        disabled={uploading}
                        aria-expanded={browsing}
                        aria-controls={libraryId}
                        onClick={() => setBrowsing((open) => !open)}
                        className="flex-1"
                    >
                        {browsing ? 'Close library' : 'Choose from library'}
                    </Button>
                )}
            </div>
            {canUpload && canEdit && <p className="text-2xs text-muted">{uploadHelp()}</p>}
            {uploading && (
                <div role="status" className="space-y-1 text-xs text-muted">
                    <progress aria-label="Image upload progress" value={progress ?? 0} max={100} className="h-2 w-full" />
                    <span>{progress === 100 ? 'Upload transferred. Validating and preparing image…' : `Uploading ${progress ?? 0}%`}</span>
                </div>
            )}
            {error && (
                <p role="alert" className="text-xs text-danger">
                    {error}
                </p>
            )}
            {browsing && (
                <div className="space-y-2">
                    <label className="block text-xs">
                        Search library
                        <input
                            className="ui-input mt-1 w-full"
                            value={query}
                            maxLength={120}
                            onChange={(e) => {
                                setQuery(e.target.value);
                                setPage(1);
                            }}
                            placeholder="Name or description"
                        />
                    </label>
                    {searching && (
                        <p role="status" className="text-xs text-muted">
                            Searching…
                        </p>
                    )}
                </div>
            )}
            {browsing && (
                <ul
                    id={libraryId}
                    aria-label="Image library"
                    className="grid max-h-56 grid-cols-3 gap-1.5 overflow-y-auto rounded-lg border border-line p-1.5"
                    data-testid="media-library"
                >
                    {choices.map((m) => {
                        const chosen = m.id === value;
                        return (
                            <li key={m.id}>
                                <button
                                    type="button"
                                    disabled={uploading || !canEdit}
                                    aria-pressed={chosen}
                                    aria-label={`Use ${mediaName(m)} (${m.width} × ${m.height})`}
                                    title={mediaName(m)}
                                    onClick={() => {
                                        onChoose(m.id, m);
                                        setBrowsing(false);
                                    }}
                                    className={`group block w-full overflow-hidden rounded-md border text-left ${chosen ? 'border-accent ring-2 ring-accent-soft' : 'border-line hover:border-faint'}`}
                                >
                                    <img
                                        src={(m as MediaInfo & { previewUrl?: string }).previewUrl ?? m.url}
                                        alt=""
                                        loading="lazy"
                                        className="aspect-[4/3] w-full bg-sunken object-cover"
                                    />
                                    <span className="block truncate px-1 py-0.5 text-3xs text-muted">{mediaName(m)}</span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            {browsing && (
                <div className="flex items-center justify-between gap-2">
                    <Button size="sm" disabled={searching || page <= 1} onClick={() => setPage((p) => p - 1)}>
                        Previous
                    </Button>
                    <span className="text-xs text-muted">
                        {page} / {pages}
                    </span>
                    <Button size="sm" disabled={searching || page >= pages} onClick={() => setPage((p) => p + 1)}>
                        Next
                    </Button>
                </div>
            )}
        </div>
    );
}
