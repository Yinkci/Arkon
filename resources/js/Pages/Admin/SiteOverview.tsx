import { Head, Link } from '@inertiajs/react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ButtonLink, EmptyState } from '@/Components/ui';
interface Row {
    id: string;
    title: string;
    path: string;
    livePath: string | null;
    description: string;
    liveDescription: string | null;
    noindex: boolean;
    liveNoindex: boolean | null;
}
export default function SiteOverview(props: {
    section: 'seo' | 'settings';
    identity?: { name: string; language: string };
    origin?: string;
    domains?: string[];
    rows?: Row[];
    page?: number;
    total?: number;
}) {
    const settings = props.section === 'settings';
    return (
        <AdminLayout>
            <Head title={settings ? 'Site settings' : 'SEO overview'} />
            <div className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-8 sm:py-8">
                <AdminPageHeader
                    title={settings ? 'Site settings' : 'SEO overview'}
                    description={
                        settings
                            ? 'Review the identity and domains configured for this website.'
                            : 'Review page descriptions and indexing choices. Edit metadata in the same page builder.'
                    }
                />
                {settings ? (
                    <>
                        <section className="border-t border-line pt-5">
                            <h2 className="text-base font-semibold">General</h2>
                            <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                                {[
                                    ['Site name', props.identity?.name],
                                    ['Language', props.identity?.language],
                                    ['Site URL', props.origin],
                                    ['Registered domains', props.domains?.join(', ') || 'None'],
                                ].map(([name, value]) => (
                                    <div key={name}>
                                        <dt className="text-xs text-muted">{name}</dt>
                                        <dd className="mt-1 break-words text-sm">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="mt-5 text-sm text-muted">
                                These values are currently configured during setup. Editing site identity and domains needs versioned settings so it cannot
                                silently change existing publications.
                            </p>
                        </section>
                        <section className="border-t border-line pt-5">
                            <h2 className="font-semibold">Looking for another setting?</h2>
                            <div className="mt-3 flex flex-wrap gap-3">
                                <ButtonLink href="/admin/navigation">Navigation</ButtonLink>
                                <ButtonLink href="/admin/design">Global styles</ButtonLink>
                                <ButtonLink href="/admin/forms">Form notifications</ButtonLink>
                            </div>
                        </section>
                    </>
                ) : (
                    <>
                        <p className="text-sm text-muted">
                            Draft settings and live settings are shown separately. Saving SEO changes does not update the live page until publishing.
                        </p>
                        {props.rows?.length ? (
                            <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b border-line text-xs text-muted">
                                        <tr>
                                            {['Page', 'Draft description', 'Live description', 'Indexing', ''].map((label, i) => (
                                                <th key={i} className="p-3 font-medium">
                                                    {label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {props.rows.map((row) => (
                                            <tr key={row.id} className="border-b border-line last:border-0">
                                                <td className="p-3">
                                                    <p className="font-medium">{row.title}</p>
                                                    <p className="text-xs text-muted">{row.path}</p>
                                                </td>
                                                <td className="p-3">{row.description ? 'Set' : 'Missing'}</td>
                                                <td className="p-3">{row.livePath ? (row.liveDescription ? 'Set' : 'Missing') : 'Not published'}</td>
                                                <td className="p-3 text-xs">
                                                    Draft: {row.noindex ? 'Excluded' : 'Allowed'}
                                                    <br />
                                                    Live: {row.livePath ? (row.liveNoindex ? 'Excluded' : 'Allowed') : '—'}
                                                </td>
                                                <td className="p-3">
                                                    <Link className="text-accent hover:underline" href={'/admin/editor/' + row.id}>
                                                        Edit SEO
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <EmptyState icon="globe" title="Create a page to start managing SEO">
                                Each page has editable metadata in the builder.
                            </EmptyState>
                        )}
                        <div className="flex items-center justify-between text-sm">
                            <span>
                                {props.total} pages · page {props.page}
                            </span>
                            <div className="flex gap-3">
                                {(props.page ?? 1) > 1 && <Link href={'/admin/seo?page=' + ((props.page ?? 1) - 1)}>Previous</Link>}
                                {(props.page ?? 1) * 50 < (props.total ?? 0) && <Link href={'/admin/seo?page=' + ((props.page ?? 1) + 1)}>Next</Link>}
                            </div>
                        </div>
                        <div className="flex gap-4 text-sm">
                            <a href="/sitemap.xml" target="_blank" rel="noreferrer" className="text-accent">
                                View sitemap
                            </a>
                            <a href="/robots.txt" target="_blank" rel="noreferrer" className="text-accent">
                                View crawler rules
                            </a>
                        </div>
                        <p className="text-xs text-muted">
                            Allowed indexing is a preference, not confirmation that a search engine has indexed the page. No automated SEO score has been
                            collected.
                        </p>
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
