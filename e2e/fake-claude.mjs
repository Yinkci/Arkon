// A stand-in for the Claude Code CLI in the browser tests: the helper (php artisan arkon:ai-helper)
// runs it exactly as it would run claude.exe (ARKON_CLAUDE_COMMAND). No network, no login, no
// subscription usage. Replies are chosen by words in the request (read from stdin):
//   "landscaping"          → a homepage proposal (hero, services, about, contact button)
//   "Shorten the headline" → a follow-up: shorter hero heading + a services intro
//   "MOCK-INVALID"         → a block type that does not exist (also on the repair run, so it fails)
//   "MOCK-LIMIT"           → Claude Code's subscription usage-limit error
//   "MOCK-SLOW"            → waits 5 s, then answers like "landscaping"
//   "image on the right"   → the hero layout of the design acceptance case (design settings only)
//   "MOCK-TOKENS"          → a site-wide token change (teal brand colour), no page changes
//   "MOCK-BUILDER"         → duplicates the hero's button, adds five equal columns and a fade-up entrance
//   anything else          → no changes, with a note
// Every -p run appends what it received (flags, environment variable names) to storage/e2e/fake-claude.log.
import { appendFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
const here = dirname(fileURLToPath(import.meta.url));
const log = resolve(here, '../storage/e2e/fake-claude.log');

if (args[0] === '--version') {
    console.log('2.1.292 (Claude Code)');
    process.exit(0);
}
if (args[0] === 'auth' && args[1] === 'status') {
    console.log(JSON.stringify({ loggedIn: true, authMethod: 'claude.ai', apiProvider: 'firstParty', subscriptionType: 'pro' }));
    process.exit(0);
}

let stdin = '';
process.stdin.on('data', (chunk) => (stdin += chunk));
process.stdin.on('end', async () => {
    mkdirSync(dirname(log), { recursive: true });
    appendFileSync(
        log,
        JSON.stringify({ args: args.filter((a) => !a.startsWith('{')), env: Object.keys(process.env), cwd: process.cwd(), stdinBytes: stdin.length }) + '\n',
    );

    const request = stdin.split('Request:\n')[1]?.split('\n\nYour previous proposal')[0] ?? '';
    const blocks = JSON.parse(/<page>([\s\S]*?)<\/page>/.exec(stdin)?.[1] ?? '[]');
    const hero = blocks.find((block) => block.type === 'hero');
    const reply = (output) => {
        console.log(
            JSON.stringify({ type: 'result', subtype: 'success', is_error: false, num_turns: 2, result: JSON.stringify(output), structured_output: output }),
        );
        process.exit(0);
    };

    if (request.includes('MOCK-LIMIT')) {
        console.log(
            JSON.stringify({ type: 'result', subtype: 'success', is_error: true, result: 'Claude AI usage limit reached|1791331200', api_error_status: 429 }),
        );
        process.exit(1);
    }
    if (request.includes('MOCK-INVALID')) {
        reply({
            summary: 'A carousel.',
            notes: [],
            tokenChanges: [],
            changes: [{ action: 'add', parent: 'page', index: null, ref: null, block: { type: 'carousel', props: {} } }],
        });
    }
    if (request.includes('MOCK-SLOW')) await new Promise((done) => setTimeout(done, 5000));

    const text = (value, element = 'p') => ({ type: 'text', props: { text: value, element, style: [] } });
    const service = (name, about) => ({ type: 'column', props: { style: [] }, children: [text(name, 'h3'), text(about)] });
    const set = (slot, screen, property, value) => ({ slot, screen, property, value });
    if (request.includes('image on the right')) {
        // "Keep the hero text on the left. Put its image on the right, make the image 500px tall with
        // cover cropping, and stack the image below the text on mobile."
        reply({
            summary:
                'The hero keeps its text on the left and shows the image on the right, 500px tall and cropped to fill; on phones the image goes below the text.',
            notes: [],
            tokenChanges: [],
            changes: [
                {
                    action: 'update',
                    change: {
                        id: hero.id,
                        type: 'hero',
                        props: {
                            heading: null,
                            headingLevel: null,
                            text: null,
                            image: null,
                            style: [
                                set('root', 'base', 'direction', 'row'),
                                set('media', 'base', 'height', '500px'),
                                set('media', 'base', 'objectFit', 'cover'),
                                set('root', 'mobile', 'direction', 'column'),
                            ],
                        },
                    },
                },
            ],
        });
    }
    if (request.includes('MOCK-BUILDER')) {
        // "Duplicate the hero's button, add a section with five equal columns, and make it fade up
        // when it scrolls into view": the duplicate, columns and animation capabilities at once.
        const button = (hero?.children ?? []).find((child) => child.type === 'button');
        const columns = [];
        for (let i = 1; i <= 5; i++) {
            columns.push({ action: 'add', parent: 'new:grid', index: null, ref: `col${i}`, block: { type: 'column', props: { style: [] } } });
            columns.push({ action: 'add', parent: `new:col${i}`, index: null, ref: null, block: text(`Step ${i}`) });
        }
        reply({
            summary: 'A second “Call us” button, and a “How it works” section with five equal columns that fades up when it scrolls into view.',
            notes: [],
            tokenChanges: [],
            changes: [
                ...(button ? [{ action: 'duplicate', id: button.id, ref: 'call' }] : []),
                ...(button
                    ? [
                          {
                              action: 'update',
                              change: {
                                  id: 'new:call',
                                  type: 'button',
                                  props: { label: 'Call us', href: null, variant: 'secondary', size: null, newTab: null, style: null },
                              },
                          },
                      ]
                    : []),
                {
                    action: 'add',
                    parent: 'page',
                    index: null,
                    ref: 'work',
                    block: { type: 'section', props: { element: 'section', contentWidth: 'default', style: [] } },
                },
                { action: 'add', parent: 'new:work', index: null, ref: null, block: text('How it works', 'h2') },
                { action: 'add', parent: 'new:work', index: null, ref: 'grid', block: { type: 'columns', props: { style: [] } } },
                ...columns,
                {
                    action: 'update',
                    change: {
                        id: 'new:work',
                        type: 'section',
                        props: {
                            element: null,
                            contentWidth: null,
                            style: [set('root', 'base', 'animation', 'fade-up'), set('root', 'base', 'animationTrigger', 'view')],
                        },
                    },
                },
            ],
        });
    }
    if (request.includes('MOCK-TOKENS')) {
        reply({
            summary: 'Changes the brand colour to teal across the site.',
            notes: [],
            tokenChanges: [{ token: '@color.primary', value: '#0f766e' }],
            changes: [],
        });
    }
    if (request.includes('Shorten the headline')) {
        reply({
            summary: 'Shortened the headline and added a short services introduction.',
            notes: [],
            tokenChanges: [],
            changes: [
                {
                    action: 'update',
                    change: { id: hero.id, type: 'hero', props: { heading: 'Gardens that grow', headingLevel: null, text: null, image: null, style: null } },
                },
                { action: 'add', parent: 'page', index: 2, ref: null, block: text('From first sketch to seasonal care, one team does it all.') },
            ],
        });
    }
    if (request.includes('landscaping') || request.includes('MOCK-SLOW')) {
        reply({
            summary: 'A homepage for a landscaping business with a hero, three services, an about section and a contact button.',
            notes: ['Set where the Contact us button links to before publishing.'],
            tokenChanges: [],
            changes: [
                {
                    action: 'update',
                    change: {
                        id: hero.id,
                        type: 'hero',
                        props: {
                            heading: 'Gardens that grow with you',
                            headingLevel: null,
                            text: 'Garden design, planting and lawn care for homes and businesses.',
                            image: null,
                            style: null,
                        },
                    },
                },
                { action: 'add', parent: 'page', index: null, ref: null, block: text('Our services', 'h2') },
                {
                    action: 'add',
                    parent: 'page',
                    index: null,
                    ref: null,
                    block: {
                        type: 'columns',
                        props: { style: [] },
                        children: [
                            service('Garden design', 'Plans that fit your space and how you use it.'),
                            service('Planting', 'Trees, shrubs and borders chosen for your soil.'),
                            service('Lawn care', 'Mowing, feeding and repair through the seasons.'),
                        ],
                    },
                },
                { action: 'add', parent: 'page', index: null, ref: null, block: text('About us', 'h2') },
                { action: 'add', parent: 'page', index: null, ref: null, block: text('We are a local team that looks after gardens of every size.') },
                {
                    action: 'add',
                    parent: 'page',
                    index: null,
                    ref: null,
                    block: { type: 'button', props: { label: 'Contact us', href: '', variant: 'primary', size: 'medium', newTab: false, style: [] } },
                },
            ],
        });
    }
    reply({ summary: 'Nothing on this page can do that.', notes: ['Arkon has no block for this yet.'], tokenChanges: [], changes: [] });
});
