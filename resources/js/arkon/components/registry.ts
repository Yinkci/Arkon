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
    editor?: { fields?: Record<string, { label?: string; multiline?: boolean }> };
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

let activeThemeTypes: Set<string> | null = null;
export function themeIsAddable(type: string): boolean {
    return activeThemeTypes === null || activeThemeTypes.has(type);
}

/** Immutable snapshots supplied by the server on a full page load; no theme JS or rebuild. */
export function installThemeDefinitions(manifests: ComponentManifest[], addableTypes?: string[]): void {
    activeThemeTypes = addableTypes === undefined ? null : new Set(addableTypes);
    byType.clear();
    for (const manifest of [...Object.values(modules), ...manifests]) {
        const versions = byType.get(manifest.type) ?? new Map<number, ComponentManifest>();
        versions.set(manifest.version, manifest);
        byType.set(manifest.type, versions);
    }
    const types = manifests.map((m) => m.type);
    for (const [type, versions] of byType) {
        for (const [version, definition] of versions) {
            if (definition.children !== false && definition.children.allow.includes('text')) {
                versions.set(version, { ...definition, children: { ...definition.children, allow: [...new Set([...definition.children.allow, ...types])] } });
            }
        }
        byType.set(type, versions);
    }
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
