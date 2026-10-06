import { Head } from '@inertiajs/react';
import { Editor } from '@/editor/Editor';
import type { EditorInit } from '@/types';

export default function EditorPage({ init }: { init: EditorInit }) {
    return (
        <>
            <Head title={`Edit ${init.page.title}`} />
            {/* Keyed by page: navigating between pages starts a fresh editor session. */}
            <Editor key={init.page.id} init={init} />
        </>
    );
}
