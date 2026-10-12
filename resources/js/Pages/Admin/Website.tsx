// Build a website: an AI workspace. The assistant (request, progress, proposal, history) sits in a narrow
// panel on the left; the actual proposed website fills the rest at a true device width. Details, change
// lists and diagnostics are disclosed on request. All server calls are unchanged (see WebsiteController).
import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState, type MutableRefObject } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { Icon } from '@/Components/Icon';
import { toast } from '@/Components/Toast';
import { buttonClass, Skeleton, StatusPill } from '@/Components/ui';
import { DeviceSwitch, PreviewStage, ZoomSelect } from '@/Components/website/PreviewStage';
import { PublishDialog } from '@/Components/website/PublishDialog';
import { ComponentBadges, Disclosure, HistoryList, LiveTiming, PanelTabs, ProviderSelect, SimpleSteps, StageList, StateDot } from '@/Components/website/parts';
import {
    imagesOf,
    imagesPending,
    isWorking,
    providerName,
    requestTitle,
    SCOPE_LABEL,
    type Connection,
    type Device,
    type ImageChoice,
    type Proposal,
    type Readiness,
    type Scope,
    type Zoom,
} from '@/Components/website/types';
import { api, newRequestKey } from '@/lib/api';
import { selectedProvider } from '@/lib/aiProvider';
import { requestsGeneratedImage } from '@/lib/imageIntent';

type Attempt = { body: string; key: string };
type Tab = 'ai' | 'history';
const NO_IMAGES: Connection = { ready: false, selectionState: 'unavailable', message: 'Image generation status is not available.', providers: [] };
const PANEL_KEY = 'arkon.website.panel';

function readPanel() {
    try {
        return localStorage.getItem(PANEL_KEY) !== 'closed';
    } catch {
        return true;
    }
}

/** Which of the plain steps a request is on (used when the run reports no stage list of its own). */
function currentStep(p: Proposal): string {
    if (p.status === 'queued') return 'Reading your site';
    if (p.status === 'running') {
        if (p.activity === 'ready') return 'Preparing review';
        if (p.activity === 'checking' || p.activity === 'repairing') return 'Checking against builder rules';

        return 'Building the proposal';
    }
    const img = imagesOf(p).find((i) => i.kind === 'generated' && ['queued', 'running'].includes(i.status));

    return img?.activity === 'optimizing' ? 'Optimizing image' : 'Generating image';
}

