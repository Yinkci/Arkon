import { useId, type ReactNode } from 'react';
import {
    animateInsteadOps,
    animateInsteadTargets,
    isFirstBlock,
    MOTION_PROPERTIES,
    motionStatus,
    type MotionStatus,
    type Protection,
} from '@/arkon/editor/motion';
import { componentName } from '@/arkon/editor/parts';
import { nodeLabel } from '@/arkon/editor/structure';
import type { Node, PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import { styleOf, withStyleValue } from '@/arkon/style/edit';
import { allowedProperties, type Breakpoint, type Style, type StyleField } from '@/arkon/style/schema';
import { Icon, type IconName } from '@/Components/Icon';
import { Button, PanelSection, Segmented } from '@/Components/ui';

export type { Protection } from '@/arkon/editor/motion';

export const ANIMATION_LABELS: Record<string, string> = {
    none: 'None',
    fade: 'Fade in',
    'fade-up': 'Fade up',
    'fade-down': 'Fade down',
    'fade-left': 'Fade left (slides in from the right)',
    'fade-right': 'Fade right (slides in from the left)',
    zoom: 'Subtle zoom in',
};

const EASING_LABELS: Record<string, string> = {
    smooth: 'Smooth slowdown (default)',
    'ease-in-out': 'Ease in and out',
    ease: 'Standard',
    linear: 'Linear',
};

const SCREEN: Record<Breakpoint, string> = { base: 'all screens', tablet: 'tablets', mobile: 'phones' };
const DEFAULTS = { duration: 600, delay: 0 };

/** Whether a component's whole block (its root slot) can have an entrance animation. */
export function canAnimate(field: StyleField | null): boolean {
    const root = field?.slots.root;
    return !!root && allowedProperties(root).includes('animation');
}

const ms = (value: unknown, fallback: number) => (typeof value === 'string' && /^[0-9]+ms$/.test(value) ? parseInt(value, 10) : fallback);

/** The status line next to the effect: what the visitor will actually see. */
function StatusLine(props: { status: MotionStatus; reduced: boolean; document: PageDocument; nodeId: string; name: string; onShow(id: string): void }) {
    const { status } = props;
    const tone = (icon: IconName, cls: string, kind: string, text: ReactNode) => (
        <div
            role="status"
            data-testid="animation-status"
            data-status={kind}
            className={`flex items-start gap-2 rounded-md border px-2.5 py-2 text-2xs leading-snug ${cls}`}
        >
            <Icon name={icon} className="mt-px size-3.5 shrink-0" />
            <div className="min-w-0 space-y-1">{text}</div>
        </div>
    );
    if (status.kind === 'protected') {
        const cause = props.document.nodes[status.protection.cause];
        const what = status.protection.reason === 'heading' ? 'the page’s main heading (H1)' : 'the image that loads first';
        const self = status.protection.cause === props.nodeId;
        return tone(
            'lock',
            'border-changed/40 bg-changed-soft text-fg',
            'protected',
            <>
                <p>
                    <strong className="font-semibold">Won’t play on the page: protected content.</strong> This {props.name.toLowerCase()}{' '}
                    {self ? 'is' : 'holds'} {what}
                    {cause && !self ? (
                        <>
                            {' '}
                            (<span data-testid="animation-cause">{nodeLabel(cause)}</span>)
                        </>
                    ) : null}
                    . It is likely the largest content visitors see first (LCP), so it appears immediately, without an entrance.
                </p>
                {cause && !self && (
                    <button
                        type="button"
                        className="font-medium text-accent hover:underline"
                        onClick={() => props.onShow(cause.id)}
                        data-testid="animation-show-cause"
                    >
                        Show {status.protection.reason === 'heading' ? 'the heading' : 'the image'}
                    </button>
                )}
            </>,
        );
    }
    if (status.kind === 'off-here') {
        return tone(
            'mobile',
            'border-line bg-raised text-fg',
            'off-here',
            <p>
                <strong className="font-semibold">Off on {SCREEN[status.breakpoint]}.</strong> Plays on larger screens; this screen shows the block without an
                entrance.
            </p>,
        );
    }
    if (status.kind === 'plays') {
        return tone(
            props.reduced ? 'info' : 'check',
            props.reduced ? 'border-line bg-raised text-fg' : 'border-live/30 bg-live-soft text-fg',
            props.reduced ? 'reduced-motion' : 'plays',
            <>
                <p>
                    <strong className="font-semibold">{status.trigger === 'load' ? 'Plays when the page opens.' : 'Plays when it comes into view.'}</strong>{' '}
                    {status.trigger === 'view' ? 'Already on screen when the page opens: it plays then. ' : ''}Once per visit; without JavaScript it plays on
                    load or simply shows.
                </p>
                {props.reduced && (
                    <p data-testid="animation-reduced">
                        Your system asks for reduced motion, so previews show the final state, as visitors with that setting see it.
                    </p>
                )}
            </>,
        );
    }
    return null;
}

/**
 * Entrance animation of the selected block (always its whole block: the root slot), stored as
 * ordinary design settings. The status says what the page will really do (plays, protected
 * content, off on this screen, reduced motion). Choosing or changing an eligible setting previews
 * it on the canvas once its new render is ready; "Preview animation" plays it again.
 */
export function AnimationSection(props: {
    document: PageDocument;
    node: Node;
    canEdit: boolean;
    breakpoint: Breakpoint;
    onStyle(style: Style, key?: string): void;
    /** Other edits (the safe alternative for a protected container), one undo step. */
    onChange(ops: PageOperation[]): void;
    /** Why the page leaves this block still (it holds the likely LCP), from the last canvas render. */
    protection?: Protection;
    /** Every protected block of the page (same render): the move never lands on any of them. */
    protectedNodes?: Record<string, Protection>;
    /** In the reusable-component editor: protection depends on the page it is placed on. */
    inComponent?: boolean;
    /** Plays the block's entrance once on the canvas, after the render that shows the current settings. */
    onPreview?(nodeId: string): void;
    onSelectNode(nodeId: string): void;
}) {
    const ids = { effect: useId(), duration: useId(), delay: useId(), easing: useId(), phones: useId() };
    const style = styleOf(props.node.props);
    const base = style.root?.base ?? {};
    const animation = typeof base.animation === 'string' ? base.animation : 'none';
    const trigger = base.animationTrigger === 'view' ? 'view' : 'load';
    const duration = ms(base.animationDuration, DEFAULTS.duration);
    const delay = ms(base.animationDelay, DEFAULTS.delay);
    const easing = typeof base.animationEasing === 'string' ? base.animationEasing : 'smooth';
    const phonesOff = style.root?.mobile?.animation === 'none';
    const on = animation !== 'none';
    const name = componentName(props.node);
    const reduced = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
    const status = motionStatus(style, props.breakpoint, props.protection);
    const protectedHere = props.protection !== undefined;
    const targets = props.protection && props.protectedNodes ? animateInsteadTargets(props.document, props.node.id, props.protectedNodes) : [];

    /** Applies a change; previews it when the result plays on this screen (after its canvas render). */
    const update = (next: Style, key?: string) => {
        props.onStyle(next, key);
        if (!reduced && motionStatus(next, props.breakpoint, props.protection).kind === 'plays') props.onPreview?.(props.node.id);
    };
    const set = (property: string, value: string | null, from: Style = style, breakpoint: Breakpoint = 'base') =>
        withStyleValue(from, 'root', breakpoint, property, value);
    const clear = (): Style => {
        let next = style;
        for (const property of MOTION_PROPERTIES)
            for (const bp of ['base', 'tablet', 'mobile'] as const) next = withStyleValue(next, 'root', bp, property, null);
        return next;
    };
    const chooseEffect = (value: string) => {
        if (value === 'none') return props.onStyle(clear());
        let next = set('animation', value);
        // A first choice also picks when it plays: on page load in the first block, otherwise when it comes into view.
        if (!on && base.animationTrigger === undefined) next = set('animationTrigger', isFirstBlock(props.document, props.node.id) ? 'load' : 'view', next);
        update(next);
    };
    const canPreview = !!props.onPreview && status.kind === 'plays' && !reduced;

    return (
        <PanelSection
            title="Animation"
            data-testid="animation-section"
            aside={on ? <span className="text-2xs font-normal text-muted">{ANIMATION_LABELS[animation]}</span> : undefined}
        >
            <p className="text-2xs leading-snug text-muted" data-testid="animation-target">
                <Icon name="motion" className="mr-1 inline size-3.5 align-[-3px]" />
                Animates the whole {name.toLowerCase()} and everything inside it, as one block.
            </p>
            <div>
                <label htmlFor={ids.effect} className="ui-label">
                    Entrance
                </label>
                <select
                    id={ids.effect}
                    className="ui-input"
                    // Protected content never animates: no effect can be chosen that would silently not play.
                    disabled={!props.canEdit || protectedHere}
                    title={protectedHere ? 'This block is protected content: it appears immediately, without an entrance.' : undefined}
                    value={animation}
                    onChange={(e) => chooseEffect(e.target.value)}
                    data-testid="animation-effect"
                    aria-describedby={`${ids.effect}-status`}
                >
                    {Object.entries(ANIMATION_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>
            <div id={`${ids.effect}-status`}>
                {protectedHere && !on ? (
                    <StatusLine
                        status={{ kind: 'protected', protection: props.protection! }}
                        reduced={reduced}
                        document={props.document}
                        nodeId={props.node.id}
                        name={name}
                        onShow={props.onSelectNode}
                    />
                ) : (
                    <StatusLine status={status} reduced={reduced} document={props.document} nodeId={props.node.id} name={name} onShow={props.onSelectNode} />
                )}
            </div>
            {protectedHere && on && targets.length > 0 && (
                <div className="space-y-1">
                    <Button
                        size="sm"
                        icon="motion"
                        disabled={!props.canEdit}
                        onClick={() => props.onChange(animateInsteadOps(props.document, props.node, targets))}
                        data-testid="animation-instead"
                    >
                        Move this entrance to the other blocks inside
                    </Button>
                    <p className="text-2xs leading-snug text-muted" data-testid="animation-instead-count">
                        {targets.length} block{targets.length === 1 ? '' : 's'} next to the protected content get{targets.length === 1 ? 's' : ''} the entrance{' '}
                        (taken from this block); the protected content stays still. One undo step.
                    </p>
                </div>
            )}
            {on && !protectedHere && (
                <>
                    <div>
                        <p className="ui-label">Plays</p>
                        <Segmented
                            label="When the animation plays"
                            size="sm"
                            value={trigger}
                            onChange={(value) => update(set('animationTrigger', value))}
                            options={[
                                { value: 'load', label: 'On page load' },
                                { value: 'view', label: 'When scrolled into view' },
                            ]}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label htmlFor={ids.duration} className="ui-label">
                                Duration <span className="font-normal text-muted tabular-nums">{duration} ms</span>
                            </label>
                            <input
                                id={ids.duration}
                                type="range"
                                min={150}
                                max={4000}
                                step={50}
                                value={duration}
                                disabled={!props.canEdit}
                                onChange={(e) => update(set('animationDuration', `${e.target.value}ms`), 'motion.duration')}
                                className="w-full accent-[var(--ak-accent)]"
                                data-testid="animation-duration"
                            />
                        </div>
                        <div>
                            <label htmlFor={ids.delay} className="ui-label">
                                Delay <span className="font-normal text-muted tabular-nums">{delay} ms</span>
                            </label>
                            <input
                                id={ids.delay}
                                type="range"
                                min={0}
                                max={2000}
                                step={50}
                                value={delay}
                                disabled={!props.canEdit}
                                onChange={(e) => update(set('animationDelay', `${e.target.value}ms`), 'motion.delay')}
                                className="w-full accent-[var(--ak-accent)]"
                                data-testid="animation-delay"
                            />
                        </div>
                    </div>
                    <div>
                        <label htmlFor={ids.easing} className="ui-label">
                            Easing
                        </label>
                        <select
                            id={ids.easing}
                            className="ui-input"
                            disabled={!props.canEdit}
                            value={easing}
                            onChange={(e) => update(set('animationEasing', e.target.value === 'smooth' ? null : e.target.value))}
                        >
                            {Object.entries(EASING_LABELS).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <label htmlFor={ids.phones} className="flex items-center gap-2 text-ui">
                        <input
                            id={ids.phones}
                            type="checkbox"
                            className="size-4 accent-[var(--ak-accent)]"
                            disabled={!props.canEdit}
                            checked={phonesOff}
                            onChange={(e) => update(set('animation', e.target.checked ? 'none' : null, style, 'mobile'))}
                            data-testid="animation-phones-off"
                        />
                        Don’t animate on phones
                    </label>
                    {props.inComponent && (
                        <p className="text-2xs leading-snug text-muted">
                            On a page, the animation is left off if this block holds the page’s main heading or the image that loads first.
                        </p>
                    )}
                </>
            )}
            {on && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {!protectedHere && (
                        <Button
                            size="sm"
                            icon="play"
                            disabled={!canPreview}
                            onClick={() => props.onPreview?.(props.node.id)}
                            data-testid="animation-preview"
                            title={
                                canPreview
                                    ? 'Play the entrance once on the canvas. Clicking, typing, selecting or dragging stops it.'
                                    : 'Nothing to preview on this screen.'
                            }
                        >
                            Preview animation
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="ghost"
                        icon="reset"
                        disabled={!props.canEdit}
                        onClick={() => props.onStyle(clear())}
                        data-testid="animation-reset"
                    >
                        {protectedHere ? 'Remove the stored entrance' : 'Reset'}
                    </Button>
                </div>
            )}
        </PanelSection>
    );
}
