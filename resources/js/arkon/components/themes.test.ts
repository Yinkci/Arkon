import { afterEach, describe, expect, it } from 'vitest';
import manifest from '../../../../themes/mysite/components/testimonial/component.json';
import { addableTypes } from '../editor/structure';
import { currentDefinition, getDefinition, installThemeDefinitions, type ComponentManifest } from './registry';

afterEach(() => installThemeDefinitions([]));

describe('installed theme definitions', () => {
    it('registers editable fields and allows the component in existing containers without a frontend rebuild', () => {
        installThemeDefinitions([manifest as ComponentManifest]);
        expect(addableTypes()).toContain(manifest.type);
        for (const type of ['page', 'section', 'group', 'column', 'fragment']) {
            const children = currentDefinition(type)!.children;
            expect(children !== false && children.allow).toContain(manifest.type);
        }
        expect(currentDefinition(manifest.type)?.inlineFields?.quote?.kind).toBe('multiline');
        expect(currentDefinition(manifest.type)?.editor?.fields?.author?.label).toBe('Author');
    });

    it('changes palette availability without forgetting historical component definitions', () => {
        installThemeDefinitions([manifest as ComponentManifest], []);
        expect(addableTypes()).not.toContain(manifest.type);
        expect(currentDefinition(manifest.type)).toBeDefined();
        installThemeDefinitions([manifest as ComponentManifest], [manifest.type]);
        expect(addableTypes()).toContain(manifest.type);
        installThemeDefinitions([manifest as ComponentManifest], []);
        expect(addableTypes()).not.toContain(manifest.type);
        expect(getDefinition(manifest.type, 1)).toBeDefined();
    });

    it('keeps old versions and picks the latest definition', () => {
        installThemeDefinitions([manifest as ComponentManifest, { ...manifest, version: 2 } as ComponentManifest]);
        expect(getDefinition(manifest.type, 1)?.version).toBe(1);
        expect(currentDefinition(manifest.type)?.version).toBe(2);
        installThemeDefinitions([]);
        expect(currentDefinition(manifest.type)).toBeUndefined();
    });
});
