import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import cases from '../../../tests/Conformance/form-conditions.json';
for (const version of [1, 2]) {
    const source = readFileSync(new URL(`../../../public/_arkon/forms-${version}.js`, import.meta.url), 'utf8');
    const begin = source.indexOf('function match('),
        end = source.indexOf('function update(', begin);
    const context: { match?: (condition: unknown, values: unknown) => boolean } = {};
    vm.runInNewContext(source.slice(begin, end), context);
    describe('public form runtime agrees with server conditions', () => {
        for (const fixture of cases) it(fixture.name, () => expect(context.match!(fixture.condition, fixture.values)).toBe(fixture.expected));
    });
}
