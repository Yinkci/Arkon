export const DROP_DURATION = 160;
export interface PreviewOrigin {
    x: number;
    y: number;
    rect: { left: number; top: number; width: number; height: number };
    scale?: number;
}
export function previewTransform(x: number, y: number, origin: PreviewOrigin): string {
    const scale = origin.scale ?? 1;
    return `translate3d(${x - (origin.x - origin.rect.left) * scale}px,${y - (origin.y - origin.rect.top) * scale}px,0)`;
}
export function placementAnimation(element: HTMLElement, frames: Keyframe[]): Animation | null {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return null;
    return element.animate(frames, { duration: DROP_DURATION, easing: 'ease-out' });
}
