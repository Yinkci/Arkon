// The official Arkon mark, the one source of product branding in the admin and the builder.
// Assets: resources/brand (256 px copies of the originals in resources/brand/source; see
// docs/ADMIN_DESIGN_SYSTEM.md). There is no wordmark: where a name is needed, the word "Arkon"
// is set as text beside the mark. Never used on public websites: those carry their own branding.
import markOnDark from '../../brand/arkon-mark-on-dark.png';
import markOnLight from '../../brand/arkon-mark-on-light.png';

export const ARKON_MARK = { onLight: markOnLight, onDark: markOnDark };

/**
 * `surface` is the background the mark sits on, not the theme name: `dark` (the navigation rail)
 * uses the white mark, `light` the graphite one, and `auto` follows the admin theme for marks on
 * workspace surfaces. Decorative unless `label` is given (a link around it usually names itself).
 */
export function ArkonLogo({ surface = 'auto', className = 'size-6', label }: { surface?: 'light' | 'dark' | 'auto'; className?: string; label?: string }) {
    const a11y = label ? { alt: label } : { alt: '', 'aria-hidden': true as const };
    const image = (src: string, extra = '') => (
        <img src={src} width={256} height={255} draggable={false} className={`shrink-0 select-none ${className} ${extra}`} {...a11y} />
    );
    if (surface === 'dark') return image(markOnDark);
    if (surface === 'light') return image(markOnLight);
    return (
        <>
            {image(markOnLight, 'dark:hidden')}
            {image(markOnDark, 'hidden dark:block')}
        </>
    );
}
