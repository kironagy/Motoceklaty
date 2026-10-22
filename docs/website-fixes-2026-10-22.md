# Website fixes and local setup — 2026-10-22

## Calculator
- Remove the required down payment field on the home/modal and dedicated calculator forms. Empty input is submitted as zero; server-side system minimums still apply.
- Submit calculation requests to the current origin with session cookies.
- On HTTP 419, fetch a fresh session CSRF token from a non-cacheable, rate-limited endpoint and retry once. CSRF protection remains enabled.

## Request tracking
- Accept a plain request number in addition to existing encrypted tracking codes.
- Normalize Arabic and Persian numerals before looking up a request.
- Continue showing only status and model information, without customer names, addresses or telephone numbers.
- Render separate numbered/checkmark circles and readable labels, with a connected vertical timeline.
- Highlight exactly the current stage in orange; distinguish completed stages with checkmarks and future stages in gray.

## Showroom
- Replace server pagination with horizontally scrollable groups of nine models: three columns by three rows on desktop, two columns on medium screens and one on mobile.
- Add previous/next controls, slide count, swipe/scroll snapping and keyboard navigation.
- Clicking non-interactive card content displays that model on the upper platform. WhatsApp and calculator controls retain their actions.
- Separate card buttons and override mobile absolute positioning that caused overlapping actions.
- Version changed integration assets to avoid stale browser caches.

## Layout
- Remove the Tawk chat widget script from the shared site layout.

## Local setup performed (runtime data not committed)
- Verified PHP/Composer requirements and installed frontend dependencies; built Vite assets.
- Created Laravel runtime directories and generated a local application key.
- Started local MySQL/Apache/PHP services and linked public/storage to storage/app/public.
- Imported the supplied SQL dump into a separate local database, switched the local .env connection and verified no pending migrations.
- Environment secrets, SQL dumps, backups, customer records, generated assets and local credentials are excluded from this change.
- Imported image paths still require the actual uploaded image files in storage/app/public.

## Validation
- Website feature tests for calculator, tracking and showroom: 4 tests, 45 assertions passed on a separate test database.
- Browser verification: empty down payment produced an installment result; numeric tracking displayed an existing request status; card selection updated the platform; slides contained nine models and desktop used three columns.
- HTTP verification: invalid CSRF token returned 419; valid token reached validation (422 for empty input).
- Tracking layout follow-up: response 200, four distinct stage markers and exactly one aria-current="step" marker.
