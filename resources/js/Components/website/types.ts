// Shapes of the Build a website screen (from WebsiteController / ProposalLedger views).
import type { ProviderConnection } from '@/lib/aiProvider';

export type Connection = ProviderConnection;
export type Scope = 'section' | 'page' | 'website';
export type ProposedPage = { id: string; title: string; path: string; existing: boolean; changes: string[] };
export type ImageItem = {
    assetId: string;
    kind: 'generated' | 'library' | 'unknown';
    status: string;
    activity: string | null;
    error: string | null;
    errorCode: string | null;
    title: string;
    url: string | null;
    usedOn: string[];
    ownJob: boolean;
    heartbeatAt: string | null;
    startedAt: string | null;
    spec: { subject?: string; placement?: string; aspectRatio?: string } | null;
};
export type Requirement = {
    key: string;
    label: string;
    status: 'fulfilled' | 'in_progress' | 'failed' | 'missing' | 'skipped' | 'pending';
    detail: string;
};
export type Stage = {
    key: string;
    label: string;
    status: 'waiting' | 'running' | 'retrying' | 'done' | 'cached' | 'failed';
    seconds: number | null;
    startedAt?: string | null;
    reconnects: number;
    retries?: number;
    error: string | null;
};
export type Proposal = {
    id: string;
    prompt: string;
    provider: string;
    status: string;
    summary: string | null;
    error: string | null;
    errorCode: string | null;
    issues: { path?: string; message: string }[];
    activity: string | null;
    createdAt: string;
    startedAt: string | null;
    heartbeatAt: string | null;
    resolvedAt: string | null;
    candidateSaved: boolean;
    parentId: string | null;
    revision: { scope: Scope; pageId: string | null; nodeId: string | null; label: string; baselineKind: 'proposal' | 'drafts' } | null;
    images: ImageItem[];
    requirements: Requirement[];
    sections: { id: string; type: string; label: string }[][];
    /** Every kind of block the proposal places, with its catalogue label (theme components marked, listed last). */
    blocks?: { type: string; label: string; theme: boolean }[];
    conflict: string | null;
    impacts?: { kind: 'form' | 'component'; id: string; name: string; otherPages: string[] }[];
    progress?: { stages: Stage[]; lastOutputAt: string | null } | null;
    diagnostics?: { mode: string; outcome: string; seconds: number; stages: Stage[]; code?: string; message?: string } | null;
    resumable?: boolean;
    cachedStages?: number;
    imageMode: 'generate' | 'library' | null;
    result: {
        includeLayout?: boolean;
        pages: ProposedPage[];
        notes: string[];
        tokenChanges: { token: string; value: string }[];
        menu?: { id: string; definition: { name: string; items: { label: string; type: string; href: string }[] } } | null;
        shared: Record<string, { id: string; changed: boolean }>;
        form: { id: string; definition: { name: string; fields: unknown[] } } | null;
        revisionChanges?: { page: string; changes: string[] }[];
    } | null;
    applied: { pages: { id: string; title: string; path: string; version: number }[]; withoutImages?: string[] } | null;
};
export type Readiness = {
    ready: boolean;
    issues: string[];
    pages: { id: string; title: string; path: string; htmlBytes: number; cssBytes: number; scripts: number; warnings: string[] }[];
    note: string;
};
export type ImageChoice = 'generate' | 'library' | undefined;
export type Device = 'desktop' | 'tablet' | 'mobile';
export type Zoom = 'fit' | '1' | '0.75' | '0.5';

export const providerName = (id: string) => (id === 'codex' ? 'Codex' : id === 'local' ? 'Local preparation' : 'Claude Code');
export const imagesOf = (p?: Proposal) => p?.images ?? [];
export const isWorking = (p?: Proposal) => !!p && (p.status === 'queued' || p.status === 'running');
export const imagesPending = (p?: Proposal) => imagesOf(p).some((i) => i.kind === 'generated' && ['queued', 'running'].includes(i.status));
export const SCOPE_LABEL: Record<Scope, string> = { section: 'Section', page: 'Page', website: 'Entire website' };

/** A short, human status for a request (History rows). */
export function statusLabel(p: Proposal): { text: string; tone: 'live' | 'ai' | 'changed' | 'danger' | 'neutral' } {
    if (isWorking(p)) return { text: 'Working', tone: 'ai' };
    if (p.status === 'proposed' && imagesPending(p)) return { text: 'Generating image', tone: 'ai' };
    if (p.status === 'proposed') return { text: 'Ready to apply', tone: 'live' };
    if (p.status === 'applied') return { text: 'Applied', tone: 'live' };
    if (p.status === 'failed') return { text: 'Failed', tone: 'danger' };
    if (p.status === 'empty') return { text: 'No changes needed', tone: 'neutral' };
    if (p.status === 'cancelled') return { text: 'Cancelled', tone: 'neutral' };
    if (p.status === 'discarded') return { text: 'Discarded', tone: 'neutral' };
    return { text: p.status.charAt(0).toUpperCase() + p.status.slice(1), tone: 'neutral' };
}

/** A one-line title for a request: the model's summary, else the start of the request. */
export const requestTitle = (p: Proposal) => p.summary || p.prompt.split(/[.\n]/)[0]!.trim() || 'Website request';

export const elapsed = (from: string, now: number) => {
    const s = Math.floor(Math.max(0, now - Date.parse(from)) / 1000);
    return `${Math.floor(s / 60)}m ${s % 60}s`;
};
export const ago = (from: string, now: number) => {
    const s = Math.max(0, Math.round((now - Date.parse(from)) / 1000));
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    return `${Math.floor(s / 86400)}d ago`;
};
