// Twin of app/Arkon/Schema/PagePath.php.
import { matches, message, rules } from '../rules';

/** Problems with a page URL path; empty when valid. */
export function pathIssues(path: unknown): string[] {
    if (typeof path !== 'string') return [message('expectedString')];
    const issues: string[] = [];
    if (path.length > rules.limits.path) issues.push(message('tooLong', { max: rules.limits.path }));
    if (!(path === '/' || (path.startsWith('/') && !path.endsWith('/')))) issues.push(message('pathShape'));
    if (
        path !== '/' &&
        !path
            .slice(1)
            .split('/')
            .every((segment) => matches('pathSegment', segment))
    ) {
        issues.push(message('pathSegments'));
    }
    if (isReservedFirstSegment(path.split('/')[1] ?? '')) issues.push(message('pathReserved'));
    return issues;
}

export function isReservedFirstSegment(segment: string): boolean {
    return rules.reservedPathSegments.includes(segment);
}

/** Suggested URL for a title: "Pricing Plans" → "/pricing-plans". */
export function slugify(title: string): string {
    const slug = title
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return slug ? `/${slug}` : '';
}
