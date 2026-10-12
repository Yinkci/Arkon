import { expect, test, type Page } from '@playwright/test';

/**
 * "Request changes" and generated images in the Build a website screen, with the server mocked
 * (no helper, no model, no image generation). The PHP feature tests cover the server side.
 */
const PARENT = '01a00000-0000-7000-8000-0000000000a1';
const REVISION = '01a00000-0000-7000-8000-0000000000a2';
const PAGE = '01a00000-0000-7000-8000-0000000000b1';
const IMAGE = '01a00000-0000-7000-8000-0000000000c1';

const text = {
    ready: true,
    selectionState: 'automatic',
    provider: 'claude-code',
    providerName: 'Claude Code',
    message: 'Using Claude Code.',
    providers: [{ id: 'claude-code', name: 'Claude Code', ready: true }],
};
const images = {
    ready: true,
    selectionState: 'automatic',
    provider: 'codex',
    providerName: 'Codex',
    message: 'Using Codex. Codex 0.160.0 image generation with your ChatGPT account.',
    providers: [
        { id: 'claude-code', name: 'Claude Code', ready: false, message: 'Claude Code cannot generate images.' },
        { id: 'codex', name: 'Codex', ready: true, message: 'Codex 0.160.0 image generation with your ChatGPT account.' },
    ],
};
const base = {
    provider: 'claude-code',
    error: null,
    errorCode: null,
    issues: [],
    activity: null,
    createdAt: new Date(Date.now() - 90000).toISOString(),
    startedAt: new Date(Date.now() - 90000).toISOString(),
    heartbeatAt: new Date().toISOString(),
    resolvedAt: null,
    candidateSaved: false,
    conflict: null,
    applied: null,
};
const result = {
    includeLayout: false,
    pages: [{ id: PAGE, title: 'Contact', path: '/contact', existing: false, changes: ['Add Hero “Contact us”', 'Add Form'] }],
    notes: [],
    tokenChanges: [],
    menu: null,
    shared: {},
    form: { id: 'f', definition: { name: 'Contact enquiry', fields: [1, 2, 3, 4, 5] } },
};
const sections = [
    [
        { id: 'heroNode01', type: 'hero', label: 'Hero “Contact us”' },
        { id: 'formNode01', type: 'form', label: 'Form' },
    ],
];
function parent(imageStatus: 'ready' | 'failed') {
    return {
        ...base,
        id: PARENT,
        prompt: 'Build a contact page… generated professional background photograph featuring Black African adults.',
        status: 'proposed',
        summary: 'A contact page with a hero and an enquiry form',
        parentId: null,
        revision: null,
        imageMode: 'generate',
        result,
        sections,
        requirements: [
            imageStatus === 'ready'
                ? {
                      key: 'generated-image',
                      label: 'Generated image requested',
                      status: 'fulfilled',
                      detail: 'Generated and imported into the media library (private until a page using it is published).',
                  }
                : {
                      key: 'generated-image',
                      label: 'Generated image requested',
                      status: 'failed',
                      detail: 'Image generation did not complete. The layout is kept; retry the image or apply without it.',
                  },
        ],
        images: [
            {
                assetId: IMAGE,
                kind: 'generated',
                status: imageStatus,
                activity: null,
                error: imageStatus === 'failed' ? 'Your Codex image generation limit has been reached. Try again after it resets.' : null,
                errorCode: imageStatus === 'failed' ? 'PROVIDER_LIMIT_REACHED' : null,
                title: 'Contact hero background',
                url: null,
                usedOn: ['Contact'],
                ownJob: true,
                heartbeatAt: null,
                startedAt: null,
                spec: { subject: 'Two professional Black African adults at a reception desk', placement: 'background', aspectRatio: '16:9' },
            },
        ],
    };
}
const revision = {
    ...base,
    id: REVISION,
    prompt: 'Replace only the hero background with another generated image. Keep the form, text and other sections unchanged.',
    status: 'running',
    activity: 'generating',
    summary: null,
    parentId: PARENT,
    revision: { scope: 'section', pageId: PAGE, nodeId: 'heroNode01', label: 'Hero “Contact us” on Contact (/contact)', baselineKind: 'proposal' },
    imageMode: 'generate',
    result: null,
    sections: [],
    requirements: [],
    images: [],
};

