import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { Button, Notice } from '@/Components/ui';
import { api, newRequestKey } from '@/lib/api';
import type { MediaInfo } from '@/types';
import { useLeaveGuard } from '@/editor/session';
import { MediaPicker } from '@/editor/MediaPicker';
import type { SharedProps } from '@/types';
type Defaults = {
    titlePattern: string;
    description: string;
    socialImage: string;
    noindex: boolean;
    nofollow: boolean;
    organizationName: string;
    organizationType: string;
};
export default function SeoDefaults({
    settings,
    media,
}: {
    media: MediaInfo[];
    settings: { draft: Defaults; version: number; published: { version: number; values: Defaults }; refreshes: { pending: number; failed: number } };
}) {
    const { can } = usePage<SharedProps>().props;
    const canEdit = !!can['page.publish'];
    const [savedDraft, setSavedDraft] = useState(settings.draft);
    const [draft, setDraft] = useState(settings.draft);
    const [publishedVersion, setPublishedVersion] = useState(settings.published.version);
    const [version, setVersion] = useState(settings.version);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [attempt, setAttempt] = useState<{ key: string; value: Defaults; version: number } | null>(null);
    const [publishKey, setPublishKey] = useState<{ key: string; version: number } | null>(null);
    useLeaveGuard(() => JSON.stringify(draft) !== JSON.stringify(savedDraft) || !!attempt || !!publishKey || busy);
    const change = (key: keyof Defaults, value: string | boolean) => setDraft((d) => ({ ...d, [key]: value }));
    const save = async () => {
        setBusy(true);
        const a = attempt ?? { key: newRequestKey(), value: structuredClone(draft), version };
        setAttempt(a);
        try {
            const r = await api<{ version: number }>('/seo/defaults/save', { body: { settings: a.value, baseVersion: a.version, saveKey: a.key } });
            if (r.ok) {
                setVersion(r.data.version);
                setSavedDraft(a.value);
                setAttempt(null);
                setMessage('Defaults saved as a draft. Publish separately to refresh live pages.');
            } else {
                setMessage(r.message);
                setAttempt(null);
                setPublishKey(null);
            }
        } catch {
            setMessage('Save not confirmed. Retry the same saved batch.');
        } finally {
            setBusy(false);
        }
    };
    const publish = async () => {
        if (JSON.stringify(draft) !== JSON.stringify(savedDraft)) {
            setMessage('Save the changed defaults before publishing.');
            return;
        }
        if (!window.confirm('Publish these saved SEO defaults and refresh dependent live pages?')) return;
        setBusy(true);
        const intent = publishKey ?? { key: newRequestKey(), version };
        setPublishKey(intent);
        try {
            const r = await api<{ version: number }>('/seo/defaults/publish', { body: { expectedVersion: intent.version, idempotencyKey: intent.key } });
            if (r.ok) {
                setPublishedVersion(r.data.version);
                setPublishKey(null);
                setMessage('SEO defaults published. Live-page refreshes have been queued; failed refreshes can be retried from Design.');
            } else {
                setMessage(r.message);
                setPublishKey(null);
            }
        } catch {
            setMessage('Publish not confirmed. Retry with the same key.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <AdminLayout>
            <Head title="Site SEO defaults" />
            <div className="ak-page max-w-3xl space-y-5">
                <AdminPageHeader title="Site SEO defaults" description="Pages inherit published defaults unless they override them." />
                <Link className="ui-link" href="/admin/seo">
                    Back to SEO
                </Link>
                {message && <Notice tone="info">{message}</Notice>}
                <div className="space-y-5 rounded-lg border border-line bg-surface p-5">
                    {(['titlePattern', 'description', 'organizationName'] as const).map((key) => (
                        <div key={key}>
                            <label htmlFor={key} className="ui-label">
                                {key === 'titlePattern'
                                    ? 'Site title pattern'
                                    : key === 'description'
                                      ? 'Default meta description'
                                      : 'Organization / person name (optional)'}
                            </label>
                            {key === 'description' ? (
                                <textarea
                                    className="ui-input"
                                    id={key}
                                    rows={3}
                                    maxLength={320}
                                    value={draft[key]}
                                    disabled={!canEdit || busy || !!attempt || !!publishKey}
                                    onChange={(e) => change(key, e.target.value)}
                                />
                            ) : (
                                <input
                                    className="ui-input"
                                    id={key}
                                    maxLength={key === 'titlePattern' ? 160 : 120}
                                    value={draft[key]}
                                    disabled={!canEdit || busy || !!attempt || !!publishKey}
                                    onChange={(e) => change(key, e.target.value)}
                                />
                            )}
                            <p className="mt-1 text-xs text-muted">
                                {key === 'titlePattern'
                                    ? 'Use {page} and {site}. Each page can override the result.'
                                    : key === 'description'
                                      ? 'Leave blank and write unique page descriptions.'
                                      : 'Only provide an actual name; Arkon never invents business facts.'}
                            </p>
                        </div>
                    ))}
                    <div>
                        <p className="ui-label">Default social image</p>
                        <MediaPicker
                            value={draft.socialImage || null}
                            media={media}
                            canEdit={canEdit && !busy && !attempt && !publishKey}
                            canUpload={false}
                            onChoose={(id) => change('socialImage', id ?? '')}
                            onUpload={async () => null}
                        />
                        <p className="text-xs text-muted">Upload new images in Media, then choose one here. Pages can override this image.</p>
                    </div>
                    <label className="ui-label" htmlFor="organizationType">
                        Identity type
                    </label>
                    <select
                        id="organizationType"
                        className="ui-input"
                        value={draft.organizationType}
                        disabled={!canEdit || busy || !!attempt || !!publishKey}
                        onChange={(e) => change('organizationType', e.target.value)}
                    >
                        <option>Organization</option>
                        <option>Person</option>
                    </select>
                    {(['noindex', 'nofollow'] as const).map((key) => (
                        <label key={key} className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={draft[key]}
                                disabled={!canEdit || busy || !!attempt || !!publishKey}
                                onChange={(e) => change(key, e.target.checked)}
                            />
                            {key === 'noindex' ? 'Exclude inheriting pages from search results' : 'Ask crawlers not to follow links on inheriting pages'}
                        </label>
                    ))}
                    <p className="text-xs text-muted">
                        Canonical policy: use the registered site URL and live page path. Page overrides are available under Advanced. Sitemap uses published
                        indexing choices. Social titles and descriptions inherit search metadata.
                    </p>
                </div>
                <div className="flex gap-3">
                    <Button disabled={!canEdit || busy || !!publishKey} onClick={() => void save()}>
                        Save defaults
                    </Button>
                    <Button variant="primary" disabled={!canEdit || busy || !!attempt} onClick={() => void publish()}>
                        Publish saved defaults
                    </Button>
                </div>
                <p className="text-xs text-muted">
                    Published version {publishedVersion} · draft version {version}. Publishing never publishes unsaved page content.
                </p>
            </div>
        </AdminLayout>
    );
}
