// Every version of every component, from the same manifests the server reads
// (resources/arkon/components/<type>/v<N>.json). Rendering is server-side only.
import type { Field } from './props';

export interface ComponentManifest {
    type: string;
    version: number;
    label: string;
    children: false | { allow: string[]; min?: number; max?: number };
    props: Record<string, Field>;
    defaultProps: Record<string, unknown>;
    inlineFields?: Record<string, { kind: 'line' | 'multiline' }>;
    mediaRefs?: string[];
    publishChecks?: { rule: 'notBlank' | 'present'; prop: string; when?: string; message: string }[];
}

const modules = import.meta.glob<ComponentManifest>('../../../arkon/components/*/v*.json', { eager: true, import: 'default' });

const byType = new Map<string, Map<number, ComponentManifest>>();
for (const manifest of Object.values(modules)) {
    const versions = byType.get(manifest.type) ?? new Map<number, ComponentManifest>();
    versions.set(manifest.version, manifest);
    byType.set(manifest.type, versions);
}

export function getDefinition(type: string, version: number): ComponentManifest | undefined {
    return byType.get(type)?.get(version);
}

/** The newest version of a component: the only one new documents may contain. */
export function currentDefinition(type: string): ComponentManifest | undefined {
    const versions = byType.get(type);
    if (!versions) return undefined;
    return versions.get(Math.max(...versions.keys()));
}

export function componentTypes(): string[] {
    return [...byType.keys()];
}

/** Dotted path lookup (`image.alt`), like Laravel's data_get. */
export function valueAt(object: unknown, path: string): unknown {
    let current = object;
    for (const key of path.split('.')) {
        if (typeof current !== 'object' || current === null) return undefined;
        current = (current as Record<string, unknown>)[key];
    }
    return current;
}
