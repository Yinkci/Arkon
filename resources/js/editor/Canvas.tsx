import { useEffect, useMemo, useRef, useState } from 'react';
import { BRIDGE_SCRIPT, CANVAS_CSS } from './bridge';

export type Viewport = 'desktop' | 'tablet' | 'mobile';
const VIEWPORT_WIDTH: Record<Viewport, string> = { desktop: '100%', tablet: '820px', mobile: '390px' };

interface Box {
    id: string;
    label: string | null;
    rect: { top: number; left: number; width: number; height: number } | null;
}

export interface CanvasProps {
    body: string;
    css: string;
    /** Inline fields that keep line breaks, by component type. */
    multiline: Record<string, string[]>;
    selectedId: string | null;
    viewport: Viewport;
    /** Incremented when the document changed from outside the canvas, so the iframe must re-render. */
    renderToken: number;
    /** No inline editing (selection still works). */
    readOnly?: boolean;
    onSelect(nodeId: string | null): void;
    onInlineEdit(nodeId: string, prop: string, value: string): void;
    onSaveShortcut(): void;
}

/**
 * The canvas is a sandboxed iframe (scripts allowed, no same-origin access) showing
 * the renderer's editor-mode HTML with the real page CSS. Selection chrome is drawn
 * in this document, over the iframe, so it never becomes part of the page DOM.
 */
export function Canvas(props: CanvasProps) {
    const frameRef = useRef<HTMLIFrameElement>(null);
    const [ready, setReady] = useState(false);
    const [boxes, setBoxes] = useState<{ selected: Box | null; hover: Box | null }>({ selected: null, hover: null });
    const latest = useRef(props);
    useEffect(() => {
        latest.current = props;
    });

    // srcdoc is built once; later updates are posted to the bridge, which keeps scroll position.
    const srcDoc = useMemo(() => buildSrcDoc(), []);

    useEffect(() => {
        function onMessage(event: MessageEvent) {
            if (event.source !== frameRef.current?.contentWindow) return;
            const msg = event.data as { source?: string; type?: string; [key: string]: unknown };
            if (msg?.source !== 'arkon-canvas') return;
            const current = latest.current;
            switch (msg.type) {
                case 'ready':
                    setReady(true);
                    break;
                case 'select':
                    current.onSelect((msg.nodeId as string | null) ?? null);
                    break;
                case 'edit':
                    current.onInlineEdit(msg.nodeId as string, msg.prop as string, msg.value as string);
                    break;
                case 'rects':
                    setBoxes({ selected: msg.selected as Box | null, hover: msg.hover as Box | null });
                    break;
                case 'shortcut':
                    if (msg.action === 'save') current.onSaveShortcut();
                    break;
            }
        }
        window.addEventListener('message', onMessage);
        // If the iframe loaded before this listener existed, its "ready" was missed: ask again.
        frameRef.current?.contentWindow?.postMessage({ source: 'arkon-editor', type: 'ping' }, '*');
        return () => window.removeEventListener('message', onMessage);
    }, []);

    const post = (message: Record<string, unknown>) => frameRef.current?.contentWindow?.postMessage({ source: 'arkon-editor', ...message }, '*');

    useEffect(() => {
        if (ready) post({ type: 'render', body: props.body, css: props.css, multiline: props.multiline });
        // Only re-render the iframe for outside changes; inline edits are already visible there.
    }, [ready, props.renderToken]);

    useEffect(() => {
        if (ready) post({ type: 'mode', readOnly: props.readOnly === true });
    }, [ready, props.readOnly]);

    useEffect(() => {
        if (ready) post({ type: 'select', nodeId: props.selectedId });
    }, [ready, props.selectedId]);

    return (
        <div className="flex h-full justify-center overflow-hidden bg-zinc-100 p-4">
            <div className="relative h-full bg-white shadow-sm transition-[width]" style={{ width: VIEWPORT_WIDTH[props.viewport] }}>
                <iframe ref={frameRef} title="Page canvas" sandbox="allow-scripts" srcDoc={srcDoc} className="h-full w-full border-0" data-testid="canvas" />
                <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden">
                    {boxes.hover?.rect && <Outline box={boxes.hover} tone="hover" />}
                    {boxes.selected?.rect && <Outline box={boxes.selected} tone="selected" />}
                </div>
            </div>
        </div>
    );
}

function Outline({ box, tone }: { box: Box; tone: 'hover' | 'selected' }) {
    const r = box.rect!;
    const color = tone === 'selected' ? 'border-indigo-600' : 'border-indigo-300 border-dashed';
    return (
        <div className={`absolute border-2 ${color}`} style={{ top: r.top, left: r.left, width: r.width, height: r.height }}>
            {tone === 'selected' && box.label && (
                <span className="absolute -top-5 left-0 rounded-t bg-indigo-600 px-1.5 py-0.5 text-[10px] font-medium text-white capitalize">{box.label}</span>
            )}
        </div>
    );
}

function buildSrcDoc(): string {
    return (
        '<!doctype html><html lang="en"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<style id="ak-page-css"></style>' +
        `<style>${CANVAS_CSS}</style>` +
        `</head><body><script>${BRIDGE_SCRIPT}</script></body></html>`
    );
}
