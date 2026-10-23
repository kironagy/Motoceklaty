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

## Live website updates — 2026-10-23

### Calculator and installment results
- Rename the dedicated calculator result heading to "القسط الشهري".
- Show the actual administrative fees returned by the existing server calculator on the dedicated result dashboard and in modal results; reset the fee display when selections change.
- Explain that fees are paid once on delivery, separately from installment totals.
- Replace the unstructured result modal with a MotoGate payment-plan card: selected motorcycle and finance system, prominent monthly amount, separate cards for months, deposit, installment total and administrative fees, plus a preselected application link.
- Populate result fields with textContent and preserve the existing model/system/month/deposit parameters in the application URL.
- Remove the duplicated administrative-fee note from the modal.
- Give the popup a constrained width, compact spacing on short laptop screens, a fixed header and a scrollable body with min-height:0 so figures and actions cannot be clipped at normal zoom.

### Tracking page design
- Replace the previous tracking layout with an Arabic MotoGate journey page, matching the dark/orange visual identity.
- Add a motorcycle illustration, request/code search card, empty-state journey, and an updated result panel.
- Show the current stage, stage counter, completed/current/upcoming labels and contact link; use a horizontal timeline on desktop and vertical stages on narrower screens.
- Continue supporting encrypted codes and numeric/Arabic request numbers without exposing customer personal details.

### Dashboard statistics
- Confirmed the four gauge statistics already use the existing About Settings > Numbers controls (number, suffix and label). No duplicate fields or database changes were necessary.

### Deployment and backup work (server operations, not repository files)
- With explicit authorization, removed the manual /root/backups directory, freeing about 21 GB; available disk space became about 23 GB.
- Kept the separate Hestia /backup archives intact.
- Created and verified a 36 MB compressed backup of the previous code/design inside the project root, excluding storage, vendor, Git history, runtime dependencies and large archives.
- Deployed updated code while preserving the existing .env, uploads/storage link, vendor dependencies and the server's attendance-page customization.
- Ran php artisan migrate --force; the server reported no pending migrations. No database reset, truncation or record deletion was performed.
- Restored original root directory permissions after a deployment-time 403; .htaccess was restored from the backup and verified identical to the original. Live page and existing-image checks returned HTTP 200.
- Took additional small backups of affected files before the tracking/calculator/popup edits.

### Live validation
- Successfully calculated a real installment with an empty deposit; verified all displayed result fields and the selected motorcycle/system.
- Verified numeric request tracking and exactly one current-stage marker in the new journey layout.
- Checked popup layout on desktop and a 390px mobile viewport.
- At 1366x650, fees, total and the application button fit entirely inside the popup. At 1024x500, the body scrolls internally instead of clipping content.
- Live home, showroom, tracking and calculator pages and the new stylesheets returned HTTP 200.
- Changes do not include credentials, environment files, database dumps, uploads or backups.

## Showroom responsive follow-up — 2026-10-23
- Replace cramped four-across mobile specs with two-column tiles containing an icon, label and wrapping value; update the same markup when selecting a different model.
- Redesign motorcycle cards with a contained image, readable brand/model/category/price and a separate display action followed by side-by-side WhatsApp and installment buttons.
- Use three card columns on desktop, two on tablet and one on small phones while retaining nine models per slider group.
- Constrain grid/flex children and remove absolute button positioning and nowrap constraints to prevent horizontal clipping.
- Adapt the featured-model details, price and actions for mobile/tablet layouts.
- Deploy the three affected files directly with a small pre-change backup. Preserve .env, .htaccess, root directory permissions, database and uploaded storage.
- Validation: JavaScript syntax check, Blade compilation and live HTTP 200. Browser checks at 320, 390, 768 and 1440px found no document horizontal overflow. Verified selecting a card updates the featured model and labeled spec tiles.
