import { Head } from '@inertiajs/react';
import { ComponentEditor, type ComponentEditorInit } from '@/editor/ComponentEditor';

export default function ComponentEditorPage({ init }: { init: ComponentEditorInit }) {
    return (
        <>
            <Head title={`Component: ${init.component.name}`} />
            <ComponentEditor key={init.component.id} init={init} />
        </>
    );
}
