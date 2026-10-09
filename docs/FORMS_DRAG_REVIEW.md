# Forms drag-and-drop review

## Findings

Arkon uses its own shared pointer-event DragController, not HTML5 drag-and-drop or a third-party sortable library. Mouse and pen activate after 4px of movement; touch activates after 8px. Handles use pointer capture. A click remains a selection action. Cancellation covers Escape, pointer cancellation, lost capture, blur, unmount, permissions and stale document versions.

Forms already used this controller, but skipped the visual feedback provided by the page editor's DragProvider. There was no floating field preview or precise insertion marker. Collision detection used narrow 12px row-edge regions and highlighted the whole row. The visible form changed only at release, giving the impression of switching positions. Auto-scroll multiplied elapsed seconds by 0.5, producing approximately half a pixel per second instead of a usable scrolling speed.

## Changes

- A readable, inert clone of the field lifts from its original position and follows the pointer at the original grip offset. Its transform updates directly once per animation frame; React does not rerender the form on every move.
- The original field becomes a subdued dashed placeholder and retains its space. The body selects; only the grip starts moving an existing field. Duplicate, delete and settings actions remain separate.
- Horizontal insertion lines identify new rows; vertical lines identify column positions. Nearby fields make restrained room. Row-edge hysteresis and an 8px column-boundary dead band reduce flickering between destinations.
- Collision measurements are captured in content coordinates before feedback transforms. Scrolling adjusts those coordinates; animated feedback never feeds back into hit testing. Mobile's stacked preview creates row insertions rather than silently adding hidden desktop columns.
- Auto-scroll tapers near the canvas edges, caps elapsed frame time and reaches 450px/second at the edge. It continues while the pointer remains stationary and stops when the pointer leaves the canvas horizontally.
- Final placement animates for 160ms. The floating preview has no CSS transition, avoiding pointer lag. Reduced-motion preferences skip placement animation. Resize cancels safely because the cached geometry is no longer valid.
- Only a completed valid drop changes the local form draft. Existing manual save, undo/redo and immutable save batches remain in charge. Field IDs, merge tags and conditions are preserved.

The shared controller was retained without changing its behavior for page and reusable-component editors. Forms-specific row geometry and feedback live in small separate modules. Published-site assets and PHP rendering were not changed.

## Verification

- Type checking and all 375 frontend tests passed, including six new collision/scroll tests.
- All nine final Forms browser checks passed, including setup, tiny-motion selection, slow pointer tracking and offset, column insertion, unchanged order before release, Escape cancellation, long-form stationary scrolling, fast drop, stable identity and the existing complete form lifecycle.
- The existing page-builder drag suite passed all eleven drag scenarios in the combined run.
- Production build passed. The active drag screenshot was visually inspected.

The direct desktop browser-control tool could not initialize; rendered interaction verification used Playwright in Chromium with disposable test data. Touch still uses the shared 8px pointer sensor; this round did not include a physical touchscreen test. Keyboard Move up/down controls remain available. File uploads and page breaks are not supported form types yet, so no unsupported capability is claimed.

Nothing was committed or pushed.
