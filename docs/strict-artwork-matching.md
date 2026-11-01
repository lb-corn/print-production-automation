# Strict artwork matching rollout

## Behavior and operator workflow

The Google Drive service account and its read-only scope are unchanged. Only the configured Group A and Group B roots, their direct series folders, and files directly within those series folders are indexed. Nested folders and shortcuts are never followed. MOCKUP(S), PREVIEW(S), and ARCHIVE files are excluded. Empty and duplicate series folders are retained as evidence so they cannot trigger a fallback search elsewhere.

The Shopify SKU format is `YEAR-DESIGN-SERIES-NUMBER`. Series aliases include `PSE` / `PSN-ELT`; multi-token series remain distinct. Filename normalization accepts case and separator differences but requires the entire filename stem to follow `SERIES-DESIGN[-COLOR]`, optionally `-SINGLE` or `-FRONT` for a single-sided product. Double-sided products require separate `-FRONT` and `-BACK` files. Recognized series-only variants (for example Clover) use colorless filenames; explicit colors remain required and cannot match a colorless file. Unknown/ambiguous colors or SKU fields fail closed. Friendly series folder aliases (for example Clover and Surefire) identify scope without replacing exact filename tokens. There is no confidence-based approval. A duplicate on either side prevents any selection.

For `2027-DUC-PSE-0001`, variant `Pro Sniper Elite / Red`, the proposed filename is `PSE-DUC-RED.jpg`. The expected folder is `GROUP B (Carpet) / PSE` when that unique folder exists. If a series folder cannot be located, the UI reports the missing folder without inventing a Drive link. Naming suggestions are conventions only; existing-file correction suggestions retain the original extension and never rename anything. Confirm actual artwork content and color before any rename; renaming a mockup does not make it production artwork.

Artwork Verification shows missing/ambiguous instructions, proposed filenames, copy controls and the real folder link inside the right-hand comparison box, replacing invalid previews. Rematch Artwork asks for confirmation and refreshes all unbatched stock items, even when invoked from one card. Correct saved previews with exact names and direct approved paths stay visible without conferring approval; a rematch is still required for legacy selections. Both image frames and the missing-instructions frame share the same height. New suggestions use JPG (for example `CLV-EAR.jpg` for colorless Earth/Clover), per the operator preference. Custom artwork remains separate. Fix duplicate/invalid files in Drive, rematch, inspect the selected production files, then explicitly approve them.

## Changed files

- `app/Services/GoogleDriveService.php`: bounded indexing, empty-folder evidence, file revision/checksum metadata; pagination and authentication retained.
- `app/Services/ArtworkMatcherService.php`: exact normalized tokens, series resolution, sides/colors, ambiguity, suggestions and strict selection eligibility.
- `app/Services/ArtworkRematchService.php`: transactional rematch, counts, stale-match cleanup, identity/revision approval invalidation and batch protection.
- `app/Services/BacklogSyncService.php`: refresh existing eligible selections as well as new orders.
- `app/Services/ProductionService.php`: strict approval/batch eligibility and consistent lock order.
- `app/Models/ProductionItem.php`, `database/migrations/2026_11_01_000005_add_artwork_review.php`: nullable JSON review metadata.
- `app/Http/Controllers/ProductionController.php`, `routes/web.php`: confirmed, authenticated, CSRF-protected, throttled rematch behind demo/write guards; counts and display-only legacy suggestions.
- `resources/views/production/verification.blade.php`, `resources/views/partials/artwork-review.blade.php`, `public/js/app.js`, `public/css/app.css`: missing states in the right box, proposed filenames, copying, folder links, confirmation and disabled invalid approval.
- `tests/artwork-matching.php`, `tests/drive-index.php`, `tests/bulk-review.php`: isolated regression coverage and updated refresh expectations.
- `.github/workflows/artwork-tests.yml`: PR validation with no deployment; existing deployment validation also runs the new suites.

## Validation

Run the following reviewed test harnesses; each database test forces SQLite `:memory:` before running migrations. Integration clients are faked, with Drive pagination tested through an in-memory HTTP handler. Do not run production imports or rematch requests as a local verification step.

```text
php tests/drive-index.php
php tests/artwork-matching.php
php tests/bulk-review.php
php tests/admin-controls.php
node --check public/js/app.js
composer --no-plugins --no-scripts validate --no-check-publish
composer --no-plugins --no-scripts --no-cache audit --locked
```

Also lint changed PHP files and run `git diff --check`. Tests cover scope restrictions, wrong-series/color/substring rejection, extensions, missing/duplicate folders, both sides, approval invalidation (including edits to an existing Drive ID), no batch/handoff mutations, transactional rollback, confirmation, middleware and legacy-preview suppression.

## Deployment instructions (review and validation required first)

1. Review the PR and require its regression workflow to pass. Validate naming conventions against representative production files in a staging Drive folder using the existing service account configuration. No live Drive calls are needed by automated tests.
2. Back up the application/database through the normal operational process. Schedule a coordinated activation so the migration completes before the new application handles requests. Keep existing credentials, `.env`, `APP_KEY`, storage and batch payloads.
3. Deploy the reviewed branch through the established release process after approval. Run the additive migration with `php artisan migrate --force`, then refresh Laravel configuration/routes/views using the existing deployment procedure. Do not seed or reset the database. This change adds only nullable `production_items.artwork_review`; no data backfill runs during migration.
4. In Artwork Verification, explicitly run Rematch Artwork. This downloads fresh eligible metadata, recalculates unbatched stock items and returns matched/missing/ambiguous/skipped counts. Approvals for legacy selections or changed file identity/revision are invalidated. Unchanged strict selections retain their operator decision. Existing batches in every status, including failed and printed, remain untouched.
5. Resolve missing or ambiguous cases in Drive and rematch again. Review genuine production artwork before approval. Rematch never creates a print batch or requests Epson Edge Print handoff.

Existing unbatched approvals with no strict-policy evidence are intentionally ineligible for new batching until rematched and reviewed. Existing locked batches remain available through their normal workflow. The matcher is deliberately conservative: nonconforming names require operator correction. An external Drive edit after rematch cannot be detected until the next rematch/backlog refresh; coordinate Drive edits with operators.

For rollback, stop new approvals/batching and restore the prior application through the normal release process. Leave the additive nullable column in place; dropping it would discard diagnostic metadata. Do not restore obsolete approvals or modify locked batch payloads.

## Local validation result (2026-11-01)

All four reviewed test harnesses passed: 51 strict-artwork checks, the Drive pagination/allowlist check, and both existing bulk-review and admin-controls suites. PHP syntax checks across application/routes/migrations/tests, JavaScript syntax, whitespace checks, and tracked-file/symlink/high-risk-pattern security checks passed. Composer validation passed with the existing missing-license warning. `composer audit --locked` reported no security vulnerability advisories. No live Drive/Shopify request, database migration against configured storage, print handoff, deployment or merge was performed during implementation. The GitHub PR workflow must still pass on the review branch.
