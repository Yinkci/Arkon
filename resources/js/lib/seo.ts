/** Status styling only; colors always accompany explicit score labels. */
export const seoColor = (score: number) => (score >= 70 ? 'text-live' : score >= 50 ? 'text-changed' : 'text-danger');
