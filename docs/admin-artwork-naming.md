# Admin artwork filename standardization

The Admin account menu includes **Admin Settings**, with vertical sections for **Artwork filenames** and **Reset portal data**. This is a separate, confirmed metadata operation; normal indexing and matching retain their read-only Drive client.

## Scope and naming

The highlighted scope panel shows only the two configured manufacturing branches: `(GROUP A) Non Carpet` and `GROUP B (Carpet)`. The reader enters only recognized direct series/version folders, and indexes only files directly in those folders. It never enters MOCKUPS, PREVIEWS, ARCHIVE or any other nested folder. The preview shows links to the exact version folders checked. Source parent, file identity, version and destination are rechecked before writes. Files referenced by any batch status, including failed or printed batches, are protected.

Bulk-generated names use:

```text
LB-PSE-DUC-RED-SINGLE-ID-8F23A19C4D72.jpg
LB-SNP-CAN-BRL-FRONT-ID-81D7BA9C4F20.jpeg
LB-SNP-CAN-BRL-BACK-ID-A0F24C8D3E91.jpeg
```

The 12-character ID suffix is derived from the full, stable Drive file ID. It stays the same on repeat scans. Every proposal preserves the real extension, including its original case; changing a name never converts an image format. Actual file IDs, file bytes and parent folders are preserved. The code also detects a destination collision rather than relying on the hash alone.

Missing-file suggestions follow the same readable LB/series/design/color/side convention but omit the ID suffix until a real Drive file exists. Production verification, the ready queue and future batches display actual filenames. Existing locked batch snapshots retain their recorded names.

An ID suffix prevents filename clashes, not semantic ambiguity. Multiple images for the same design, variant or side remain ambiguous in the production matcher and need review. The preview flags these candidates. Naming does not approve an image and does not create a print batch or handoff request.

## Workflow

1. Open **Account → Admin Settings → Artwork filenames** as an active administrator.
2. Click **Check files**. The fixed collection name is **All Bags**, saved in `config/services.php`. Only **ACTIVE** products in that exact collection are read, including products without orders. Draft, archived and other-collection products are excluded. Missing or duplicate exact collection names stop the scan; there is no whole-store fallback. Collection products and each product’s variants are fully paginated. Old previews must be refreshed after this scope change.
3. Inspect the current and proposed names. Exact SKU-code or full-title evidence can suggest a name, using an approved folder as series context for a reviewed rename. Arbitrary abbreviations, unidentified designs/colors and missing print sides are never guessed.
4. In **Match a Shopify product**, choose the Shopify product/variant first, then its Drive artwork. Both pickers have search inside their menu and small image thumbnails. Color and side are filled automatically; only missing FRONT/BACK requires a side choice. A Shopify variant without a color option is not hidden by a Drive color label. Explicit different colors and series remain restricted. Confirming a design updates the preview only and can be reused across compatible versions and future files. A mockup or wrong folder cannot be rescued by a mapping.
5. Select ready files (up to 500 per run), confirm the images/colors/sides, open the confirmation dialog and enter the current admin password. Optionally keep **Refresh unbatched artwork matches** checked. This refresh cannot approve artwork or modify any batch.
6. The browser processes five files per request, shows progress and opens the completed audit. No additional confirmation is needed per chunk. New scans leave already-standard files unchanged; newly added files are evaluated with the current catalog.

No scheduler or unattended watcher is created. The same button can be used whenever new files are added. Shopify variants are the monitored inventory: coverage lists found, missing and ambiguous artwork. Unrelated Drive files are excluded from counts and the monitored rename table. They remain available as candidates for an explicit mapping to an eligible Shopify product; otherwise they are left alone.

## Permission and setup requirements

- The existing service account needs **Editor access** to the approved manufacturing folders to rename their files. This implementation never changes sharing or creates credentials. A read-only readiness check in this environment confirmed that the account can read the files but currently cannot rename them; the Drive owner must grant the appropriate access before Apply will succeed.
- Shopify must allow `read_products`. The catalog includes products without orders, scoped to Active products in All Bags. The implementation does not write to Shopify or query customer/order history for naming.
- `GOOGLE_DRIVE_RENAME_ENABLED=false` disables applying renames while retaining scanning/preview. Applying is otherwise gated by admin role, active authenticated session, CSRF, current password, confirmed preview and a user-bound expiring token. The ordinary Drive client stays `DRIVE_READONLY`; only the dedicated writer requests `drive.metadata`.
- Existing `production_items.artwork_review` migration must be present. No new database migration is added for this feature.
- The web-server process must be able to create and update `storage/app/private/artwork-names`. Preserve it across deployments. It is outside the public directory, ignored by Git, and contains private preview/audit JSON ledgers. Do not publish it through a web-server alias or storage link. Use normal operational backup/retention for audit files.
- The existing PortalWriteLock serializes portal/factory writes for this application's single-host deployment. Multi-host deployments need a shared write lock before enabling this feature.