/** The first render uses the real (empty) page props; the screen's own 10 s refresh then loads the mocked state. */
async function open(page: Page) {
    await page.goto('/admin/website');
    await expect(page.getByRole('heading', { name: 'Build a website', level: 1 })).toBeVisible();
    await page.clock.runFor(10_500);
}

async function mock(page: Page, state: { requests: object[]; posted?: Record<string, unknown> }) {
    await page.clock.install();
    await page.route('**/admin/website/*/preview/**', (route) =>
        route.fulfill({
            contentType: 'text/html',
            body: '<!doctype html><title>Contact</title><main><section><h1>Contact us</h1></section><form><label>First name<input></label></form></main>',
        }),
    );
    await page.route('**/website/requests', async (route) => {
        if (route.request().method() === 'POST') {
            state.posted = route.request().postDataJSON();
            state.requests = [revision, ...state.requests];
            await route.fulfill({ json: { ok: true, data: revision } });
            return;
        }
        await route.fulfill({ json: { ok: true, data: { requests: state.requests, connection: text, imageConnection: images } } });
    });
}

test('request changes targets the selected hero, asks about image generation and names the separate image connection', async ({ page }) => {
    const state: { requests: object[]; posted?: Record<string, unknown> } = { requests: [parent('ready')] };
    await mock(page, state);
    await open(page);
    const current = page.getByRole('region', { name: 'Current proposal' });
    await expect(current.getByText('A contact page with a hero and an enquiry form', { exact: true })).toBeVisible();
    await expect(current.getByRole('heading', { name: 'Proposal ready' })).toBeVisible();
    await expect(current).toContainText('Contact created');
    await expect(current).toContainText('1 image generated');
    await expect(page.frameLocator('iframe').getByRole('heading', { name: 'Contact us' })).toBeVisible();

    // A proposal is selected, so the composer requests changes to it; where they apply is an advanced option.
    const panel = page.getByRole('region', { name: 'Request changes' });
    await expect(panel.getByRole('radio', { name: 'This page only' })).toBeHidden();
    await panel.getByRole('button', { name: 'Advanced options' }).click();
    await expect(panel.getByRole('radio', { name: 'This page only' })).toBeChecked();
    await panel.getByRole('radio', { name: 'One section' }).check();
    await expect(panel.getByRole('combobox', { name: 'Section' })).toHaveValue('heroNode01');
    // A request for a new image is understood, with a one-line confirmation instead of a question.
    await panel
        .getByLabel('Describe a change')
        .fill('Replace only the hero background with another generated image. Keep the form, text and other sections unchanged.');
    await expect(panel.getByText('A new image will be generated with Codex.')).toBeVisible();
    await page.screenshot({ path: 'storage/screenshots/website/request-changes.png' });
    await panel.getByRole('button', { name: 'Request changes' }).click();

    await expect.poll(() => state.posted).toBeTruthy();
    expect(state.posted).toMatchObject({
        parentId: PARENT,
        target: { scope: 'section', pageId: PAGE, nodeId: 'heroNode01' },
        images: 'generate',
        imageProvider: 'codex',
        imageSelectionMode: 'automatic',
        provider: 'claude-code',
    });
    expect(String(state.posted?.prompt)).not.toContain('Build a contact page');
    await expect(page.getByRole('heading', { name: 'Preparing your proposal…' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Request changes' })).toHaveCount(0);
    await expect(page.getByRole('list', { name: 'Stages' })).toContainText('Generating image');
    await expect(page.locator('[aria-label="Stages"] li[aria-current="step"]')).toHaveText(/Building the proposal/);
    // While the revision is prepared, the canvas keeps showing its starting point.
    await expect(page.getByRole('region', { name: 'Website preview' })).toContainText('Starting point');
});

test('a failed generated image keeps the layout and offers only an image retry or applying without it', async ({ page }) => {
    const state: { requests: object[] } = { requests: [parent('failed')] };
    await mock(page, state);
    let retried: unknown = null;
    await page.route(`**/website/images/${IMAGE}/retry`, async (route) => {
        retried = route.request().postDataJSON();
        await route.fulfill({ json: { ok: true, data: { status: 'queued' } } });
    });
    await open(page);
    await expect(page.getByRole('region', { name: 'Current proposal' })).toContainText('Generated image requested: not met');
    await expect(page.getByRole('region', { name: 'Current proposal' })).toContainText('limit has been reached');
    await expect(page.getByRole('button', { name: 'Apply proposal' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Apply without image' })).toBeVisible();
    await page.screenshot({ path: 'storage/screenshots/website/image-failed.png' });
    await page.getByRole('button', { name: 'Retry image only' }).click();
    await expect.poll(() => retried).toMatchObject({ requestKey: expect.any(String) });
    await expect(page.getByText('Only the image will be generated again. The layout is kept.')).toBeVisible();
});

test('a failed revision can be prepared again on its parent with the same request, target and image choice', async ({ page }) => {
    const failed = {
        ...revision,
        status: 'failed',
        activity: 'failed',
        errorCode: 'EXECUTION_FAILED',
        error: 'Codex could not finish this request.',
        resolvedAt: new Date().toISOString(),
    };
    const state: { requests: object[]; posted?: Record<string, unknown> } = { requests: [failed, parent('ready')] };
    await mock(page, state);
    await open(page);
    await expect(page.getByRole('alert').filter({ hasText: 'Codex could not finish this request.' })).toBeVisible();
    // History lists both, the failed revision under its parent.
    await page.getByRole('tab', { name: /History/ }).click();
    const history = page.getByRole('list', { name: 'Website requests' });
    await expect(history.getByRole('button')).toHaveCount(2);
    await expect(history).toContainText('Failed');
    await expect(history.getByRole('listitem').first()).toContainText('Today');
    await page.getByRole('tab', { name: 'AI' }).click();
    await page.getByRole('button', { name: 'Try again' }).click();
    const panel = page.getByRole('region', { name: 'Request changes' });
    await expect(panel.getByLabel('Describe a change')).toHaveValue(revision.prompt);
    await expect(panel.getByText('A new image will be generated with Codex.')).toBeVisible();
    await panel.getByRole('button', { name: 'Advanced options' }).click();
    await expect(panel.getByRole('radio', { name: 'One section' })).toBeChecked();
    await expect(panel.getByRole('combobox', { name: 'Section' })).toHaveValue('heroNode01');
    expect(state.posted).toBeUndefined();
    await panel.getByRole('button', { name: 'Request changes' }).click();
    await expect.poll(() => state.posted).toMatchObject({ parentId: PARENT, target: { scope: 'section', nodeId: 'heroNode01' }, images: 'generate' });
});

test('a revision that changes a form other pages show lists them and needs confirmation before applying', async ({ page }) => {
    const shared = {
        ...parent('ready'),
        id: REVISION,
        parentId: PARENT,
        summary: 'First and last name side by side; button Submit Enquiry',
        revision: { scope: 'page', pageId: PAGE, nodeId: null, label: 'Contact (/contact)', baselineKind: 'proposal' },
        requirements: [],
        images: [],
        impacts: [{ kind: 'form', id: 'f', name: 'the form “Contact enquiry”', otherPages: ['/quote'] }],
        result: { ...result, revisionChanges: [{ page: 'Form “Contact enquiry”', changes: ['Submit button: Send message → Submit Enquiry'] }] },
    };
    const state: { requests: object[] } = { requests: [shared, parent('ready')] };
    await mock(page, state);
    let applied: unknown = null;
    await page.route(`**/website/${REVISION}/apply`, async (route) => {
        applied = route.request().postDataJSON();
        await route.fulfill({ json: { ok: true, data: { pages: [] } } });
    });
    await open(page);
    const also = page.getByRole('group', { name: 'Also changes', exact: true });
    await expect(also).toContainText('The form “Contact enquiry”, also shown on /quote');
    await page.getByText('1 change', { exact: true }).click();
    await expect(page.getByRole('region', { name: 'Changes in this revision' })).toContainText('Submit button: Send message → Submit Enquiry');
    // A revision compares with its starting point without leaving the canvas.
    const compare = page.getByRole('group', { name: 'Compare' });
    await compare.getByRole('button', { name: 'Before' }).click();
    await expect(page.locator('iframe[title^="Starting point"]')).toBeVisible();
    await compare.getByRole('button', { name: 'After' }).click();
    await expect(page.locator('iframe[title^="Proposed"]')).toBeVisible();
    const applyButton = page.getByRole('button', { name: 'Apply proposal' });
    await expect(applyButton).toBeDisabled();
    await also.getByRole('checkbox', { name: /I understand these pages change too/ }).check();
    await expect(applyButton).toBeEnabled();
    await applyButton.click();
    await expect.poll(() => applied).toMatchObject({ confirmShared: true });
});
