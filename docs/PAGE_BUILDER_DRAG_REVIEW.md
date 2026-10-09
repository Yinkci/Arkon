# Page Builder drag interaction

The page and reusable-component editors retain the same pointer controller used by Forms. No new drag library, document format or persistence path was added.

## Audit and changes

- The existing controller already distinguished click from drag (4px mouse/pen, 8px touch), handled pointer capture and cancellation, and resolved the actual release coordinates. Those behaviors were retained.
- Canvas components previously followed the pointer only as a small label. They now have a rendered component preview. It preserves typography and the original grip offset; large/tall components can be scaled to fit the screen.
- Canvas preview HTML stays in a separate, script-disabled sandbox. The editor canvas retains its opaque origin. Preview payloads have size and rectangle checks.
- Layers and palette items have inert previews of the item being dragged. Canvas handles remain the dedicated drag entry point; normal canvas clicks select/edit. Tapping a Move handle selects its component.
- Source space remains in place with dimmed/dashed feedback. Valid targets show an insertion line or container outline and a destination label. Neighbours respond visually without altering the document.
- Canvas and Layers animate the resulting placement. Reduced-motion preferences disable displacement and placement animation.
- Geometry is measured without temporary feedback transforms. This prevents visual displacement from changing the collision result.
- Forms and the other builders share pointer-offset positioning and placement-animation primitives, in addition to their existing shared controller. Their layout-specific collision rules remain separate.

## Preserved behavior

Nested validation, cycle prevention, container limits, empty-container handling, reversed/wrapped layout placement, scrolling the iframe versus the sidebar, delayed geometry fencing, stale-document checks and keyboard move controls remain in place. A successful drop uses the existing structural operation and one undo step. Dragging itself never saves or publishes.

All new feedback runs in admin assets. Public renderer output and public page assets were not changed for this task.

## Scope and limits

- The editor has responsive canvas widths and horizontal stage scrolling; it has no custom zoom control. Browser coordinates remain CSS pixels. Arbitrary future CSS transform scaling needs explicit coordinate conversion before a zoom UI is added.
- Very large previews use a compact label if their HTML/CSS exceeds the safe preview budget.
- Palette previews show the palette item before insertion, rather than making a server rendering request while dragging.
- Canvas placement still waits for the existing PHP redraw after release. Pointer tracking and insertion feedback run locally during the drag.
- Automated Chromium checks cover rendered UI and screenshots, including simulated touch. Physical touch hardware and subjective usability remain useful follow-up checks.

## Verification

Typecheck and 377 frontend tests passed. Production assets built successfully. All 21 combined browser checks passed (authentication setup, 12 page/component drag scenarios, two Forms drag scenarios and six Forms workflow checks). The active-drag screenshot was inspected and the preview readability was corrected before the final run. The tests use an isolated database and fake AI only.
