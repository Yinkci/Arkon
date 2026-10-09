# Arkon Forms redesign

Forms now has a library and a dedicated workspace for each form: **Build, Settings, Confirmations, Notifications and Entries**. Open `/admin/forms` to begin.

## What changed

The previous screen combined field editing, publication, an operational email recipient and submissions. It supported a small field set and offered little visual structure. The new workspace separates those jobs and keeps the existing immutable definitions, encrypted entries, permissions and explicit publishing model.

- **Library:** named forms, search, status, modification dates, entry counts for authorized users, blank/contact/newsletter starters and retry-safe duplication.
- **Build:** visual field palette, pointer drag and drop, reordering, field selection, duplication, deletion with confirmation, undo/redo, rows and columns, desktop/tablet/mobile canvas widths and an inspector. Field identifiers stay stable when labels or positions change.
- **Fields:** text, paragraph, email, telephone, number, URL, dropdown, radio buttons, checkbox groups, consent checkbox, date, time, section heading and divider. Choice labels and submitted values are separate. Fields support required states, descriptions, placeholders, defaults, applicable number limits and conditional visibility.
- **Settings:** name, description, submit label, active/inactive state, live usage and protected archiving. A form used by a live page cannot be archived accidentally.
- **Confirmations:** a saved message or validated redirect. Draft preview validates test input without creating entries or sending mail.
- **Notifications:** multiple messages, literal or field-based recipients, reply-to, safe subject/body merge fields and conditional routing. Sender address comes from the configured mail service. Transport acceptance and failure are shown separately from submission success.
- **Entries:** per-form search, dates, read/unread, stars, spam/trash/restore, bulk actions, pagination, historical field labels and filtered CSV export. Exports neutralize spreadsheet formulas.

## Publishing and recovery

Save draft and Publish are separate actions. Unsaved changes are clearly marked. Save requests carry immutable data and an idempotency key; an uncertain save locks editing until the same request is retried. Publishing uses existing version checks, permissions and site publication ordering.

Public submission retries also retain the same values and key. Inputs are locked while submission is outstanding or unconfirmed so a retry cannot silently send a different payload or erase later typing. Server validation remains authoritative, including allowed choices and hidden-field filtering. Forms work through native HTML submission when JavaScript is unavailable; conditional fields that become required are presented through server validation recovery.

Existing forms are adapted in memory without rewriting their history. Existing entry identifiers, timestamps, encrypted payloads and version references are preserved. An additive migration adds management metadata and an idempotent data upgrade creates keyed search tokens for existing encrypted entries. Original published output remains reproducible. New form runtime behavior is released as a separate immutable asset and renderer version.

## Security and performance

Site scoping and separate form/entry capabilities are checked on the server. Editors can build forms but cannot view private entries or configure notification recipients. Public submission access requires a live form reference on the correct host; origin checks, honeypot, bounded bodies and rate limiting remain enforced. Personal values stay encrypted. Search uses keyed tokens rather than plaintext values; it matches whole words and complete email addresses, not arbitrary substrings.

Published forms use semantic labels, fieldsets, descriptions and accessible error/status messages. A small deferred form script is included only when a modern form is present; the public site does not load the admin builder. No page-speed or field Core Web Vitals score is claimed without measurement.

## Deliberate remaining limits

File uploads, multi-step forms, integrations, scheduled forms, advanced custom validators, multiple conditional confirmations, entry notes/editing, notification resend and analytics remain future work. They are not presented as working controls.

Notifications require a real configured mail transport. Delivery is synchronous and best effort; there is no durable email outbox or automatic retry yet. A successful entry is retained even when email fails. A transport's acceptance does not guarantee inbox delivery. Newsletter forms collect entries locally; they do not subscribe visitors to an external mailing platform.

Entry search depends on the application's encryption/search key. Key rotation requires a planned decrypt/reindex migration. Archived forms retain history and entries; an archive-restoration UI and permanent deletion/retention policy are deferred.

No changes were committed or pushed as part of this redesign.

## Verification

- Full PHP suite: 394 tests, 2,645 assertions passed. After the final immutable runtime change, the affected Forms and Pages suite passed again: 44 tests, 198 assertions.
- TypeScript type checking passed; the full Vitest suite passed all 369 tests.
- Production build and PHP formatting passed.
- Browser suite: seven tests passed, including sign-in setup, visual field layout, the complete form workflow, lost save/submission responses and native submission with JavaScript disabled.
- All 49 existing development publications reproduced byte for byte.
- Runtime privilege check passed. The optional Herd smoke test skipped because its separate credentials were not configured; it was not counted as a passing test.

Screenshots: [Builder](forms-builder-desktop.png), [Dark theme](forms-builder-dark.png), [Entries](forms-entries.png).
