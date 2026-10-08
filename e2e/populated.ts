// A realistically populated page for the opt-in editor measurements (about 200 blocks).
import { E2E_HOST, PORT } from './env';
import { db } from './support';

/** 12 sections, each a heading, a paragraph and three columns of text and a button: about 200 blocks. */
export async function populatedPage(): Promise<string> {
    const { rows } = await db.query<{ site_id: string }>('select site_id from site_domains where hostname = $1', [`${E2E_HOST}:${PORT}`]);
    const nodes: Record<string, unknown> = {};
    const id = (prefix: string, n: number) => `${prefix}${String(n).padStart(10 - prefix.length, '0')}`;
    const rootChildren: string[] = ['perfHero01'];
    nodes.perfHero01 = {
        id: 'perfHero01',
        type: 'hero',
        version: 3,
        props: { heading: 'A populated page', headingLevel: 'h1', text: 'Measuring the editor.', image: null },
        children: [],
    };
    let n = 0;
    for (let s = 0; s < 12; s++) {
        const section = id('sec', ++n);
        const cols = id('cls', ++n);
        const children = [id('txt', ++n), id('txt', ++n), cols];
        nodes[children[0]!] = { id: children[0], type: 'text', version: 2, props: { text: `Section ${s + 1}`, element: 'h2', style: {} } };
        nodes[children[1]!] = {
            id: children[1],
            type: 'text',
            version: 2,
            props: { text: 'A paragraph of supporting text for this section of the page.', element: 'p', style: {} },
        };
        const columns: string[] = [];
        for (let c = 0; c < 3; c++) {
            const column = id('col', ++n);
            const t = id('txt', ++n);
            const b = id('btn', ++n);
            nodes[t] = { id: t, type: 'text', version: 2, props: { text: `Column ${c + 1} text with a few words.`, element: 'p', style: {} } };
            nodes[b] = {
                id: b,
                type: 'button',
                version: 2,
                props: { label: 'Learn more', href: '/about', variant: 'secondary', size: 'medium', newTab: false, style: {} },
            };
            nodes[column] = { id: column, type: 'column', version: 2, props: { style: {} }, children: [t, b] };
            columns.push(column);
        }
        nodes[cols] = { id: cols, type: 'columns', version: 2, props: { style: { root: { mobile: { columns: '1' } } } }, children: columns };
        nodes[section] = { id: section, type: 'section', version: 1, props: { contentWidth: 'default', element: 'section', style: {} }, children };
        rootChildren.push(section);
    }
    nodes.perfRoot01 = { id: 'perfRoot01', type: 'page', version: 3, props: {}, children: rootChildren };
    const document = { schemaVersion: 1, root: 'perfRoot01', nodes, seo: {} };
    const pageId = crypto.randomUUID();
    await db.query('insert into pages (id, site_id, path, title) values ($1, $2, $3, $4)', [
        pageId,
        rows[0]!.site_id,
        `/editor-perf-${pageId.slice(0, 8)}`,
        'Editor perf',
    ]);
    await db.query('insert into page_drafts (page_id, site_id, document, version) values ($1, $2, $3, 1)', [
        pageId,
        rows[0]!.site_id,
        JSON.stringify(document),
    ]);
    return pageId;
}
