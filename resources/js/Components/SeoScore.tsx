// SEO score indicators shared by the SEO workspace and the editor's SEO panel.
import { seoBand } from '@/lib/seo';
import { Icon } from './Icon';

/** The headline score: a restrained ring with the number inside and the band named beside it by the caller. */
export function ScoreRing({ score, size = 112 }: { score: number | null; size?: number }) {
    const stroke = 8;
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const band = score === null ? null : seoBand(score);
    return (
        <div className="relative shrink-0" style={{ width: size, height: size }}>
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true" className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={radius} fill="none" strokeWidth={stroke} className="stroke-sunken" />
                {band && score !== null && score > 0 && (
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        strokeWidth={stroke}
                        strokeLinecap="round"
                        strokeDasharray={`${(score / 100) * circumference} ${circumference}`}
                        className={`${band.stroke} transition-[stroke-dasharray] duration-500`}
                    />
                )}
            </svg>
            <div className="absolute inset-0 grid place-items-center text-center">
                <p className="leading-none">
                    <span className="block text-display font-semibold tracking-tight t-num" data-testid="seo-site-score">
                        {score ?? '–'}
                    </span>
                    <span className="mt-1 block t-meta">/ 100</span>
                </p>
            </div>
        </div>
    );
}

/** A compact score for tables and lists: icon, number and band name (never colour alone). */
export function ScoreMark({ score, label, className = '' }: { score: number; label?: string; className?: string }) {
    const band = seoBand(score);
    return (
        <span className={`inline-flex items-center gap-1.5 whitespace-nowrap ${className}`}>
            <Icon name={band.icon} className={`size-3.5 ${band.text}`} />
            <span className="font-semibold t-num">{score}</span>
            <span className={`text-xs ${band.text}`}>{label ?? band.label}</span>
        </span>
    );
}
