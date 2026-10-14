import { currentDefinition } from '@/arkon/components/registry';
import type { BindableProp } from '@/editor/BindingPanel';

/**
 * Which of a block's properties could take their value from content, and what shape each wants.
 *
 * Read from the component's own manifest — the props it declares and the media paths it names —
 * so a component that gains a property gains the ability to bind it without anything here
 * changing. Style, responsive settings and structural references are left out: they describe how
 * a block looks or what it points at, not what it says.
 */
const NEVER = ['style', 'responsive', 'componentId', 'form', 'menuId'];

export function bindableProps(type: string, labels: Record<string, string> = {}): BindableProp[] {
    const definition = currentDefinition(type);
    if (!definition) return [];
    const mediaRefs = definition.mediaRefs ?? [];

    return Object.entries(definition.props)
        .filter(([key]) => !NEVER.includes(key))
        .map(([key, field]) => {
            const shape = mediaRefs.some((path) => path.startsWith(`${key}.`))
                ? 'media'
                : field.type === 'string'
                  ? 'string'
                  : field.type === 'link'
                    ? 'string'
                    : field.type === 'boolean'
                      ? 'boolean'
                      : null;
            if (shape === null) return null;
            return { key, label: labels[key] ?? definition.editor?.fields?.[key]?.label ?? key, shape };
        })
        .filter((prop): prop is BindableProp => prop !== null);
}