## Concurrency and partial failures

A preview expires after 30 minutes and is bound to its reviewing administrator. The catalog is checked again when the admin begins the confirmed run; accepted work uses that reviewed catalog snapshot. Active operations have a two-hour bound and process small chunks. Every chunk re-reads eligible Drive scope, detects changed versions/destinations and checks batch references. Files are checked for rename capability before affected unbatched selections/approvals are invalidated. Every individual metadata write repeats the file check and uses a conditional `If-Match` PATCH against the file's ETag.

Drive v2's metadata resource exposes the ETag used for this conditional title-only PATCH. The API body contains only `title`; it contains no content, parents, permissions, delete or trash operation. File metadata is checked after the response. See [Google's file metadata resource](https://developers.google.com/workspace/drive/api/reference/rest/v2/files) and [metadata update scopes](https://developers.google.com/workspace/drive/api/reference/rest/v3/files/update).

Drive and the local database cannot participate in one atomic transaction. The service therefore writes a durable audit entry before each remote attempt and clears affected unbatched approvals before that attempt. Successful rows are recorded immediately. A failed/uncertain request stops further work, remains in the ledger, and is never blindly retried. Some names may already have changed; inspect Drive and start a fresh scan. If refreshing matches fails after renaming, the result explicitly says so; renamed files are not represented as rolled back. No approval is restored automatically.

## Tests and rollout

Run the existing regression suites plus:

```text
php tests/artwork-naming.php
node tests/artwork-naming-controls.cjs
```

They use SQLite `:memory:`, a disposable audit directory, fake Shopify/Drive services, and an in-memory HTTP handler. Coverage includes catalog pagination without orders, automatic and manual previews, generic future names, unique suffixes, unchanged extensions, FRONT/BACK, excluded folders/mockups, duplicate artwork ambiguity, actor-bound/expired/replayed plans, changed file/catalog/batch state, approval invalidation, conditional metadata-only writes, permission/precondition failures, audit/partial results, confirmation, admin-only routing, chunked requests and no print handoff.

PR and deployment validation run these tests. Review and validate the PR before deployment or merge. No live rename, artwork rematch, print batch action, credential change or Drive-sharing change was performed during development. The three orders reserved for operator testing remain untouched.

## Shared Admin Settings

Artwork filenames and Reset portal data are sections of `/admin/settings`, with vertical navigation on desktop and a compact horizontal layout on smaller screens. The account menu contains a single Admin Settings entry for these tools. Artwork is the default section; reset remains a distinct Danger Zone with its existing password, phrase and factory-agent checks. GET navigation never resets data or applies names. Existing `/admin/artwork-names` and `/admin/reset` bookmarks redirect to their settings sections; POST action URLs remain compatible. Future tools can be added to the explicit section allowlist and navigation without adding more account-menu entries.


## Simpler automation workflow

Generated names and missing-file suggestions do not contain a year. Legacy year prefixes and the actual year field in catalog SKUs are ignored for naming; catalog SKUs themselves remain unchanged as evidence. Exact canonical series aliases in SKUs (including PSN-ELT/PSE) and equivalent catalog rows are resolved without an arbitrary artwork choice. Different designs sharing a code remain ambiguous.

Ready files are preselected, with one password-confirmed dialog to apply the reviewed selection; no typed rename phrase or permanently displayed password block is required. The filename filter is outside the password form to prevent email autofill from hiding every row. Technical evidence and folder/version details are collapsed. The settings tabs are flat and have no subtitles or shadows.

An unclear source design can be confirmed once with the Remember option. Its descriptive label and confirmed catalog design are saved privately in design-mappings.json with an admin audit event. Future scans and sibling versions can reuse that confirmation only when their own series, color and side agree with the current catalog. Arbitrary numeric/image placeholders, missing sides/colors, conflicting catalog evidence and unavailable variants still require review. These are data mappings, not hardcoded image-specific rules. Equivalent catalog rows never merge genuinely different product identities. Mapping changes and naming-policy updates invalidate older previews before any write.

Read-only analysis of the existing scan found that the 103 ready files were already automatically identified; the remaining labels include catalog synonyms and unavailable variants. The tool does not invent those equivalences. Reusable design confirmation reduces per-file work safely. No actual mapping or rename was applied during development.
