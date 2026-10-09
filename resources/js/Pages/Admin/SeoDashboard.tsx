import { Head, Link } from '@inertiajs/react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ButtonLink, EmptyState } from '@/Components/ui';
import type { SeoReport } from '@/editor/SeoPanel';
import { seoColor } from '@/lib/seo';
export default function SeoDashboard({
    rows,
    page,
    total,
    summary,
    origin,
}: {
    rows: { id: string; title: string; path: string; draft: SeoReport | null; published: SeoReport | null; issue: string }[];
    page: number;
    total: number;
    origin: string;
    summary: {
        score: number | null;
        published: number;
        distribution: Record<string, number>;
        noindex: number;
        missingDescriptions: number;
        duplicateTitles: string[];
        brokenLinks: string[];
        duplicateCanonicals: string[];
    };
}) {
    return (
        <AdminLayout>
            <Head title="SEO" />
            <div className="ak-page space-y-6">
                <AdminPageHeader title="SEO" description="Find issues, improve drafts, and review what is live." />
                <div className="flex flex-wrap items-center justify-between gap-4 border-y border-line py-5">
                    <div>
                        <p className={`t-title ${summary.score === null ? 'text-muted' : seoColor(summary.score)}`}>
                            {summary.score === null ? 'No published score yet' : `${summary.score} / 100 — Published page average`}
                        </p>
                        <p className="mt-1 text-xs text-muted">{summary.published} published pages · deterministic checks, not a ranking prediction</p>
                    </div>
                    <ButtonLink href="/admin/seo/defaults">Site SEO defaults</ButtonLink>
                </div>
                <dl className="flex flex-wrap gap-6 text-sm">
                    {Object.entries(summary.distribution).map(([label, count]) => (
                        <div key={label}>
                            <dt className="text-xs text-muted">{label}</dt>
                            <dd
                                className={`mt-1 font-medium ${label === 'Excellent' || label === 'Good' ? 'text-live' : label === 'Needs improvement' ? 'text-changed' : 'text-danger'}`}
                            >
                                {count}
                            </dd>
                        </div>
                    ))}
                </dl>
                <section>
                    <h2 className="t-section">Page SEO</h2>
                    <p className="mt-2 text-xs text-muted">Draft and published scores stay separate. Saving does not change live metadata.</p>
                    {rows.length ? (
                        <div className="ui-management-list mt-4">
                            <table className="ui-management-table w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        {['Page', 'Draft SEO', 'Live SEO', 'Priority', ''].map((t) => (
                                            <th className="p-3 text-xs font-medium text-muted" key={t}>
                                                {t}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="border-t border-line">
                                            <td className="ui-cell-name p-3">
                                                <Link className="ui-link font-medium" href={`/admin/editor/${row.id}?panel=seo`}>
                                                    {row.title}
                                                </Link>
                                                <p className="text-xs text-muted">{row.path}</p>
                                            </td>
                                            <td className="p-3">
                                                <span className="ui-mobile-label text-xs text-muted">Draft: </span>
                                                {row.draft ? `${row.draft.score} — ${row.draft.label}` : 'Needs repair'}
                                            </td>
                                            <td className="p-3">
                                                <span className="ui-mobile-label text-xs text-muted">Live: </span>
                                                {row.published ? `${row.published.score} — ${row.published.label}` : 'Unpublished'}
                                            </td>
                                            <td className="ui-cell-date p-3 text-xs text-muted">{row.issue}</td>
                                            <td className="ui-cell-actions p-3">
                                                <ButtonLink size="sm" href={`/admin/editor/${row.id}?panel=seo`}>
                                                    Review / AI fix
                                                </ButtonLink>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <EmptyState icon="globe" title="Create a page to start managing SEO" />
                    )}
                    <div className="mt-4 flex justify-between text-xs">
                        <span>
                            {total} pages · page {page}
                        </span>
                        <div className="flex gap-4">
                            {page > 1 && <Link href={`/admin/seo?page=${page - 1}`}>Previous</Link>}
                            {page * 50 < total && <Link href={`/admin/seo?page=${page + 1}`}>Next</Link>}
                        </div>
                    </div>
                </section>
                <section className="border-t border-line pt-5">
                    <h2 className="t-section">Technical health</h2>
                    <dl className="mt-3 space-y-3 text-sm">
                        <div>
                            <dt>Published metadata</dt>
                            <dd className="text-xs text-muted">
                                {summary.missingDescriptions} pages have no description.{' '}
                                {summary.duplicateTitles.length
                                    ? `Repeated titles: ${summary.duplicateTitles.join(', ')}`
                                    : 'No duplicate published titles detected.'}
                            </dd>
                        </div>
                        <div>
                            <dt>Indexing choices</dt>
                            <dd className="text-xs text-muted">{summary.noindex} published pages ask not to be indexed. This may be intentional.</dd>
                        </div>
                        <div>
                            <dt>Internal destinations</dt>
                            <dd className="text-xs text-muted">
                                {summary.brokenLinks.length ? summary.brokenLinks.join(', ') : 'No missing live page destinations detected.'}
                            </dd>
                        </div>
                        <div>
                            <dt>Duplicate canonical URLs</dt>
                            <dd className="text-xs text-muted">
                                {summary.duplicateCanonicals.length ? summary.duplicateCanonicals.join(', ') : 'None detected on published pages.'}
                            </dd>
                        </div>
                        <div>
                            <dt>Sitemap and crawler rules</dt>
                            <dd className="mt-1 flex gap-4 text-xs">
                                <a className="ui-link" href={origin + '/sitemap.xml'} target="_blank" rel="noreferrer">
                                    View sitemap
                                </a>
                                <a className="ui-link" href={origin + '/robots.txt'} target="_blank" rel="noreferrer">
                                    View robots.txt
                                </a>
                            </dd>
                        </div>
                    </dl>
                    <p className="mt-4 text-xs text-muted">
                        Checks use Arkon’s stored HTML and live routes. External links, search engine indexing, rich-result eligibility and Core Web Vitals
                        require separate verification.
                    </p>
                </section>
            </div>
        </AdminLayout>
    );
}
