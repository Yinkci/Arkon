// The TypeScript half of the conformance suite: the editor's validation and
// operation semantics must produce exactly what the PHP server produces
// (tests/Conformance/fixtures.json, built from PHP by build.php).
import { describe, expect, it } from 'vitest';
import { mediaRefs, publishIssues, validatePageDocument } from '@/arkon/components/validate';
import type { Issue } from '@/arkon/rules';
import type { PageDocument } from '@/arkon/schema/document';
import { OperationError, applyOperations, type PageOperation } from '@/arkon/schema/operations';
import { pathIssues } from '@/arkon/schema/paths';
import { parseTokens } from '@/arkon/style/tokens';
import fixtures from './fixtures.json';

/** JSON with object keys sorted (twin of PHP Json::canonical). */
function canonical(value: unknown): string {
    if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
    if (value && typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>).filter(([, v]) => v !== undefined);
        entries.sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));
        return `{${entries.map(([k, v]) => `${JSON.stringify(k)}:${canonical(v)}`).join(',')}}`;
    }
    return JSON.stringify(value);
}

/** Issue order follows object key order, which differs between PHP and JavaScript for numeric keys: compare as sets. */
function sortIssues(issues: Issue[]): Issue[] {
    const normalised = issues.map((i) =>
        Object.fromEntries(Object.entries({ nodeId: i.nodeId, path: i.path, message: i.message }).filter(([, v]) => v !== undefined)),
    );
    return normalised.sort((a, b) => (canonical(a) < canonical(b) ? -1 : canonical(a) > canonical(b) ? 1 : 0)) as unknown as Issue[];
}

describe('documents', () => {
    it.each(fixtures.documents.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        const doc: unknown = JSON.parse(c.document);
        const issues = validatePageDocument(doc);
        expect(sortIssues(issues)).toEqual(c.issues);
        if (c.publishIssues !== null) expect(sortIssues(publishIssues(doc as PageDocument))).toEqual(c.publishIssues);
        if (c.mediaRefs !== null) expect(mediaRefs(doc as PageDocument)).toEqual(c.mediaRefs);
    });
});

describe('operations', () => {
    it.each(fixtures.operations.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        const original = JSON.parse(c.document) as PageDocument;
        const ops = JSON.parse(c.operations) as PageOperation[];
        if (c.error !== null) {
            expect(() => applyOperations(original, ops)).toThrow(new OperationError(c.error, 0).message);
            return;
        }
        const { doc, inverse } = applyOperations(original, ops);
        // Same resulting document as PHP, including `{}` staying an object.
        expect(JSON.parse(canonical(doc))).toEqual(JSON.parse(c.result!));
        expect(canonical(doc)).toBe(canonical(JSON.parse(c.result!)));
        expect(sortIssues(validatePageDocument(doc))).toEqual(c.issues);
        // The inverse restores the original exactly.
        expect(canonical(applyOperations(doc, inverse).doc)).toBe(canonical(original));
    });
});

describe('paths', () => {
    it.each(fixtures.paths.map((c) => [JSON.stringify(c.path).slice(0, 40), c] as const))('%s', (_name, c) => {
        expect(pathIssues(c.path)).toEqual(c.issues);
    });
});

describe('design tokens', () => {
    it.each(fixtures.tokens.map((c) => [c.tokens.slice(0, 60), c] as const))('%s', (_name, c) => {
        expect(sortIssues(parseTokens(JSON.parse(c.tokens)).issues)).toEqual(c.issues);
    });
});
