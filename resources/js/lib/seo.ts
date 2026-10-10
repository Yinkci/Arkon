import type { IconName } from '@/Components/Icon';

/**
 * The score bands of App\Arkon\Seo\SeoAnalysis, with their styling. Colour always accompanies the
 * label and an icon: Excellent green, Good teal, Needs improvement amber, Needs attention red.
 */
export const SEO_BANDS = [
    { label: 'Excellent', min: 85, text: 'text-live', fill: 'bg-live', stroke: 'stroke-live', icon: 'check' },
    { label: 'Good', min: 70, text: 'text-site', fill: 'bg-site', stroke: 'stroke-site', icon: 'check' },
    { label: 'Needs improvement', min: 50, text: 'text-changed', fill: 'bg-changed', stroke: 'stroke-changed', icon: 'alert' },
    { label: 'Needs attention', min: 0, text: 'text-danger', fill: 'bg-danger', stroke: 'stroke-danger', icon: 'alert' },
] as const satisfies readonly { label: string; min: number; text: string; fill: string; stroke: string; icon: IconName }[];

export type SeoBand = (typeof SEO_BANDS)[number];

export const seoBand = (score: number): SeoBand => SEO_BANDS.find((band) => score >= band.min) ?? SEO_BANDS[SEO_BANDS.length - 1]!;

/** Status styling only; colors always accompany explicit score labels. */
export const seoColor = (score: number) => seoBand(score).text;

/** "85–100 Excellent · 70–84 Good · …", for the scale explanation. */
export const seoScale = SEO_BANDS.map((band, i) => `${band.min}–${i === 0 ? 100 : SEO_BANDS[i - 1]!.min - 1} ${band.label}`).join(' · ');
