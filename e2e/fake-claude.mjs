// A stand-in for the Claude Code CLI in the browser tests: the helper (php artisan arkon:ai-helper)
// runs it exactly as it would run claude.exe (ARKON_CLAUDE_COMMAND). No network, no login, no
// subscription usage. Replies are chosen by words in the request (read from stdin):
//   "landscaping"          → a homepage proposal (hero, services, about, contact button)
//   "Shorten the headline" → a follow-up: shorter hero heading + a services intro
//   "MOCK-INVALID"         → a block type that does not exist (also on the repair run, so it fails)
//   "MOCK-LIMIT"           → Claude Code's subscription usage-limit error
//   "MOCK-SLOW"            → waits 5 s, then answers like "landscaping"
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
        reply({ summary: 'A carousel.', notes: [], changes: [{ action: 'add', parent: 'page', index: null, block: { type: 'carousel', props: {} } }] });
    }
    if (request.includes('MOCK-SLOW')) await new Promise((done) => setTimeout(done, 5000));

    const text = (value, element = 'p') => ({ type: 'text', props: { text: value, element, align: 'start' } });
    const service = (name, about) => ({ type: 'column', props: {}, children: [text(name, 'h3'), text(about)] });
    if (request.includes('Shorten the headline')) {
        reply({
            summary: 'Shortened the headline and added a short services introduction.',
            notes: [],
            changes: [
                {
                    action: 'update',
                    change: { id: hero.id, type: 'hero', props: { heading: 'Gardens that grow', headingLevel: null, text: null, image: null } },
                },
                { action: 'add', parent: 'page', index: 2, block: text('From first sketch to seasonal care, one team does it all.') },
            ],
        });
    }
    if (request.includes('landscaping') || request.includes('MOCK-SLOW')) {
        reply({
            summary: 'A homepage for a landscaping business with a hero, three services, an about section and a contact button.',
            notes: ['Set where the Contact us button links to before publishing.'],
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
                        },
                    },
                },
                { action: 'add', parent: 'page', index: null, block: text('Our services', 'h2') },
                {
                    action: 'add',
                    parent: 'page',
                    index: null,
                    block: {
                        type: 'columns',
                        props: { stackOn: 'mobile', gap: 'medium' },
                        children: [
                            service('Garden design', 'Plans that fit your space and how you use it.'),
                            service('Planting', 'Trees, shrubs and borders chosen for your soil.'),
                            service('Lawn care', 'Mowing, feeding and repair through the seasons.'),
                        ],
                    },
                },
                { action: 'add', parent: 'page', index: null, block: text('About us', 'h2') },
                { action: 'add', parent: 'page', index: null, block: text('We are a local team that looks after gardens of every size.') },
                {
                    action: 'add',
                    parent: 'page',
                    index: null,
                    block: { type: 'button', props: { label: 'Contact us', href: '', style: 'primary', newTab: false } },
                },
            ],
        });
    }
    reply({ summary: 'Nothing on this page can do that.', notes: ['Arkon has no block for this yet.'], changes: [] });
});
