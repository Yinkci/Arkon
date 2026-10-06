import type { PageDocument } from '@/arkon/schema/document';

export type PageStatus = 'draft' | 'published' | 'changed';

export interface PageRow {
    id: string;
    path: string;
    title: string;
    livePath: string | null;
    livePublicationId: string | null;
    version: number;
    status: PageStatus;
    updatedAt: string | null;
    publishedAt: string | null;
}

export interface MediaInfo {
    id: string;
    url: string;
    width: number;
    height: number;
    mime: string;
}

export interface LiveInfo {
    revisionNumber: number;
    publishedAt: string;
    publicationId: string;
    path: string;
    title: string;
}

export interface Revision {
    id: string;
    number: number;
    source: 'human' | 'ai' | 'system';
    message: string;
    createdAt: string;
    authorName: string | null;
    isLive: boolean;
}

/** A stored value the current rules refuse, in a draft saved before they were tightened. */
export interface RecoveryItem {
    nodeId: string;
    type: string;
    /** The prop holding the value, e.g. "href". */
    path: string;
    value: string;
    message: string;
}

export interface EditorInit {
    page: { id: string; path: string; title: string };
    draft: { document: PageDocument; version: number };
    /** Null for a normal draft; otherwise the draft opens in recovery (see RecoveryPanel). */
    recovery: RecoveryItem[] | null;
    live: LiveInfo | null;
    status: PageStatus;
    media: MediaInfo[];
    permissions: { edit: boolean; publish: boolean; delete: boolean; upload: boolean };
    revisions: Revision[];
    site: { name: string };
    canvas: { body: string; css: string };
    multiline: Record<string, string[]>;
    /** Whether the AI panel can be used (never provider credentials). */
    ai: { available: boolean; reason: string | null; promptMax: number; connection: import('@/arkon/editor/proposals').AiConnection };
}

export interface SharedProps {
    auth: { user: { id: string; name: string; email: string } | null };
    site: { id: string; name: string; role: string } | null;
    can: Record<string, boolean>;
    [key: string]: unknown;
}
