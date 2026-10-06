import { describe, expect, it } from 'vitest';
import { createHeroNode, createPageDocument } from '../components/factories';
import { proposalBlocker, type AiProposal } from './proposals';
import { beginSave, dispatch, initialState, undo } from './state';

const hero = createHeroNode({ heading: 'Hello' });
const doc = createPageDocument([hero]);
const proposal = (overrides: Partial<AiProposal> = {}): AiProposal => ({
    id: 'p1',
    pageId: 'page',
    baseVersion: 3,
    status: 'proposed',
    prompt: 'Shorten the headline',
    summary: 'Shorter headline',
    notes: [],
    changes: ['Change Hero “Hello”: heading'],
    warnings: [],
    operations: [{ op: 'updateProps', nodeId: hero.id, set: { heading: 'Hi' } }],
    canvas: { body: '', css: '' },
    ...overrides,
});

describe('proposalBlocker', () => {
    it('allows a proposal made for the saved draft the editor holds', () => {
        expect(proposalBlocker(initialState(doc, 3), proposal(), 0)).toBeNull();
    });

    it('refuses stale proposals, unsaved or unconfirmed edits, unfinished fields and empty answers', () => {
        expect(proposalBlocker(initialState(doc, 4), proposal(), 0)).toMatch(/changed after this proposal was made \(version 3, now 4\)/);
        const edited = dispatch(initialState(doc, 3), [{ op: 'updateProps', nodeId: hero.id, set: { text: 'mine' } }]);
        if (!edited.ok) throw new Error('setup');
        expect(proposalBlocker(edited.state, proposal(), 0)).toMatch(/edited the page after asking/);
        expect(proposalBlocker(beginSave(edited.state, () => 'k')!.state, proposal(), 0)).toMatch(/edited the page after asking/);
        expect(proposalBlocker(initialState(doc, 3), proposal(), 1)).toMatch(/unfinished field/);
        expect(proposalBlocker(initialState(doc, 3), proposal({ status: 'empty', operations: [] }), 0)).toMatch(/no changes/);
    });

    it('applying is one undo step that restores the page exactly', () => {
        const applied = dispatch(initialState(doc, 3), proposal().operations);
        if (!applied.ok) throw new Error('setup');
        expect(applied.state.document.nodes[hero.id]!.props.heading).toBe('Hi');
        expect(applied.state.undo).toHaveLength(1);
        expect(applied.state.pending).toEqual(proposal().operations);
        const back = undo(applied.state)!;
        expect(back.document).toEqual(doc);
    });
});

describe('saving an applied proposal', () => {
    it('sends exactly the proposal in one batch tagged with its id, and resends it unchanged', () => {
        const applied = dispatch(initialState(doc, 3), proposal().operations);
        if (!applied.ok) throw new Error('setup');
        const started = beginSave(applied.state, () => 'key-1', 'p1')!;
        expect(started.batch).toEqual({ key: 'key-1', baseVersion: 3, operations: proposal().operations, proposalId: 'p1' });
        // An unconfirmed batch is resent as it was, id included.
        expect(beginSave(started.state, () => 'key-2')!.batch).toBe(started.batch);
    });
});
