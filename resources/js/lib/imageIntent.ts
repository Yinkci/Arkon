import rules from '../../arkon/ai-images.json';

/**
 * Twin of App\Arkon\Ai\Images\ImageIntent (rules in resources/arkon/ai-images.json): whether a brief
 * explicitly asks for a newly generated image. The form uses it to ask the user before sending;
 * the server checks the same rules and refuses to start without the user's choice.
 */
export function requestsGeneratedImage(text: string): boolean {
    if (rules.negations.some((p) => new RegExp(p, 'iu').test(text))) return false;
    return rules.patterns.some((p) => new RegExp(p, 'iu').test(text));
}

export const imageExamples: Record<string, boolean> = rules.examples;
