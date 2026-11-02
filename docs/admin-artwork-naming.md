# Admin artwork matching and filename cleanup

Admin Settings has separate sections for Artwork matching, Data sync, Filename cleanup, Manage Users and Reset portal data. Manage Users replaces the standalone Users dropdown entry; existing user-management bookmarks and actions remain supported. Cosmetic Admin-only badges are omitted, while all authorization checks remain enforced.

## Simple optional filename cleanup

Open **Admin Settings → Filename cleanup**. The page reads current Shopify catalog data and approved Drive files and lists only files whose names differ from a reliable canonical suggestion:

| Current filename | Suggested name | Action |
| --- | --- | --- |
| LB-CLV-RET7-SINGLE.jpg | LB-CLV-RET7-SINGLE-ID-XXXXXXXXXXXX.jpg | Process suggested name |

Cleanup now prefers `LB-SERIES-DESIGN-COLOR-SIDE-ID-XXXXXXXXXXXX.ext`. The ID token is the existing twelve-character uppercase hash of the exact Drive file ID. Correct ID-suffixed names are not listed or shortened. Short names appear for optional alignment. Matching continues accepting shorter names, so deployment does not block existing orders. Color is omitted when unsupported by catalog/reviewed data; CLV and LUC remain separate series. New name stays editable, subject to the existing matching and collision checks.

After a verified rename to an edited, valid name, the app remembers its fingerprint and naming-policy version in private filename-preferences.json. It is not repeatedly listed under that policy. File changes or a policy revision invalidate that preference; it never supplies print approval or bypasses matching checks.

Click **Process suggested name** on one row. That request renames that exact Drive file and refreshes unbatched artwork matches. The page returns with a success or failure message; a successfully standardized file disappears from the list. **Refresh** reloads current suggestions. There are no bulk selections, password dialogs, prepare/preview modes or typed confirmation phrases in this UI.

Filename cleanup is optional. Matching and printing can continue using valid legacy names. The Artwork matching screen no longer contains filename processing controls. Old Prepare standard names requests redirect to Filename cleanup.

Files with no reliable catalog identity are excluded rather than assigned guessed names. Use Artwork matching to confirm their design first. Reviewed choices can supply identity and variant evidence. Suggestions preserve extensions and keep a full disambiguating product design where codes are shared. Stable ID tokens never resolve duplicate-artwork ambiguity by themselves.

## Protection and results

The row action is an authenticated Admin POST with CSRF, a per-administrator limiter and the shared PortalWriteLock. A server-side actor-bound snapshot carries original metadata and suggestions. An edited name is accepted only if the matcher recognizes it for the same product identity, series, color and side, with the original extension. Shared design codes must retain the full disambiguating product design. The server rechecks the edited target against current catalog, metadata, parent, destinations and batch references before writing. It never blindly trusts a submitted filename.

Files referenced by protected batches, including queued, claimed, completed and failed batches, are blocked. Destination collisions and duplicate artwork are blocked. Missing Drive Editor access and disabled server renaming are shown directly on the list instead of offering actions that cannot work. Replay and stale-file submissions do not perform another rename. The limiter redirects to Filename cleanup with retry guidance rather than displaying the generic 429 page.

The existing metadata-only writer changes the Drive title using a conditional ETag request. File IDs, contents, extensions and parent folders stay unchanged. Original/new names, actor, timestamps and confirmed/uncertain outcomes remain in a durable private audit. The implementation never changes Drive sharing or Shopify data.

Verified renames advance saved exact-file choice fingerprints so subsequent rematches keep the same selected file. Unbatched artwork matches refresh automatically. Affected unbatched approvals are cleared and require operator review again. Print queues and batch snapshots are never rewritten.

If the remote result is uncertain, processing stops and the screen says to inspect Drive before trying again. No automatic retry runs. If the rename succeeds but order refresh fails, the message states that separately; the file is not presented as rolled back.

## Matching and catalog sync

**Artwork matching** is the product-first image comparison workflow. It supports distinct front/back slots for double-sided products and one production face for single-sided products. Confirming artwork saves an audited exact-file choice and refreshes unbatched matches; print approval stays separate. Explicit color/side contradictions cannot be silently overridden. Known series names are not inferred as colors. Saving results, validation errors and uncertain connection outcomes appear inside the dialog.

**Data sync → Sync catalog** refreshes only active products/variants in Shopify's exact All Bags collection and artwork in the approved Drive scope. It does not import orders, rematch saved orders, modify approvals or print queues, rename files or change users. Order backlog import and artwork rematch are separate operational actions.

Order backlog import reads the current SKU/title through the order line's exact linked variant, where its product identity agrees, so catalog SKU corrections reach unbatched orders without guessing by label. Original checkout values are retained as review evidence when they differ. Batched items remain protected.

## Scope and setup

Only direct artwork files inside recognized direct series folders under `(GROUP A) Non Carpet` and `GROUP B (Carpet)` are eligible. No MOCKUPS, PREVIEWS, ARCHIVE, arbitrary nested folders or shortcuts are traversed. Shopify collections, collection products and variants are paginated, and missing/duplicate exact All Bags collections stop catalog scans.

- The existing service account needs Editor access for renaming; ordinary indexing still uses the read-only client.
- `GOOGLE_DRIVE_RENAME_ENABLED=false` disables processing. To enable it, set the server variable to true and clear/rebuild its Laravel configuration cache. This feature never edits the server environment automatically.
- Shopify needs read_products access for catalog scans.
- The artwork_review database migration must be installed before order refresh/approval.
- Preserve and back up `storage/app/private/artwork-names`. It contains actor-bound snapshots, audits, exact-file choices and remembered design mappings. It must not be publicly served.
- Deployments and ordinary production-data resets preserve this private artwork configuration, users and connection settings. Local choices do not automatically transfer to production.

## Verification

The isolated regression suites use SQLite memory databases, disposable private ledgers and fake Shopify/Drive clients. They perform no live renames or printing:

```text
php tests/artwork-naming.php
php tests/artwork-standardization.php
php tests/filename-cleanup.php
php tests/order-catalog-refresh.php
node tests/artwork-naming-controls.cjs
node tests/rematch-controls.cjs
php tests/rematch-throttle.php
```

The lower-level rename engine retains guarded snapshot/audit processing for the one-row action. Its historical bulk browser workflow is no longer exposed on the artwork screen. Deployment does not itself perform any rename, rematch, approval or print operation.
