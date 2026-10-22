# MotoGate 2 integration

The approved input is `motogate-2/`. Its source files are untouched. Public pages reuse its section order, markup and CSS; no additional public-page sections were introduced. The nine layout CSS files in `public/motogate-v2/assets/css` match the approved source hashes recorded in `resources/site/motogate-v2-css-hashes.json`. `integration.css` handles backend feedback/results and the existing detail/application pages that have no equivalent in the supplied design.

## Data and dashboard

The existing Website Settings pages remain the editing entry points. Source copy and meaningful images are stored under each existing group's `design` key. Home sliders, categories, enabled sections, about FAQs, contact subjects and general navigation/contact information remain editable. Models, brands, installment systems/plans, articles, staff, reviews and branches come from the existing database/resources. Source demo JavaScript is not loaded.

The calculator posts to the existing domain calculator, and its application link carries the selected model, plan, duration and deposit. Application validation checks active model/system/plan combinations and deposit limits before storing documents. Contact submissions use the existing contact-message resource. Tracking accepts an encrypted receipt code and reveals status/model only, with no customer details; tracking responses suppress analytics/custom scripts and disable indexing/caching.

The original comments/newsletter endpoints and dashboard records remain available. No additional comments or newsletter sections were added to the source design. Existing product details, application, article listing and team routes remain available.

## Fidelity pass (2026-10-21)

- Restored the spaces the source has between heading text and its `<em>`/`<span>` parts (e.g. "اختار رفيق <em>رحلتك الجاية</em>").
- Desktop nav ends with the tracking link, as in the source; the footer maps each dashboard social link to the source's coloured icon (facebook, instagram, tiktok, whatsapp, youtube).
- The source's `#searchModal` is in the layout and lists active models from the database, each linking to its model page.
- About: team cards fall back to the source team photo and show 01–04; review cards show the numbered badge; the FAQ title has a highlighted second line (`about.faq.title_highlight`) and the first answer starts open.
- Article pages render `$post->body` again (the conversion had turned it into a literal text key).

## Literal shell (2026-10-21)

- Pages with a source file set `@section('design_css', …)`; the layout then emits that page's exact stylesheet list and skeleton (drawer, header, `main#main`, footer; home/showroom inside `.home-shell`/`.gallery-shell` with the skip link). Legacy CSS (feather, font-awesome, swiper, site-extras) only loads on pages without `design_css`.
- Model pages (`/machines/{id}`) render the showroom with that model on the platform, as `machines.html?model=` does; "احسب القسط" opens the source's `#paymentCalculator`, results go to `#paymentResult` (home/showroom) or the finance dashboard (calculator page).
- Articles render the body as the source does: one `section` per heading, with matching table of contents.
- `/team` redirects to `/about#team`; `/blog` and `/apply-installment` have no source page and are built only from the source's interior-page parts.

## Adoption and backups

`php artisan site:adopt-motogate-v2` backs up saved website settings to the local disk's `site-backups/` directory, then replaces only values matching known old defaults. Custom values are preserved. It is repeatable. The command has already been run locally. No production deployment has been performed.

The source does not supply a playable brand video. Configure the brand video through Home Settings; do not substitute a fictional promotional video. Existing WhatsApp/OCR integrations continue using the environment's configured providers and credentials.

## Validation

Run with a PHP runtime supporting intl and the existing configured MySQL test database:

```sh
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/WebsiteTest.php
```

Validated locally with PHP 8.5: 17 tests, 178 assertions; four PHP 8.5 deprecations remain in existing database configuration/framework dependencies. Covers public routes, formula, dashboard editing, contact persistence, OCR success/fallback, tracking privacy/status changes, source section/CSS preservation, valid HTML calculator configuration, adoption idempotence and rejected application terms/models/deposits.

Browser verification includes live calculator selections/result, application preselection, showroom, contact and about responsive rendering. OCR/WhatsApp delivery against external live providers was not exercised by these tests.
