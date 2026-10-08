// The parts (style slots) of a component as the editor names them: what is selected, which
// part's controls are shown, and contextual labels such as "Image height" or "Section width".
// Parts stay parts of their component (no extra blocks): selecting one only changes which
// slot the inspector edits and which element the canvas highlights.
import { currentDefinition } from '../components/registry';
import type { Node } from '../schema/document';
import type { StyleField } from '../style/schema';

export interface Part {
    slot: string;
    /** "Hero section", "Image", "Content area" … */
    label: string;
    /** The noun used in control labels: "Image" → "Image height". */
    noun: string;
}

/** Names per component type and slot, where the manifest's own label is not specific enough. */
const NAMES: Record<string, Record<string, [label: string, noun: string]>> = {
    hero: {
        root: ['Hero section', 'Section'],
        content: ['Content area', 'Content area'],
        heading: ['Heading', 'Heading'],
        text: ['Supporting text', 'Text'],
        actions: ['Buttons', 'Button row'],
        media: ['Image', 'Image'],
    },
    image: { root: ['Image block', 'Block'], media: ['Image', 'Image'], caption: ['Caption', 'Caption'] },
    button: { root: ['Button block', 'Block'], button: ['Button', 'Button'] },
    section: { root: ['Section', 'Section'] },
    group: { root: ['Group', 'Group'] },
    columns: { root: ['Columns', 'Columns'] },
    column: { root: ['Column', 'Column'] },
    text: { root: ['Text', 'Text'] },
    instance: { root: ['Reusable component', 'Component'] },
    page: { root: ['Page', 'Page'] },
};

/** The part a component opens with: its main part (the image of an image block, the button itself). */
const DEFAULT_PART: Record<string, string> = { image: 'media', button: 'button' };

export function styleFieldOf(type: string): StyleField | null {
    const field = currentDefinition(type)?.props.style;
    return field && field.type === 'style' ? (field as unknown as StyleField) : null;
}

export function partsOf(node: Pick<Node, 'type'>): Part[] {
    const field = styleFieldOf(node.type);
    const slots: [string, { label?: string }][] = field ? Object.entries(field.slots) : [['root', {}]];
    return slots.map(([slot, definition]) => {
        const [label, noun] = NAMES[node.type]?.[slot] ?? [definition.label ?? slot, definition.label ?? slot];
        return { slot, label, noun };
    });
}

export function defaultPart(node: Pick<Node, 'type'>): string {
    const preferred = DEFAULT_PART[node.type];
    return preferred && partsOf(node).some((p) => p.slot === preferred) ? preferred : 'root';
}

/** The part to edit: the requested one if the component has it, otherwise its default. */
export function resolvePart(node: Pick<Node, 'type'>, part: string | null | undefined): Part {
    const parts = partsOf(node);
    return parts.find((p) => p.slot === part) ?? parts.find((p) => p.slot === defaultPart(node)) ?? parts[0]!;
}

/** "Image height", "Section width", "Block max width" — the property named for the part it changes. */
export function contextualLabel(part: Part, propertyLabel: string): string {
    return `${part.noun} ${propertyLabel.charAt(0).toLowerCase()}${propertyLabel.slice(1)}`;
}

/** The component's name for breadcrumbs and canvas tags ("Hero section", "Text", "Image block"). */
export function componentName(node: Pick<Node, 'type'>): string {
    return NAMES[node.type]?.root?.[0] ?? currentDefinition(node.type)?.label ?? node.type;
}
