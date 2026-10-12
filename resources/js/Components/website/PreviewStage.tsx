// The website canvas of Build a website: the real server-rendered proposal page in an isolated,
// sandboxed iframe at a true device width, scaled to fit the workspace. The page scrolls inside the
// frame like the live site; the admin page itself does not scroll.
import { memo, useLayoutEffect, useRef, useState } from 'react';
import { Icon, type IconName } from '@/Components/Icon';
import { Spinner } from '@/Components/ui';
import type { Device, Zoom } from './types';

/** Logical viewport widths: a common laptop/desktop, the builder's tablet and a phone. */
export const DEVICES: { value: Device; label: string; icon: IconName; width: number }[] = [
    { value: 'desktop', label: 'Desktop', icon: 'desktop', width: 1440 },
    { value: 'tablet', label: 'Tablet', icon: 'tablet', width: 820 },
    { value: 'mobile', label: 'Mobile', icon: 'mobile', width: 390 },
];
const ZOOMS: { value: Zoom; label: string }[] = [
    { value: 'fit', label: 'Fit' },
    { value: '1', label: '100%' },
    { value: '0.75', label: '75%' },
    { value: '0.5', label: '50%' },
];
const PAD = 16;

export function DeviceSwitch({ value, onChange }: { value: Device; onChange(value: Device): void }) {
    return (
        <div role="group" aria-label="Preview device" className="inline-flex h-8 items-center rounded-md border border-line bg-sunken p-0.5">
            {DEVICES.map((d) => {
                const active = d.value === value;
                return (
                    <button
                        key={d.value}
                        type="button"
                        aria-pressed={active}
                        title={`${d.label} (${d.width} px)`}
                        onClick={() => onChange(d.value)}
                        className={`inline-flex h-full w-8 items-center justify-center rounded text-xs font-medium transition-[background-color,color,box-shadow] duration-100 ${active ? 'bg-surface text-fg shadow-raise' : 'text-muted hover:text-fg'}`}
                    >
                        <Icon name={d.icon} className="size-3.5" />
                        <span className="sr-only">{d.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

export function ZoomSelect({ value, onChange }: { value: Zoom; onChange(value: Zoom): void }) {
    return (
        <select
            aria-label="Zoom"
            title="Zoom"
            value={value}
            onChange={(e) => onChange(e.target.value as Zoom)}
            className="ui-input h-8 w-auto py-0 pr-7 pl-2 text-xs"
        >
            {ZOOMS.map((z) => (
                <option key={z.value} value={z.value}>
                    {z.label}
                </option>
            ))}
        </select>
    );
}

/**
 * Both frames stay mounted once shown, so switching Before/After, the device or the zoom never reloads a page;
 * only a different page (or a regenerated image) changes `src`.
 */
export const PreviewStage = memo(function PreviewStage({
    src,
    title,
    baselineSrc,
    baselineTitle,
    showBaseline,
    device,
    zoom,
}: {
    src: string;
    title: string;
    baselineSrc: string | null;
    baselineTitle: string;
    showBaseline: boolean;
    device: Device;
    zoom: Zoom;
}) {
    const stageRef = useRef<HTMLDivElement>(null);
    const [box, setBox] = useState({ w: 0, h: 0 });
    const [loaded, setLoaded] = useState<Record<string, boolean>>({});
    useLayoutEffect(() => {
        const el = stageRef.current;
        if (!el) return;
        const measure = () => setBox((b) => (b.w === el.clientWidth && b.h === el.clientHeight ? b : { w: el.clientWidth, h: el.clientHeight }));
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        return () => observer.disconnect();
    }, []);
    const width = DEVICES.find((d) => d.value === device)!.width;
    const scale = zoom === 'fit' ? Math.min(1, Math.max(0.2, (box.w - PAD * 2) / width)) : Number(zoom);
    const visualHeight = Math.max(360, box.h - PAD * 2);
    const frames = [
        { key: 'after', src, title, hidden: showBaseline && !!baselineSrc },
        ...(baselineSrc ? [{ key: 'before', src: baselineSrc, title: baselineTitle, hidden: !showBaseline }] : []),
    ];
    const visible = frames.find((f) => !f.hidden)!;
    return (
        <div ref={stageRef} className="ak-scroll relative h-full min-h-0 overflow-auto bg-canvas p-4" data-testid="preview-stage">
            {box.w > 0 && (
                <div
                    className="relative mx-auto overflow-hidden rounded-md bg-white shadow-raise ring-1 ring-line"
                    style={{ width: Math.round(width * scale), height: visualHeight }}
                    data-device={device}
                    data-scale={scale.toFixed(3)}
                >
                    {frames.map((f) => (
                        <iframe
                            key={f.key + f.src}
                            title={f.title}
                            sandbox=""
                            src={f.src}
                            hidden={f.hidden}
                            onLoad={() => setLoaded((l) => ({ ...l, [f.src]: true }))}
                            className="block border-0 bg-white"
                            style={{ width, height: visualHeight / scale, transform: `scale(${scale})`, transformOrigin: '0 0' }}
                        />
                    ))}
                    {!loaded[visible.src] && (
                        <span className="pointer-events-none absolute top-3 right-3 inline-flex items-center gap-1.5 rounded-md bg-surface/90 px-2 py-1 text-xs text-muted shadow-hairline">
                            <Spinner className="size-3" />
                            Loading preview
                        </span>
                    )}
                </div>
            )}
        </div>
    );
});