export default function Website(props: {
    requests: Proposal[];
    connection: Connection;
    imageConnection: Connection;
    permissions: { edit: boolean; publish: boolean };
}) {
    const [requests, setRequests] = useState(props.requests),
        [connection, setConnection] = useState(props.connection),
        [imageConnection, setImageConnection] = useState(props.imageConnection ?? NO_IMAGES),
        [prompt, setPrompt] = useState(''),
        [provider, setProvider] = useState<string | undefined>(undefined),
        [imageChoice, setImageChoice] = useState<ImageChoice>(undefined),
        [imageProvider, setImageProvider] = useState<string | undefined>(undefined),
        [selected, setSelected] = useState<string | null>(props.requests[0]?.id ?? null),
        [index, setIndex] = useState(0),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [allowRepair, setAllowRepair] = useState(false),
        [includeLayout, setIncludeLayout] = useState(true),
        [pollError, setPollError] = useState(false),
        [check, setCheck] = useState<Readiness | null>(null),
        [publishing, setPublishing] = useState(false),
        [confirmShared, setConfirmShared] = useState(false),
        // A new website request while a proposal is selected (otherwise the composer requests changes to it).
        [fresh, setFresh] = useState(false),
        // Revision target: 'page', 'website' or 'section:<node id>' (on the page shown in the preview).
        [target, setTarget] = useState('page'),
        // Workspace view state.
        [tab, setTab] = useState<Tab>('ai'),
        [device, setDevice] = useState<Device>(() => (typeof window !== 'undefined' && window.innerWidth < 768 ? 'mobile' : 'desktop')),
        [zoom, setZoom] = useState<Zoom>('fit'),
        [before, setBefore] = useState(false),
        [baselineOpened, setBaselineOpened] = useState(false),
        [panelOpen, setPanelOpenState] = useState(readPanel),
        [drawer, setDrawer] = useState(props.requests.length === 0),
        [fullscreen, setFullscreen] = useState(false),
        [options, setOptions] = useState(false),
        [fullRequest, setFullRequest] = useState(false),
        [devDetails, setDevDetails] = useState(false);
    const briefAttempt = useRef<Attempt | null>(null),
        revisionAttempt = useRef<Attempt | null>(null),
        retryAttempts = useRef<Record<string, string>>({}),
        publishAttempt = useRef<{ id: string; key: string } | null>(null),
        promptRef = useRef<HTMLTextAreaElement>(null);
    const ids = useId();

    const proposal = requests.find((r) => r.id === selected);
    const parent = proposal?.parentId ? requests.find((r) => r.id === proposal.parentId) : undefined;
    const pending = requests.some((r) => isWorking(r) || imagesPending(r));
    const layoutPending = requests.some(isWorking);
    const canRevise = !!proposal && ['proposed', 'applied'].includes(proposal.status) && props.permissions.edit;
    const mode: 'brief' | 'revise' = canRevise && !fresh ? 'revise' : 'brief';
    const attempt = mode === 'revise' ? revisionAttempt : briefAttempt;
    const effectiveConnection = selectedProvider(connection, provider);
    const imageSel = selectedProvider(imageConnection, imageProvider);

    // The preview: the selected proposal, or its starting point while a revision is prepared (or failed).
    const shown = proposal?.result ? proposal : parent?.result ? parent : undefined;
    const pages = shown?.result?.pages ?? [];
    const pageIndex = Math.min(index, Math.max(0, pages.length - 1));
    const page = pages[pageIndex];
    const compareAvailable = !!shown?.revision && shown === proposal;
    const imageKey = imagesOf(shown)
        .map((i) => i.status)
        .join();
    const previewBase = shown ? `/admin/website/${shown.id}/preview/${pageIndex}` : '';
    const previewSrc = previewBase + (imageKey ? `?v=${encodeURIComponent(imageKey)}` : '');

    const sections = proposal?.sections?.[pageIndex] ?? [];
    const sectionTarget = target.startsWith('section:') ? target.slice(8) : '';
    const scope: Scope = target === 'website' ? 'website' : sectionTarget && sections.some((s) => s.id === sectionTarget) ? 'section' : 'page';

    const setPanelOpen = useCallback((open: boolean) => {
        setPanelOpenState(open);
        try {
            localStorage.setItem(PANEL_KEY, open ? 'open' : 'closed');
        } catch {
            // Storage unavailable: the choice lasts for this visit.
        }
    }, []);

    const refresh = async () => {
        const r = await api<{ requests: Proposal[]; connection: Connection; imageConnection: Connection }>('/website/requests');
        if (r.ok) {
            setPollError(false);
            setRequests(r.data.requests);
            // Keep the selection while it is listed; otherwise show the newest request.
            setSelected((current) => (current && r.data.requests.some((p) => p.id === current) ? current : (r.data.requests[0]?.id ?? null)));
            setConnection(r.data.connection);
            setImageConnection(r.data.imageConnection ?? NO_IMAGES);
        } else {
            setPollError(true);
        }
        return r;
    };
    useEffect(() => {
        let active = true;
        const tick = setInterval(
            () => {
                if (active) void refresh().catch(() => setPollError(true));
            },
            pending ? 2500 : 10000,
        );
        return () => {
            active = false;
            clearInterval(tick);
        };
    }, [pending]);
    useEffect(() => {
        if (!fullscreen && !drawer) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') return;
            setFullscreen(false);
            setDrawer(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [fullscreen, drawer]);

    const select = (id: string) => {
        setSelected(id);
        setIndex(0);
        setCheck(null);
        setBefore(false);
        setBaselineOpened(false);
        setConfirmShared(false);
        setFresh(false);
        setTarget('page');
        setTab('ai');
        setFullRequest(false);
        setDevDetails(false);
    };

    /**
     * Sends a request once: availability is refreshed first (nothing starts if the needed capability is
     * missing), the body and its request key are kept until the server confirms, so a lost response or a
     * repeated click replays the same request instead of starting another run.
     */
    const send = async (attempt: MutableRefObject<Attempt | null>, build: (text: Connection, image: Connection) => Record<string, unknown> | string) => {
        let a = attempt.current;
        if (!a) {
            const status = await refresh();
            if (!status.ok) {
                setNotice(status.message);
                return null;
            }
            const body = build(status.data.connection, status.data.imageConnection ?? NO_IMAGES);
            if (typeof body === 'string') {
                setNotice(body);
                return null;
            }
            a = { body: JSON.stringify(body), key: newRequestKey() };
            attempt.current = a;
        }
        const r = await api<Proposal>('/website/requests', { body: { ...JSON.parse(a.body), requestKey: a.key } });
        if (!r.ok) {
            if (r.code !== 'INTERNAL') attempt.current = null;
            setNotice(r.message);
            await refresh();
            return null;
        }
        attempt.current = null;
        setRequests((rs) => [r.data, ...rs.filter((p) => p.id !== r.data.id)]);
        select(r.data.id);
        return r.data;
    };
    const imageFields = (choice: ImageChoice, image: Connection) => {
        if (choice !== 'generate') return choice ? { images: choice } : {};
        const s = selectedProvider(image, imageProvider);
        if (!s.ready || !s.provider) return null;
        return { images: 'generate', imageProvider: s.provider, imageSelectionMode: imageProvider ? 'explicit' : 'automatic' };
    };
    /**
     * The image choice the request is sent with. A request that asks for a new image generates one when image
     * generation is available (shown as a one-line confirmation the user can turn off), otherwise it continues
     * without; the server still refuses to guess, so the choice is always explicit in the request.
     */
    const imagesFor = (image: Connection): ImageChoice => imageChoice ?? (requestsGeneratedImage(prompt) ? (image.ready ? 'generate' : 'library') : undefined);
    const submit = async () => {
        if (busy || layoutPending || !prompt.trim()) return;
        if (mode === 'revise' && (!proposal || (scope !== 'website' && !page))) return;
        setNotice('');
        setBusy(true);
        try {
            const created = await send(attempt, (text, image) => {
                const s = selectedProvider(text, provider);
                if (!s.ready || !s.provider) return s.message;
                const img = imageFields(imagesFor(image), image);
                if (img === null) return 'Image generation is no longer available. ' + image.message;
                const common = { prompt, allowRepair, provider: s.provider, selectionMode: provider ? 'explicit' : 'automatic', ...img };
                return mode === 'revise'
                    ? {
                          ...common,
                          parentId: proposal!.id,
                          target: { scope, pageId: scope === 'website' ? null : page?.id, nodeId: scope === 'section' ? sectionTarget : null },
                      }
                    : { ...common, includeLayout };
            });
            if (created) {
                setPrompt('');
                setImageChoice(undefined);
                setProvider(undefined);
            }
        } catch {
            setNotice('Request could not be confirmed. Send again to check the same request.');
        } finally {
            setBusy(false);
        }
    };
    const apply = async (withoutMissingImages = false) => {
        if (!proposal || busy) return;
        if (withoutMissingImages && !confirm('Apply the layout without the generated image that is missing? The image requirement stays unmet.')) return;
        setBusy(true);
        try {
            const r = await api(`/website/${proposal.id}/apply`, {
                body: { ...(withoutMissingImages ? { withoutMissingImages: true } : {}), ...(confirmShared ? { confirmShared: true } : {}) },
            });
            if (r.ok) {
                toast('Applied as drafts. Publish when you are ready; Arkon checks the website first.');
                await refresh();
            } else setNotice(r.message);
        } catch {
            setNotice('Apply could not be confirmed. Retry Apply; Arkon applies each proposal once.');
        } finally {
            setBusy(false);
        }
    };
    const discard = async () => {
        if (!proposal || busy) return;
        setBusy(true);
        try {
            const r = await api(`/website/${proposal.id}/discard`, { body: {} });
            if (r.ok) await refresh();
            else setNotice(r.message);
        } catch {
            setNotice('Could not confirm cancellation.');
        } finally {
            setBusy(false);
        }
    };
    /** Continues a failed request; finished steps are reused (the server checks they still match). */
    const resume = async (id: string) => {
        if (busy) return;
        setBusy(true);
        try {
            const r = await api<Proposal>(`/website/${id}/resume`, { body: {} });
            if (r.ok) {
                setRequests((rs) => rs.map((p) => (p.id === id ? r.data : p)));
                toast('Resumed. Finished steps are reused; only the rest is generated.');
            } else setNotice(r.message);
        } catch {
            setNotice('Resume could not be confirmed. Try again; resuming twice changes nothing.');
        } finally {
            setBusy(false);
        }
    };
    const retryImage = async (assetId: string) => {
        if (busy) return;
        setBusy(true);
        const key = (retryAttempts.current[assetId] ??= newRequestKey());
        try {
            const r = await api(`/website/images/${assetId}/retry`, { body: { requestKey: key } });
            if (r.ok) {
                delete retryAttempts.current[assetId];
                toast('Only the image will be generated again. The layout is kept.');
                await refresh();
            } else {
                if (r.code !== 'INTERNAL') delete retryAttempts.current[assetId];
                setNotice(r.message);
            }
        } catch {
            setNotice('The retry could not be confirmed. Retry again to check the same request.');
        } finally {
            setBusy(false);
        }
    };
    /** Opens the publish dialog and runs the readiness check (a dry run of publishing) inside it. */
    const readiness = async () => {
        if (!proposal) return;
        setCheck(null);
        setPublishing(true);
        try {
            const r = await api<Readiness>(`/website/${proposal.id}/readiness`);
            if (r.ok) setCheck(r.data);
            else {
                setPublishing(false);
                setNotice(r.message);
            }
        } catch {
            setPublishing(false);
            setNotice('Could not check publishing readiness.');
        }
    };
    /** Resolves to an error for the dialog, or null once published. */
    const publish = async (): Promise<string | null> => {
        if (!proposal || !check?.ready) return 'Check the website again before publishing.';
        const a = publishAttempt.current?.id === proposal.id ? publishAttempt.current : { id: proposal.id, key: newRequestKey() };
        publishAttempt.current = a;
        setBusy(true);
        try {
            const r = await api(`/website/${a.id}/publish`, { body: { requestKey: a.key } });
            if (!r.ok) return r.message;
            publishAttempt.current = null;
            setCheck(null);
            toast('Website published. All pages and shared resources went live together.');
            return null;
        } catch {
            return 'Publishing could not be confirmed. Publish again to check the original result.';
        } finally {
            setBusy(false);
        }
    };
    /** A failed or cancelled revision, prepared again on its parent (same request, target and image choice); sent only by the user. */
    const retryRevision = (failed: Proposal, from: Proposal, broaden: boolean) => {
        const t = failed.revision;
        if (!t) return;
        const at = Math.max(0, from.result?.pages.findIndex((p) => p.id === t.pageId) ?? 0);
        select(from.id);
        setIndex(at);
        setTarget(
            broaden
                ? t.scope === 'section'
                    ? 'page'
                    : 'website'
                : t.scope === 'section' && t.nodeId
                  ? `section:${t.nodeId}`
                  : t.scope === 'website'
                    ? 'website'
                    : 'page',
        );
        setPrompt(failed.prompt);
        setImageChoice(failed.imageMode ?? undefined);
        revisionAttempt.current = null;
        promptRef.current?.focus();
    };
    const startNew = () => {
        setFresh(true);
        setTab('ai');
        setPrompt('');
        setImageChoice(undefined);
        setDrawer(true);
        if (!panelOpen) setPanelOpen(true);
        requestAnimationFrame(() => promptRef.current?.focus());
    };

    const generated = imagesOf(proposal).filter((i) => i.kind === 'generated');
    const library = imagesOf(proposal).filter((i) => i.kind !== 'generated');
    const missingImages = generated.filter((i) => i.status !== 'ready');
    const failedImages = missingImages.filter((i) => ['failed', 'cancelled', 'missing'].includes(i.status));
    const sharedImpacts = (proposal?.impacts ?? []).filter((i) => i.otherPages.length > 0);
    const locked = busy || layoutPending || !!attempt.current;
    const readyProviders = connection.providers?.filter((p) => p.ready) ?? [];
    const providerChoiceNeeded = !!provider || (!connection.ready && readyProviders.length > 0);
    const readyImageProviders = imageConnection.providers?.filter((p) => p.ready) ?? [];
    const result = proposal?.result;
    const changeLists =
        result?.revisionChanges ?? result?.pages.map((p) => ({ page: `${p.existing ? 'Update' : 'Create'} ${p.title} (${p.path})`, changes: p.changes })) ?? [];
    const changeCount = changeLists.reduce((n, c) => n + c.changes.length, 0);
    const images = imagesFor(imageConnection);
    const asksForImage = requestsGeneratedImage(prompt);
    const imageBy = imageSel.provider && imageSel.provider !== effectiveConnection.provider ? ` with ${imageSel.providerName}` : '';
    const isRevision = !!proposal?.parentId;
    /** The step a failed run stopped at, named for people ("the Services page"); finished steps are kept for Retry. */
    const failedUnit = (() => {
        const label = proposal?.diagnostics?.stages?.find((s) => s.status === 'failed')?.label;
        if (!label) return null;
        if (label.startsWith('Page ')) return `${label.slice(5).replace(/ \((\d+\/\d+|repair)\)$/, '')} page`;
        if (label.startsWith('Shared')) return 'shared header and footer';
        if (label.startsWith('Understanding')) return 'plan';
        return null;
    })();
    // The draft page shown in the canvas, once the proposal is applied (opened in the builder from the toolbar).
    const appliedPage = proposal?.applied?.pages.find((p) => p.id === page?.id);

    /** What the panel is showing: one state at a time, from the selected request's real status. */
    const view: 'empty' | 'working' | 'ready' | 'applied' | 'error' | 'closed' =
        !proposal || fresh
            ? 'empty'
            : isWorking(proposal) || (proposal.status === 'proposed' && imagesPending(proposal))
              ? 'working'
              : proposal.status === 'proposed'
                ? 'ready'
                : proposal.status === 'applied'
                  ? 'applied'
                  : proposal.status === 'failed'
                    ? 'error'
                    : 'closed';

    /** "About Us updated", "3 pages created", "1 image generated": what changed, in the user's words. */
    const outcome = (() => {
        if (!result) return [];
        const lines = result.revisionChanges
            ? result.revisionChanges.map((c) => `${c.page.replace(/ \(\/[^)]*\)$/, '')} updated`)
            : [
                  ...[result.pages.filter((p) => !p.existing), result.pages.filter((p) => p.existing)].flatMap((group, i) =>
                      group.length === 0
                          ? []
                          : [group.length === 1 ? `${group[0]!.title} ${i ? 'updated' : 'created'}` : `${group.length} pages ${i ? 'updated' : 'created'}`],
                  ),
              ];
        const made = generated.filter((i) => i.status === 'ready').length;
        return [...new Set(lines)].concat(made ? [`${made} image${made === 1 ? '' : 's'} generated`] : []);
    })();

    // ── the assistant panel ──────────────────────────────────────────────────────────────
    const composer = props.permissions.edit && view !== 'working' && (
        <section
            aria-label={mode === 'revise' ? 'Request changes' : 'Website request'}
            // The divider belongs between the two bands, so it is only drawn when a result follows.
            className={`ak-band space-y-2 ${proposal && !fresh ? 'border-b border-line' : ''}`}
        >
            <label htmlFor="website-prompt" className="block t-eyebrow">
                {mode === 'revise' ? 'Describe a change' : 'What would you like to build?'}
            </label>
            <textarea
                ref={promptRef}
                id="website-prompt"
                className="ui-input min-h-[5.5rem] resize-y p-2.5 text-ui"
                rows={3}
                maxLength={6000}
                value={prompt}
                disabled={locked}
                onChange={(e) => setPrompt(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) void submit();
                }}
                placeholder={mode === 'revise' ? 'Describe what you’d like to change…' : 'Describe the website you want to build…'}
            />
            {asksForImage && (
                <p className="flex flex-wrap items-center gap-x-1.5 text-xs text-muted">
                    <Icon name="image" className="size-3.5" />
                    {images === 'generate' ? `A new image will be generated${imageBy}.` : 'No new image will be generated.'}
                    {images === 'generate' ? (
                        <button type="button" className="ui-link" disabled={locked} onClick={() => setImageChoice('library')}>
                            Don’t generate
                        </button>
                    ) : imageConnection.ready ? (
                        <button type="button" className="ui-link" disabled={locked} onClick={() => setImageChoice('generate')}>
                            Generate one
                        </button>
                    ) : (
                        <span>(image generation is unavailable)</span>
                    )}
                </p>
            )}
            {providerChoiceNeeded && <ProviderSelect connection={connection} value={provider} onChange={setProvider} disabled={locked} />}
            <div className="flex items-center justify-end gap-2">
                {fresh && canRevise && (
                    <button type="button" className={buttonClass('ghost')} disabled={busy} onClick={() => setFresh(false)}>
                        Cancel
                    </button>
                )}
                <button
                    type="button"
                    disabled={busy || layoutPending || (!effectiveConnection.ready && !attempt.current) || !prompt.trim()}
                    title="Ctrl+Enter"
                    // Apply is the primary action while a result waits for review, so sending stays quiet and small.
                    className={buttonClass(view === 'ready' ? 'secondary' : 'primary', 'md', view === 'ready' ? 'ui-action' : 'ui-action flex-1')}
                    onClick={() => void submit()}
                >
                    {mode === 'revise' ? 'Request changes' : 'Generate proposal'}
                </button>
            </div>
            {notice && (
                <p role="alert" className="flex items-start gap-2 text-ui text-changed">
                    <Icon name="alert" className="mt-0.5 size-4 shrink-0" />
                    <span className="min-w-0 flex-1">{notice}</span>
                    <button type="button" className="-m-1 rounded p-1 text-faint hover:text-fg" aria-label="Dismiss" onClick={() => setNotice('')}>
                        <Icon name="close" className="size-3.5" />
                    </button>
                </p>
            )}
            <div>
                <button
                    type="button"
                    aria-expanded={options}
                    aria-controls={`${ids}-options`}
                    className="group/adv -ml-1 inline-flex items-center gap-1 rounded px-1 py-0.5 text-xs font-medium text-muted transition-colors hover:text-fg focus-visible:outline-2 focus-visible:outline-accent"
                    onClick={() => setOptions(!options)}
                >
                    <Icon name="chevronRight" className={`size-3 transition-transform duration-150 ${options ? 'rotate-90' : ''}`} />
                    Advanced options
                </button>
                <div id={`${ids}-options`} hidden={!options} className="mt-3 space-y-3 border-l border-line pl-3">
                    {mode === 'revise' && (
                        <fieldset className="space-y-1.5">
                            <legend className="mb-1 t-label">Apply changes to</legend>
                            <label className="ui-check">
                                <input type="radio" name={`${ids}-scope`} disabled={locked} checked={scope === 'page'} onChange={() => setTarget('page')} />
                                This page only
                            </label>
                            {sections.length > 0 && (
                                <label className="ui-check">
                                    <input
                                        type="radio"
                                        name={`${ids}-scope`}
                                        disabled={locked}
                                        checked={scope === 'section'}
                                        onChange={() => setTarget(`section:${sectionTarget || sections[0]!.id}`)}
                                    />
                                    One section
                                </label>
                            )}
                            {scope === 'section' && (
                                <select
                                    aria-label="Section"
                                    className="ui-input ml-6 w-[calc(100%-1.5rem)]"
                                    disabled={locked}
                                    value={sectionTarget}
                                    onChange={(e) => setTarget(`section:${e.target.value}`)}
                                >
                                    {sections.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <label className="ui-check">
                                <input
                                    type="radio"
                                    name={`${ids}-scope`}
                                    disabled={locked}
                                    checked={scope === 'website'}
                                    onChange={() => setTarget('website')}
                                />
                                The whole website
                            </label>
                        </fieldset>
                    )}
                    <label className="ui-check" title={imageConnection.ready ? undefined : imageConnection.message}>
                        <input
                            type="checkbox"
                            disabled={locked || !imageConnection.ready}
                            checked={images === 'generate'}
                            onChange={(e) => setImageChoice(e.target.checked ? 'generate' : asksForImage ? 'library' : undefined)}
                        />
                        <span>
                            Generate new images{imageBy}
                            {!imageConnection.ready && <span className="text-muted"> (unavailable)</span>}
                        </span>
                    </label>
                    {mode === 'brief' && (
                        <label className="ui-check">
                            <input type="checkbox" disabled={locked} checked={includeLayout} onChange={(e) => setIncludeLayout(e.target.checked)} />
                            Shared header, navigation and footer
                        </label>
                    )}
                    <label className="ui-check">
                        <input type="checkbox" disabled={locked} checked={allowRepair} onChange={(e) => setAllowRepair(e.target.checked)} />
                        <span>
                            Automatic repair
                            <span className="block t-meta">One more attempt if the result fails validation.</span>
                        </span>
                    </label>
                    {!providerChoiceNeeded && readyProviders.length > 1 && (
                        <ProviderSelect connection={connection} value={provider} onChange={setProvider} disabled={locked} />
                    )}
                    {images === 'generate' && readyImageProviders.length > 1 && (
                        <ProviderSelect
                            connection={imageConnection}
                            value={imageProvider}
                            onChange={setImageProvider}
                            disabled={locked}
                            label="Image generation connection"
                        />
                    )}
                </div>
            </div>
        </section>
    );

    // One word for the thing being reviewed, whether it is the first website or a change to it: a proposal.
    const statusTitle =
        view === 'working'
            ? isWorking(proposal)
                ? 'Preparing your proposal…'
                : 'Generating the image…'
            : view === 'ready'
              ? 'Proposal ready'
              : view === 'applied'
                ? 'Applied as drafts'
                : view === 'error'
                  ? failedUnit
                      ? `Couldn’t finish the ${failedUnit}`
                      : 'Couldn’t prepare the proposal'
                  : proposal?.status === 'empty'
                    ? 'No changes were needed'
                    : proposal?.status === 'cancelled'
                      ? 'Cancelled'
                      : 'Discarded';

    const current = proposal && view !== 'empty' && (
        // One band, one left edge: the state's mark sits in that edge and everything below starts at it.
        <section aria-label="Current proposal" className="ak-band space-y-2.5" aria-live="polite">
            <div className="flex items-center gap-2">
                <span aria-hidden="true" className="grid size-4 shrink-0 place-items-center">
                    {view === 'error' ? (
                        <Icon name="alert" className="size-4 text-danger" />
                    ) : view === 'closed' ? (
                        <Icon name="info" className="size-4 text-faint" />
                    ) : (
                        <StateDot working={view === 'working'} />
                    )}
                </span>
                <h2
                    // The heading carries the state's colour, as the reference does: the outcome is readable at a glance.
                    className={`min-w-0 flex-1 truncate t-title ${view === 'error' ? 'text-danger' : view === 'ready' || view === 'applied' ? 'text-live' : ''}`}
                >
                    {statusTitle}
                </h2>
                <button
                    type="button"
                    className="-my-1 -mr-1.5 grid size-7 shrink-0 place-items-center rounded-md text-faint hover:bg-hover hover:text-fg"
                    aria-label="Details for developers"
                    title="Details for developers"
                    aria-expanded={devDetails}
                    onClick={() => setDevDetails(!devDetails)}
                >
                    <Icon name="dots" className="size-4" />
                </button>
            </div>
            <div className="space-y-0.5">
                {proposal.summary ? (
                    <>
                        <p className="t-meta">{proposal.summary}</p>
                        {fullRequest && <p className="t-meta">“{proposal.prompt}”</p>}
                        <button type="button" className="ui-link text-xs" aria-expanded={fullRequest} onClick={() => setFullRequest(!fullRequest)}>
                            {fullRequest ? 'Hide your request' : 'View your request'}
                        </button>
                    </>
                ) : (
                    <>
                        <p className={`t-meta ${fullRequest ? '' : 'line-clamp-2'}`}>“{proposal.prompt}”</p>
                        {proposal.prompt.length > 110 && (
                            <button type="button" className="ui-link text-xs" aria-expanded={fullRequest} onClick={() => setFullRequest(!fullRequest)}>
                                {fullRequest ? 'Show less' : 'View full request'}
                            </button>
                        )}
                    </>
                )}
            </div>
            {pollError && (
                <p role="alert" className="flex items-center gap-2 text-ui text-changed">
                    <Icon name="alert" className="size-4" />
                    Cannot refresh status. The request may still be running. Reconnecting…
                </p>
            )}

            {view === 'working' && (
                <div className="space-y-3">
                    {proposal.progress?.stages?.length && isWorking(proposal) ? (
                        <StageList stages={proposal.progress.stages} live />
                    ) : (
                        <SimpleSteps
                            current={currentStep(proposal)}
                            steps={[
                                'Reading your site',
                                'Building the proposal',
                                'Checking against builder rules',
                                ...(proposal.imageMode === 'generate' ? ['Generating image', 'Optimizing image'] : []),
                                'Preparing review',
                            ]}
                        />
                    )}
                    {isWorking(proposal) ? (
                        <LiveTiming
                            from={proposal.startedAt || proposal.createdAt}
                            heartbeatAt={proposal.heartbeatAt}
                            waiting={
                                proposal.status === 'queued'
                                    ? selectedProvider(connection, proposal.provider).ready
                                        ? 'Helper connected; waiting to start'
                                        : 'Helper unavailable'
                                    : undefined
                            }
                        />
                    ) : (
                        generated
                            .filter((i) => ['queued', 'running'].includes(i.status))
                            .map((i) =>
                                i.status === 'queued' ? (
                                    <p key={i.assetId} className="text-xs text-muted">
                                        {imageConnection.ready
                                            ? 'Image helper connected; waiting to start'
                                            : 'Image helper unavailable: ' + imageConnection.message}
                                    </p>
                                ) : (
                                    <LiveTiming key={i.assetId} from={i.startedAt ?? proposal.createdAt} heartbeatAt={i.heartbeatAt} />
                                ),
                            )
                    )}
                    {props.permissions.edit && isWorking(proposal) && (
                        <button type="button" disabled={busy} className={buttonClass('secondary', 'sm')} onClick={() => void discard()}>
                            Cancel
                        </button>
                    )}
                </div>
            )}

            {view === 'error' && (
                <div role="alert" className="space-y-3">
                    {failedUnit && proposal.resumable ? (
                        <p className="text-ui text-muted">
                            {proposal.cachedStages ? 'The finished steps are kept; Retry prepares only this part again.' : 'Retry prepares it again.'}
                        </p>
                    ) : (
                        <p className="line-clamp-4 text-ui text-muted">{proposal.error}</p>
                    )}
                    {props.permissions.edit && (
                        <div className="flex flex-wrap gap-2">
                            {parent && proposal.revision ? (
                                <button
                                    type="button"
                                    className={buttonClass('secondary', 'sm')}
                                    disabled={busy || layoutPending}
                                    onClick={() => retryRevision(proposal, parent, proposal.errorCode === 'SCOPE_EXCEEDED')}
                                >
                                    {proposal.errorCode === 'SCOPE_EXCEEDED' && proposal.revision.scope !== 'website' ? 'Try a wider change' : 'Try again'}
                                </button>
                            ) : proposal.resumable ? (
                                <button
                                    type="button"
                                    className={buttonClass('secondary', 'sm')}
                                    disabled={busy || layoutPending}
                                    title={proposal.cachedStages ? `${proposal.cachedStages} finished step(s) are reused.` : undefined}
                                    onClick={() => void resume(proposal.id)}
                                >
                                    {failedUnit ? `Retry ${failedUnit}` : 'Retry'}
                                </button>
                            ) : null}
                        </div>
                    )}
                    {(proposal.issues?.length > 0 || proposal.candidateSaved || (failedUnit && proposal.resumable)) && (
                        <Disclosure summary="Details">
                            {failedUnit && proposal.resumable && <p>{proposal.error}</p>}
                            {proposal.issues?.length > 0 && (
                                <ul className="list-disc space-y-0.5 pl-4">
                                    {proposal.issues.map((issue, i) => (
                                        <li key={i}>{issue.message}</li>
                                    ))}
                                </ul>
                            )}
                            {proposal.candidateSaved && <p>The rejected result is kept privately for diagnosis. Nothing was applied.</p>}
                        </Disclosure>
                    )}
                </div>
            )}

            {(view === 'ready' || view === 'applied') && result && (
                <div className="space-y-3">
                    {outcome.length > 0 && (
                        <ul className="space-y-0.5 text-ui">
                            {outcome.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    )}
                    <ComponentBadges blocks={proposal.blocks ?? []} />
                    {proposal.conflict && view === 'ready' && (
                        <p className="flex items-start gap-2 text-ui text-changed">
                            <Icon name="alert" className="mt-0.5 size-4 shrink-0" />
                            <span>{proposal.conflict} It can’t be applied; request the change again to start from the latest version.</span>
                        </p>
                    )}
                    {(proposal.requirements ?? [])
                        .filter((r) => ['failed', 'missing'].includes(r.status))
                        .map((req) => (
                            <p key={req.key} className="flex items-start gap-2 text-ui text-danger">
                                <Icon name="alert" className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    {req.label}: not met
                                    {req.detail && <span className="block text-xs text-muted">{req.detail}</span>}
                                </span>
                            </p>
                        ))}
                    {view === 'ready' &&
                        props.permissions.edit &&
                        generated
                            .filter((i) => ['failed', 'missing'].includes(i.status))
                            .map((img) => (
                                <p key={img.assetId} className="flex flex-wrap items-center gap-2 text-xs text-muted">
                                    <span className="min-w-0 flex-1" title={img.error ?? undefined}>
                                        {img.title}: {img.error ?? 'not generated'}
                                    </span>
                                    <button
                                        type="button"
                                        className={buttonClass('secondary', 'sm')}
                                        disabled={busy || !imageConnection.ready}
                                        onClick={() => void retryImage(img.assetId)}
                                    >
                                        Retry image only
                                    </button>
                                </p>
                            ))}
                    {view === 'ready' && sharedImpacts.length > 0 && (
                        <div role="group" aria-label="Also changes" className="space-y-1.5 border-l-2 border-changed pl-3 text-ui">
                            <p className="font-medium">Also changes other pages</p>
                            <ul className="text-muted">
                                {sharedImpacts.map((i) => (
                                    <li key={i.id}>
                                        {i.name.charAt(0).toUpperCase() + i.name.slice(1)}, also shown on {i.otherPages.join(', ')}
                                    </li>
                                ))}
                            </ul>
                            {proposal.parentId && props.permissions.edit && (
                                <label className="ui-check">
                                    <input type="checkbox" checked={confirmShared} onChange={(e) => setConfirmShared(e.target.checked)} />I understand these
                                    pages change too
                                </label>
                            )}
                        </div>
                    )}
                    {view === 'ready' && props.permissions.edit && (
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                disabled={
                                    busy ||
                                    !!proposal.conflict ||
                                    missingImages.length > 0 ||
                                    (!!proposal.parentId && sharedImpacts.length > 0 && !confirmShared)
                                }
                                className={buttonClass('primary')}
                                title="Saves the changes as drafts. Nothing goes live until you publish."
                                onClick={() => void apply()}
                            >
                                Apply proposal
                            </button>
                            {failedImages.length > 0 && missingImages.length === failedImages.length && !proposal.conflict && (
                                <button type="button" disabled={busy} className={buttonClass('secondary')} onClick={() => void apply(true)}>
                                    Apply without image
                                </button>
                            )}
                            <button type="button" disabled={busy} className={buttonClass('ghost')} onClick={() => void discard()}>
                                Discard
                            </button>
                        </div>
                    )}
                    {view === 'applied' && (
                        <p className="t-meta">
                            {proposal.applied?.withoutImages?.length ? 'Applied without the image that was not generated. ' : ''}
                            Publish from the toolbar when you’re ready.
                        </p>
                    )}
                    {changeCount > 0 && (
                        <Disclosure summary={`${changeCount} change${changeCount === 1 ? '' : 's'}`}>
                            <div aria-label={result.revisionChanges ? 'Changes in this revision' : 'Changes'} role="region" className="space-y-2">
                                {changeLists.map((c) => (
                                    <div key={c.page}>
                                        <p className="text-fg">{c.page}</p>
                                        <ul className="mt-0.5 list-disc space-y-0.5 pl-4">
                                            {c.changes.map((x, i) => (
                                                <li key={i}>{x}</li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                                {generated.some((i) => i.url) && (
                                    <div className="flex flex-wrap gap-2" aria-label="Generated images" role="list">
                                        {generated
                                            .filter((i) => i.url)
                                            .map((img) => (
                                                <a key={img.assetId} role="listitem" href="/admin/media" title={img.title} className="block">
                                                    <img src={img.url!} alt={img.title} className="h-12 w-18 rounded-sm border border-line object-cover" />
                                                </a>
                                            ))}
                                    </div>
                                )}
                                {result.tokenChanges.length > 0 && <p>Branding: {result.tokenChanges.map((c) => `${c.token} ${c.value}`).join(' · ')}</p>}
                                {result.notes.map((n, i) => (
                                    <p key={i}>{n}</p>
                                ))}
                                {library.length > 0 && <p>Library images used: {library.map((i) => i.title).join(', ')}</p>}
                                {result.menu && result.includeLayout !== false && <p>Includes the shared header, navigation and footer.</p>}
                                {result.form && (
                                    <p>
                                        Form: {result.form.definition.name} ({result.form.definition.fields.length} fields)
                                    </p>
                                )}
                            </div>
                        </Disclosure>
                    )}
                </div>
            )}

            {devDetails && (
                <div className="space-y-2 rounded-md bg-sunken p-3 text-xs text-muted">
                    <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 t-num">
                        <dt>Provider</dt>
                        <dd className="text-fg">{providerName(proposal.provider)}</dd>
                        <dt>Request</dt>
                        <dd className="truncate font-mono">{proposal.id}</dd>
                        <dt>Created</dt>
                        <dd>{new Date(proposal.createdAt).toLocaleString()}</dd>
                        {proposal.revision && (
                            <>
                                <dt>Revises</dt>
                                <dd className="truncate">
                                    {parent ? requestTitle(parent) : 'an earlier proposal'} · {SCOPE_LABEL[proposal.revision.scope]}: {proposal.revision.label}
                                </dd>
                            </>
                        )}
                        {proposal.diagnostics && (
                            <>
                                <dt>Run</dt>
                                <dd>
                                    {proposal.diagnostics.mode} · {proposal.diagnostics.seconds}s · {proposal.diagnostics.code ?? proposal.diagnostics.outcome}
                                </dd>
                            </>
                        )}
                        {proposal.errorCode && (
                            <>
                                <dt>Error code</dt>
                                <dd className="font-mono">{proposal.errorCode}</dd>
                            </>
                        )}
                    </dl>
                    {proposal.diagnostics && proposal.diagnostics.stages.length > 0 && <StageList stages={proposal.diagnostics.stages} />}
                </div>
            )}
        </section>
    );

    const ready = effectiveConnection.ready;
    const panel = (
        <aside
            aria-label="AI assistant"
            className={`ak-workspace-dark ak-ai-panel flex min-h-0 flex-col border-r border-nav-edge bg-surface text-fg max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:w-[min(23rem,100vw)] max-lg:shadow-pop lg:w-[20rem] lg:shrink-0 xl:w-[21.5rem] 2xl:w-[23rem] ${drawer ? '' : 'max-lg:hidden'} ${panelOpen ? '' : 'lg:hidden'}`}
        >
            <h1 className="sr-only">Build a website</h1>
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-line pr-2">
                <PanelTabs<Tab>
                    id={ids}
                    value={tab}
                    onChange={(t) => {
                        setTab(t);
                        if (t === 'ai') requestAnimationFrame(() => promptRef.current?.focus());
                    }}
                    tabs={[
                        { value: 'ai', label: 'AI', icon: 'sparkle' },
                        { value: 'history', label: 'History', icon: 'history', count: requests.length },
                    ]}
                />
                <div className="flex shrink-0 items-center">
                    {props.permissions.edit && canRevise && !fresh && (
                        <button type="button" className={buttonClass('ghost', 'sm', 'px-2')} title="Start a new website request" onClick={startNew}>
                            <Icon name="plus" className="size-3.5" />
                            New
                        </button>
                    )}
                    <button
                        type="button"
                        className="hidden size-7 place-items-center rounded-md text-muted hover:bg-hover hover:text-fg lg:grid"
                        aria-label="Hide AI panel"
                        title="Hide AI panel"
                        onClick={() => setPanelOpen(false)}
                    >
                        <Icon name="chevronLeft" className="size-4" />
                    </button>
                    <button
                        type="button"
                        className="grid size-7 place-items-center rounded-md text-muted hover:bg-hover hover:text-fg lg:hidden"
                        aria-label="Close AI panel"
                        onClick={() => setDrawer(false)}
                    >
                        <Icon name="close" className="size-4" />
                    </button>
                </div>
            </div>
            <div
                id={`${ids}-panel-ai`}
                role="tabpanel"
                aria-labelledby={`${ids}-tab-ai`}
                hidden={tab !== 'ai'}
                className="ak-scroll ak-scroll-dark min-h-0 flex-1 overflow-y-auto"
            >
                <p className="ak-band-x flex items-center gap-2 border-b border-line py-2.5 text-xs text-muted" data-testid="ai-status">
                    <span
                        aria-hidden="true"
                        className={`size-1.5 shrink-0 rounded-full ring-3 ${!ready ? 'bg-draft ring-draft/15' : layoutPending ? 'animate-pulse bg-ai ring-ai-soft' : 'bg-live ring-live-soft'}`}
                    />
                    {ready ? `${effectiveConnection.providerName ?? 'AI'} ${layoutPending ? 'working' : 'ready'}` : effectiveConnection.message}
                    {!ready && (
                        <Link className="ui-link" href="/admin/settings/ai-connections">
                            {readyProviders.length ? 'AI Connections' : 'Connect an AI'}
                        </Link>
                    )}
                </p>
                {composer}
                {current}
            </div>
            <div
                id={`${ids}-panel-history`}
                role="tabpanel"
                aria-labelledby={`${ids}-tab-history`}
                hidden={tab !== 'history'}
                className="ak-scroll ak-scroll-dark min-h-0 flex-1 overflow-y-auto p-2"
            >
                <HistoryList requests={requests} selected={selected} onSelect={select} disabled={busy} />
            </div>
        </aside>
    );

    const working = proposal && (isWorking(proposal) || imagesPending(proposal));
    const toolbar = (
        <header className="ak-workspace-dark flex min-h-12 shrink-0 flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line bg-surface px-3 py-1.5 text-fg">
            <div className="flex min-w-fit flex-1 items-center gap-2">
                {!fullscreen && (
                    <button
                        type="button"
                        className={`size-8 shrink-0 place-items-center rounded-md text-muted hover:bg-hover hover:text-fg ${panelOpen ? 'grid lg:hidden' : 'grid'}`}
                        aria-label="Show AI panel"
                        title="Show AI panel"
                        onClick={() => {
                            setPanelOpen(true);
                            setDrawer(true);
                        }}
                    >
                        <Icon name="sidebar" className="size-4" />
                    </button>
                )}
                {pages.length > 1 ? (
                    <select
                        aria-label="Page"
                        value={pageIndex}
                        onChange={(e) => setIndex(Number(e.target.value))}
                        className="ui-input h-8 w-auto max-w-56 shrink-0 py-0 pr-7 pl-2.5 text-xs font-medium"
                    >
                        {pages.map((p, i) => (
                            <option key={p.id} value={i}>
                                {p.title}
                            </option>
                        ))}
                    </select>
                ) : (
                    page && <span className="truncate text-ui font-medium">{page.title}</span>
                )}
                {page && (
                    <span className={`hidden min-w-0 truncate font-mono text-xs text-muted ${pages.length > 1 ? '2xl:inline' : 'sm:inline'}`}>{page.path}</span>
                )}
                {shown && !proposal?.applied && (
                    <StatusPill tone={shown !== proposal ? 'neutral' : shown.status === 'applied' ? 'live' : 'ai'} className="shrink-0">
                        {shown !== proposal ? 'Starting point' : shown.status === 'applied' ? 'Draft' : 'Proposal'}
                    </StatusPill>
                )}
            </div>
            <div className="flex items-center gap-2">
                {compareAvailable && (
                    <div role="group" aria-label="Compare" className="inline-flex h-8 items-center rounded-md border border-line bg-sunken p-0.5">
                        {[
                            [true, 'Before'],
                            [false, 'After'],
                        ].map(([value, label]) => (
                            <button
                                key={String(label)}
                                type="button"
                                aria-pressed={before === value}
                                onClick={() => {
                                    setBefore(value as boolean);
                                    if (value) setBaselineOpened(true);
                                }}
                                className={`inline-flex h-full items-center rounded px-2 text-xs font-medium transition-colors ${before === value ? 'bg-surface text-fg shadow-raise' : 'text-muted hover:text-fg'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                )}
                <DeviceSwitch value={device} onChange={setDevice} />
                <span className="max-sm:hidden">
                    <ZoomSelect value={zoom} onChange={setZoom} />
                </span>
            </div>
            <div className="flex flex-wrap items-center justify-end gap-1.5">
                {appliedPage && props.permissions.edit && (
                    <Link href={`/admin/editor/${appliedPage.id}`} className={buttonClass('ghost')} title={`Edit ${appliedPage.title} in the builder`}>
                        Edit page
                    </Link>
                )}
                {proposal?.applied && (
                    <button
                        type="button"
                        disabled={busy || publishing}
                        className={buttonClass(props.permissions.publish ? 'primary' : 'secondary')}
                        onClick={() => void readiness()}
                    >
                        {props.permissions.publish ? 'Publish website' : 'Check readiness'}
                    </button>
                )}
                {fullscreen ? (
                    <button type="button" className={buttonClass('secondary')} aria-pressed onClick={() => setFullscreen(false)} title="Esc">
                        <Icon name="shrink" />
                        Exit full screen
                    </button>
                ) : (
                    <button
                        type="button"
                        className="grid size-8 place-items-center rounded-md text-muted hover:bg-hover hover:text-fg disabled:opacity-45"
                        aria-label="Full screen preview"
                        title="Full screen preview"
                        disabled={!shown}
                        onClick={() => setFullscreen(true)}
                    >
                        <Icon name="expand" className="size-4" />
                    </button>
                )}
            </div>
        </header>
    );
    const canvas = shown ? (
        <PreviewStage
            src={previewSrc}
            title={`${shown === proposal ? 'Proposed' : 'Starting point of'} ${page?.title ?? 'page'}`}
            baselineSrc={compareAvailable && baselineOpened ? `${previewBase}?baseline=1` : null}
            baselineTitle={`Starting point of ${page?.title ?? 'page'}`}
            showBaseline={compareAvailable && before}
            device={device}
            zoom={zoom}
        />
    ) : (
        <div className="grid h-full place-items-center bg-canvas p-6" data-testid="preview-stage">
            {working ? (
                <div className="w-full max-w-3xl space-y-4 rounded-md bg-surface p-8 shadow-raise ring-1 ring-line" aria-hidden="true">
                    <div className="flex items-center justify-between">
                        <Skeleton className="h-4 w-24" />
                        <div className="flex gap-3">
                            <Skeleton className="h-3 w-12" />
                            <Skeleton className="h-3 w-12" />
                            <Skeleton className="h-3 w-12" />
                        </div>
                    </div>
                    <Skeleton className="h-40 w-full" />
                    <div className="grid grid-cols-3 gap-3">
                        <Skeleton className="h-20" />
                        <Skeleton className="h-20" />
                        <Skeleton className="h-20" />
                    </div>
                    <p className="flex items-center justify-center gap-2 pt-2 text-ui text-muted">
                        <Icon name="sparkle" className="size-4 text-ai" />
                        Your website is being prepared…
                    </p>
                </div>
            ) : (
                <div className="max-w-sm text-center">
                    <Icon name="globe" className="mx-auto size-6 text-faint" />
                    <p className="mt-3 t-title">Your website appears here</p>
                    <p className="mt-1 t-meta">Describe it in the AI panel. You review the proposal here at full size before anything is applied.</p>
                </div>
            )}
        </div>
    );

    return (
        <AdminLayout chrome="dark">
            <Head title="Build a website" />
            <div className="flex h-[calc(100dvh-7.5rem)] min-h-0 lg:h-[calc(100dvh-4rem)]">
                {panel}
                {drawer && (
                    <button type="button" aria-label="Close AI panel" className="fixed inset-0 z-30 bg-scrim lg:hidden" onClick={() => setDrawer(false)} />
                )}
                <section aria-label="Website preview" className={`flex min-w-0 flex-1 flex-col bg-canvas ${fullscreen ? 'fixed inset-0 z-50' : ''}`}>
                    {toolbar}
                    <div className="min-h-0 flex-1">{canvas}</div>
                </section>
            </div>
            <PublishDialog
                open={publishing}
                check={check}
                canPublish={props.permissions.publish}
                onRecheck={() => void readiness()}
                onPublish={publish}
                onClose={() => setPublishing(false)}
            />
        </AdminLayout>
    );
}
