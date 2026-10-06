// AI proposals in the editor: a proposal is a list of the editor's own operations based on a
// specific saved draft version. These checks decide whether it may still be applied without
// replacing anything the user did since (the server checks the same again on save).
import type { PageOperation } from '../schema/operations';
import { hasUnsavedChanges, type EditorDocState } from './state';

export interface AiProposal {
    id: string;
    pageId: string;
    baseVersion: number;
    status: 'proposed' | 'empty';
    prompt: string;
    summary: string;
    notes: string[];
    /** What will change, described by the server from the operations themselves. */
    changes: string[];
    /** Problems that will block publishing (e.g. a button without a link). */
    warnings: string[];
    operations: PageOperation[];
    /** The proposed page rendered by the server (editor mode) for the preview; null when nothing changes. */
    canvas: { body: string; css: string } | null;
}

/** Why the proposal can't be applied now, or null when it can. */
export function proposalBlocker(state: EditorDocState, proposal: AiProposal, unresolvedFields: number): string | null {
    if (proposal.status === 'empty' || proposal.operations.length === 0) return 'This answer has no changes to apply.';
    if (unresolvedFields > 0) return 'Fix or revert the unfinished field first.';
    if (hasUnsavedChanges(state)) return 'You edited the page after asking. Discard this proposal and ask again, so your edits are not replaced.';
    if (state.version !== proposal.baseVersion)
        return `The draft changed after this proposal was made (version ${proposal.baseVersion}, now ${state.version}). Discard it and ask again.`;
    return null;
}

/** An AI request as the server tracks it (panel requests run in the local helper; MCP ones arrive as proposals). */
export interface AiRequestView {
    id: string;
    pageId: string;
    source: 'panel' | 'mcp' | 'api';
    status: 'queued' | 'running' | 'proposed' | 'empty' | 'failed' | 'cancelled' | 'applied' | 'discarded' | 'pending';
    prompt: string;
    baseVersion: number;
    createdAt: string;
    startedAt: string | null;
    error: { code: string; message: string } | null;
    proposal: AiProposal | null;
}

/** Readiness of the local Claude Code helper, as last reported to the server. */
export interface AiConnection {
    ready: boolean;
    message: string;
    claudeVersion: string | null;
    lastSeenAt: string | null;
}

export const isActive = (request: AiRequestView) => request.status === 'queued' || request.status === 'running';
export const isReviewable = (request: AiRequestView) => request.status === 'proposed' || request.status === 'empty';
