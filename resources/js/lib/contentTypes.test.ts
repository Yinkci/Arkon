import { expect, test } from 'vitest';
import { firstProblem, requestBody, type TypeDraft } from './contentTypes';

const service: TypeDraft = {
    key: 'service',
    name: 'Services',
    singular: 'Service',
    fields: [
        { key: 'service_name', label: 'Service Name', type: 'text', settings: {} },
        { key: 'services_image', label: 'Services Image', type: 'media', settings: {} },
    ],
};

test('a field with no settings is sent as an empty object, not a list or a string', () => {
    // What actually goes over the wire, after JSON.stringify, for the type that failed.
    const sent = JSON.parse(JSON.stringify(requestBody(service)));
    for (const field of sent.fields) {
        expect(Array.isArray(field.settings)).toBe(false);
        expect(typeof field.settings).toBe('object');
        expect(field.settings).not.toBeNull();
        expect(field.settings).toEqual({});
    }
    expect(JSON.stringify(sent.fields[0].settings)).toBe('{}');
});

test('settings keep their types and are copied, never shared between fields', () => {
    const draft: TypeDraft = {
        ...service,
        fields: [
            { key: 'a', label: 'A', type: 'text', settings: { required: true, maxLength: 40, placeholder: 'Name' } },
            { key: 'b', label: 'B', type: 'select', settings: {}, choices: [{ label: 'One', value: 'one' }] },
        ],
    };
    const body = requestBody(draft);
    const sent = JSON.parse(JSON.stringify(body));
    expect(sent.fields[0].settings).toEqual({ required: true, maxLength: 40, placeholder: 'Name' });
    expect(typeof sent.fields[0].settings.required).toBe('boolean');
    expect(typeof sent.fields[0].settings.maxLength).toBe('number');
    expect(sent.fields[1].choices).toEqual([{ label: 'One', value: 'one' }]);

    // Changing the body must not reach back into the editor's state.
    (body.fields[0]!.settings as Record<string, unknown>).required = false;
    expect(draft.fields[0]!.settings.required).toBe(true);
});

test('the editor refuses what it can judge itself', () => {
    expect(firstProblem(service)).toBeNull();
    expect(firstProblem({ ...service, singular: '  ' })).toMatch(/singular name/);
    expect(firstProblem({ ...service, name: '' })).toMatch(/plural name/);
    expect(firstProblem({ ...service, key: 'Service' })).toMatch(/lowercase/);
    expect(firstProblem({ ...service, key: '2fast' })).toMatch(/lowercase/);
    expect(firstProblem({ ...service, fields: [service.fields[0]!, { ...service.fields[1]!, key: 'service_name' }] })).toMatch(
        /cannot share the key "service_name"/,
    );
    expect(firstProblem({ ...service, fields: [{ ...service.fields[0]!, label: '' }] })).toMatch(/needs a label/);
    expect(firstProblem({ ...service, fields: [{ ...service.fields[0]!, key: 'Bad Key' }] })).toMatch(/lowercase/);
});

test('an empty type is allowed: a type with no fields still has a title, a URL and SEO', () => {
    expect(firstProblem({ ...service, fields: [] })).toBeNull();
    expect(requestBody({ ...service, fields: [] }).fields).toEqual([]);
});
