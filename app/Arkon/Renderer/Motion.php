<?php

namespace App\Arkon\Renderer;

/**
 * Entrance animations of published pages: the shared CSS (keyframes and the trigger
 * rules) and the one trusted progressive-enhancement script for "when scrolled into
 * view". Both are versioned and immutable: a publication records the runtime it was
 * rendered with (render_inputs.motion), and a change ships as a new runtime version.
 *
 * - Animations are CSS only (opacity and transform); content never depends on the script.
 * - motion-3 (current): as motion-2, plus keyboard focus shows every entrance around it at
 *   once and for good, whatever the trigger, so its script is loaded on every page with an
 *   entrance (not only "when scrolled into view").
 * - motion-2 (kept byte for byte): every entrance starts with the page, so content on screen
 *   at load plays it; for "when scrolled into view" (.ak-reveal) the script holds back only
 *   unseen blocks below the fold until they come into view. Focus only showed held-back blocks.
 * - motion-1 (kept byte for byte) paused reveal blocks and showed blocks on screen at load
 *   without an entrance.
 * - prefers-reduced-motion and printing show the final state immediately.
 * - The editor canvas gets EDITOR_CSS: the final state always, and an animation only for
 *   an explicit preview (.ak-replay, set by the canvas bridge).
 */
final class Motion
{
    public const RUNTIME = 'motion-3';

    private const KEYFRAMES = '@keyframes ak-a-fade{from{opacity:0}}'
        .'@keyframes ak-a-up{from{opacity:0;transform:translate3d(0,1.5rem,0)}}'
        .'@keyframes ak-a-down{from{opacity:0;transform:translate3d(0,-1.5rem,0)}}'
        .'@keyframes ak-a-left{from{opacity:0;transform:translate3d(1.5rem,0,0)}}'
        .'@keyframes ak-a-right{from{opacity:0;transform:translate3d(-1.5rem,0,0)}}'
        .'@keyframes ak-a-zoom{from{opacity:0;transform:scale(.94)}}'
        // Sideways entrances never make the page scroll horizontally.
        .'body{overflow-x:clip}'
        .':where(.ak-anim){animation-duration:.6s;animation-timing-function:cubic-bezier(.16,1,.3,1);animation-fill-mode:both}';

    private const REDUCED = '@media (prefers-reduced-motion:reduce){html .ak-anim{animation:none}}@media print{html .ak-anim{animation:none}}';

    /** motion-2: also undoes a hold-back (.ak-wait) for reduced motion and printing. */
    private const REDUCED_2 = '@media (prefers-reduced-motion:reduce){html .ak-anim{animation:none;opacity:1}}@media print{html .ak-anim{animation:none;opacity:1}}';

    /**
     * Runtime versions this code can produce. `src` is a static, immutable file under public/;
     * `integrity` pins its bytes (checked by tests and by the browser). `scriptFor`: the pages
     * that load it (`view`: those with a "when scrolled into view" block; `any`: any entrance).
     *
     * @var array<string, array{src: string, integrity: string, scriptFor: 'view'|'any', css: string, editorCss: string}>
     */
    public const RUNTIMES = [
        'motion-1' => [
            'src' => '/_arkon/motion-1.js',
            'integrity' => 'sha384-DSgoXTH8FQ277yBLQN9hUc69Tkp2cu0Or/RroCHoz+12tAEmk9GPpQ87nFtYH4tX',
            'scriptFor' => 'view',
            'css' => self::KEYFRAMES
                // Without the runtime, blocks waiting for the viewport just show (no animation, no hiding).
                .'html:not(.ak-reveal-on) .ak-reveal,.ak-reveal.ak-static,.ak-reveal-on .ak-reveal:focus-within{animation-name:none}'
                .'.ak-reveal-on .ak-reveal:not(.ak-in){animation-play-state:paused}'
                .self::REDUCED,
            'editorCss' => self::KEYFRAMES.'.ak-anim:not(.ak-replay){animation-name:none}'.self::REDUCED,
        ],
        // Every entrance starts with the page (CSS, no script needed): blocks on screen when the page opens
        // play their entrance from the first paint, and without JavaScript everything plays on load and ends
        // shown. For "when scrolled into view", the runtime then holds back (.ak-wait) only blocks that are
        // below the fold and still unseen, and lets each play once when it comes into view; a finished
        // entrance is never hidden again. Keyboard focus shows a held-back block at once (.ak-shown).
        'motion-2' => [
            'src' => '/_arkon/motion-2.js',
            'integrity' => 'sha384-R5QzvHCVFLp/KC8mNpj+VUbBqbghmvigBfJCqGT8odvH8zvHvdMu5TFRkRZA4RcK',
            'scriptFor' => 'view',
            'css' => self::KEYFRAMES
                .'.ak-anim.ak-wait{animation-name:none;opacity:0}'
                .'.ak-anim.ak-wait:focus-within,.ak-anim.ak-shown{animation-name:none;opacity:1}'
                .self::REDUCED_2,
            'editorCss' => self::KEYFRAMES.'.ak-anim:not(.ak-replay){animation-name:none}'.self::REDUCED,
        ],
        // motion-2, plus: keyboard focus inside any animated block (on screen or held back, during its delay or
        // while it plays, nested or not) shows the focused content and every animated block around it at once.
        // CSS :focus-within does it with no script (opacity only, while focus stays; an !important declaration
        // wins over the running animation without restarting it); the script, now loaded on every page with an
        // entrance, makes it last (.ak-shown ends the entrance), so content seen once is never hidden again.
        'motion-3' => [
            'src' => '/_arkon/motion-3.js',
            'integrity' => 'sha384-6PvqrkImj3HYgJm9IcIi6mPm86woYNPLa78icP7eIPEHyU0cqyEK2lpTn269y47P',
            'scriptFor' => 'any',
            'css' => self::KEYFRAMES
                .'.ak-anim.ak-wait{animation-name:none;opacity:0}'
                .'.ak-anim.ak-shown{animation-name:none;opacity:1}'
                .'.ak-anim:focus-within{opacity:1!important}'
                .self::REDUCED_2,
            'editorCss' => self::KEYFRAMES.'.ak-anim:not(.ak-replay){animation-name:none}'.self::REDUCED,
        ],
    ];

    /** The script tag for a runtime (production pages only; which ones: its `scriptFor`). */
    public static function scriptTag(string $runtime): string
    {
        $r = self::RUNTIMES[$runtime];

        return '<script src="'.$r['src'].'" integrity="'.$r['integrity'].'" defer></script>';
    }

    /**
     * The runtime a stored page loads: the recorded one, only if the HTML really contains its
     * script tag (pages without it, e.g. animated only "on page load" before motion-3, get none allowed).
     */
    public static function loadedBy(?string $recorded, string $html): ?string
    {
        return $recorded !== null && isset(self::RUNTIMES[$recorded]) && str_contains($html, self::scriptTag($recorded)) ? $recorded : null;
    }

    /**
     * The Content-Security-Policy script source for a page: none, or exactly the runtime's URL
     * on this origin (never 'self', 'unsafe-inline' or 'unsafe-eval').
     */
    public static function scriptSrc(?string $runtime, string $origin): string
    {
        return $runtime !== null && isset(self::RUNTIMES[$runtime]) ? $origin.self::RUNTIMES[$runtime]['src'] : "'none'";
    }
}
