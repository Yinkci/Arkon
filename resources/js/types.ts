import type { PageDocument } from '@/arkon/schema/document';
import type { TokenSet } from '@/arkon/style/tokens';

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
    /** Site-authorized small preview, separate from the public publication URL. */
    previewUrl?: string;
    optimizationWarning?: string | null;
    id: string;
    url: string;
    width: number;
    height: number;
    mime: string;
    /** The human-readable library title, falling back to the original filename. */
    name?: string;
    defaultAlt?: string;
    defaultCaption?: string;
}

/** A reusable component of the site, as pages see it: only its published version is ever used. */
export interface ReusableComponentInfo {
    id: string;
    name: string;
    /** Published version number, or null when it was never published. */
    published: number | null;
    /** The published version's document (null when unpublished), for detaching. */
    document: PageDocument | null;
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
    /** The site's published design tokens with defaults applied. */
    tokens: TokenSet;
    /** Reusable components (published versions) that instances on this page can use. */
    components: ReusableComponentInfo[];
    /** Whether the AI panel can be used (never provider credentials). */
    ai: { available: boolean; reason: string | null; promptMax: number; connection: import('@/arkon/editor/proposals').AiConnection };
    /** What kind of item this is (page, post, …) and the draft's details for kinds that have them. */
    content: EditorContent;
}

/** A content type as list screens show it (pages, posts, …: one model). */
export interface ContentTypeInfo {
    kind: string;
    label: string;
    plural: string;
    pathPrefix: string;
    taxonomies: string[];
}

export interface ContentDetailsValues {
    excerpt: string;
    featuredMediaId: string | null;
    /** Term ids by taxonomy (category, tag, …). */
    terms: Record<string, string[]>;
}

export interface EditorContent {
    kind: string;
    label: string;
    plural: string;
    /** Whether the type has an excerpt and a featured image (posts). */
    details: boolean;
    listUrl: string;
    values: ContentDetailsValues;
    taxonomies: { name: string; label: string; plural: string; terms: { id: string; name: string }[] }[];
}

export interface Term {
    id: string;
    taxonomy: string;
    name: string;
    slug: string;
    description: string;
    parentId: string | null;
    count: number;
    draftCount?: number;
}

export interface SharedProps {
    auth: { user: { id: string; name: string; email: string } | null };
    site: { id: string; name: string; role: string; url?: string } | null;
    can: Record<string, boolean>;
    [key: string]: unknown;
}
