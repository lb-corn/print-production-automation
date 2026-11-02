# Admin artwork filename standardization

The Admin account menu includes **Admin Settings**, with vertical sections for **Artwork filenames** and **Reset portal data**. This is a separate, confirmed metadata operation; normal indexing and matching retain their read-only Drive client.

## Scope and naming

The highlighted scope panel shows only the two configured manufacturing branches: `(GROUP A) Non Carpet` and `GROUP B (Carpet)`. The reader enters only recognized direct series/version folders, and indexes only files directly in those folders. It never enters MOCKUPS, PREVIEWS, ARCHIVE or any other nested folder. The preview shows links to the exact version folders checked. Source parent, file identity, version and destination are rechecked before writes. Files referenced by any batch status, including failed or printed batches, are protected.

Only incompatible filenames are proposed for renaming. Any name already accepted by the production matcher is treated as standard and preserved exactly: short code names, established long names, aliases, capitalization, separators and existing validated ID-suffixed names. An explicit reviewed choice also preserves a correctly structured series/design/color/side name when its design wording differs from Shopify. Remembered design labels are reused by production matching, not just by the rename preview. Neither local nor production adds a new ID simply to fit a preferred spelling.

New corrections use portable names, for example:

~~~text
LB-PSE-DUC-RED-SINGLE.jpg
LB-SNP-CAN-BRL-FRONT.jpeg
LB-SNP-CAN-BRL-BACK.jpeg
~~~

No year or ID suffix is added. Actual extensions and file contents are preserved. If different catalog designs share a series/design code, a reviewed correction uses the exact full normalized product design name to disambiguate it across environments. Existing compatible full-title names remain unchanged. Destination collisions and duplicate artwork require review; they are never fixed by appending unique IDs. After a successful standard rename, both local and production can match the shared Drive filename without repeating that rename or copying a local database.

Explicit operator choices for unresolved ambiguity remain private per-environment data. Filename standardization is not print approval. Repeat scans do not rename standard files; regular sync/rematch pulls their actual Drive filenames.

Previously reviewed short names are not forcibly expanded because a SKU is shared. The exact-file choice resolves the ambiguity in that environment. When an incompatible file genuinely needs a new name, its full design name can make the correction portable. A remembered arbitrary alias still needs the reviewed mapping in any separate environment; the app cannot infer unrelated design names from a folder or image similarity.

A confirmed exact-file choice can also supply a color absent from the filename. For example, a reviewed `SRF-0G 2027-MIDNIGHT-FRONT.jpg` / `BACK.jpg` pair may be linked to a Black variant without renaming. This does not create a global MIDNIGHT-to-BLACK alias: unreviewed files remain unmatched, explicit conflicting colors still require correction, and changed file fingerprints invalidate the choice. The existing names become standard only in the context of the reviewed variant and required sides; printing still requires approval.

## Reset and persistent primary settings

Admin Reset production data deletes only imported production items, their approval decisions, print batches and print confirmations. It preserves artwork exact-file choices, remembered design mappings, rename audits, users, credentials and service settings. These choices/rules are primary configuration in `storage/app/private/artwork-names`, not resettable order data. The deployment uploader excludes runtime storage and server activation does not delete this directory. Keep it persistent and backed up on each server; deployments do not transfer local reviewed choices to production. Reimported orders use the retained choices but require new print approval.

## Workflow

**Check files & refresh orders** reads current Drive filenames, replaces the saved Admin preview, and rematches unbatched production orders using that same index. Use this after renaming files in Drive or another environment; opening a saved preview alone does not refresh orders. The result reports order match counts or an explicit refresh failure. No rename permission is needed, no print approval is granted, and batched items remain protected. Filename changes are optional and collapsed; when disabled, the preview has no rename checkboxes or inactive submit button. Missing-order cards link to a product-filtered matching worklist, including already-found variants.

1. Open **Account → Admin Settings → Artwork filenames** as an active administrator.
2. The page loads a recent admin-bound preview automatically, or performs a fresh read-only scan when none is recent and valid. **Check files** forces an immediate refresh. The fixed collection name is **All Bags**, saved in `config/services.php`. Only **ACTIVE** products in that exact collection are read, including products without orders. Draft, archived and other-collection products are excluded. Missing or duplicate exact collection names stop the scan; there is no whole-store fallback. Collection products and each product’s variants are fully paginated. Old previews must be refreshed after this scope change.
3. Inspect the current and proposed names. Exact SKU-code or full-title evidence can suggest a name, using an approved folder as series context for a reviewed rename. Arbitrary abbreviations, unidentified designs/colors and missing print sides are never guessed.
4. Use the single Shopify product worklist. Search by product/SKU and click **Match artwork** on a row. The dialog keeps that product fixed and shows thumbnail choices from its exact direct series folder. Already-standard and other eligible unbatched files remain available; linked files are labeled. Search filenames inside the chooser, inspect the full image if needed, then **Confirm this artwork**. No candidate is preselected, scored as correct or approved for printing. Color and side are filled automatically; only missing FRONT/BACK requires a side choice. A Shopify variant without a color option is not hidden by a Drive color label. Wrong explicit colors, wrong parent folders, mockups and batched files remain excluded. Missing/ambiguous series folders show no candidates. The server independently rejects unconfirmed color contradictions and all FRONT/BACK contradictions. Confirmation saves an audited exact-file choice for this product/variant/side, refreshes unbatched production matches, and separately prepares filename changes. It never approves printing.
5. Open the collapsed **Filename changes** section. Select ready files (up to 500 per run), confirm the images/colors/sides, open the confirmation dialog and enter the current admin password. Optionally keep **Refresh unbatched artwork matches** checked. This refresh cannot approve artwork or modify any batch.
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

## Double-sided artwork and missing choices

For double-sided Shopify variants, the visual chooser has separate **Front** and **Back** slots. Files with explicit FRONT/BACK fill the corresponding slot; for an unnamed side, first select the intended slot. Both distinct files must be chosen before **Confirm front + back**. The server validates both together before saving a preview, rejects swapped sides, protected files and conflicting colors, and never renames files or creates a batch during mapping. Single-sided products retain one choice.

**Can’t find it?** lists same-folder files excluded by color or batch protection, with reasons and read-only Drive links. Filename search searches these excluded files too and expands matching results. NAVY is not silently treated as BLUE. After inspecting the actual image, **Use & correct color** can select an unbatched file whose filename color label is wrong. A separate required checkbox confirms that the actual image matches the selected Shopify color. This is a file-specific preview, with original name/from-color/to-color/admin/time audit data; it creates no reusable color alias and does not approve, print or rename anything immediately. Existing extensions are preserved. Batch protection, folder scope and FRONT/BACK checks cannot be overridden. Different Shopify products sharing the exact same SKU trigger a warning; fix the catalog SKU conflict rather than relying on that code to distinguish designs.

## Returning a selected queued item

Artwork Verification cards and individual items on the batch detail page offer **Return to Artwork Review** only while their batch is unclaimed QUEUED. Every button targets one production line item. Whole-batch return controls have been removed, and a cached POST to the old whole-batch URL redirects with instructions without modifying anything.

Only the selected item loses its artwork selection and approval. Other items keep their complete original order/artwork/approval snapshots. To reject stale factory poll responses, the old batch becomes CANCELLED history and a queued successor contains only the retained items, with recalculated item/file/material counts from those original snapshots. No remaining order is pulled from Ready for Print and no handoff is authorized. Returning the last item creates no replacement. The returned item must be rematched, manually approved and explicitly batched again.

The old batch page labels cancelled contents as **history, not queued**, collapses its original contents, hides active print progress, and links to any successor batch. Active item indexes, factory polling and Ready for Print exclude returned/unapproved items. Return audit metadata records actor/time/exact line-item IDs and successor ID. Claimed, downloading, prepared, handed-off, completed and failed batches remain protected. Shared portal/factory write and row locks settle claim/return races; stale agents cannot claim the superseded batch. No migration or live queue mutation occurs during deployment.

## Missing suggestions and operator errors

Ambiguous artwork has no filename suggestions or copy controls, including for previously saved reviews. It displays the conflicting filenames and Drive links instead. Missing artwork retains standard filename suggestions. A rename convention never identifies the correct member of an ambiguous set.

Expected 409/422 artwork mapping errors redirect back to the saved preview with a clear message, rather than invoking the debug exception renderer. The invalid compiled exception-view cache observed locally was removed/rebuilt without changing framework source, credentials or database contents. Product-search empty states are compact and offer Clear search / Show all products; the search accepts Shopify product names or SKUs.

The matching dialog keeps its comparison/search and confirmation controls visible while a single file area scrolls. Short viewports retain a scroll fallback. The page header, status counts and scope summary are compact; detailed folder links remain collapsed.

## Reviewed choices and cross-page consistency

Admin confirmation persists exact file IDs and metadata fingerprints by normalized series, SKU design, product identity and variant color in private `artwork-selections.json`. It is not keyed by SKU alone, so different products sharing a SKU cannot inherit each other’s choice. FRONT/BACK are saved together. A manual choice may resolve duplicate or legacy filenames only after explicit visual review; folder, file type, side, color/explicit correction and current metadata checks still apply. Changes or missing chosen files invalidate the selection without falling back to another duplicate. App-controlled, verified content-preserving renames update the selection fingerprint.

Existing unbatched approvals are invalidated before persisting a changed choice, so a later Drive refresh failure cannot retain old print eligibility. Confirming refreshes unbatched orders immediately, with print approval still separate. Normal rematch and backlog sync use the same persisted choice. Subsequent Admin scans retain the choice and report found artwork. Selection changes invalidate older rename previews; old preview policy versions require a fresh check. This adds private JSON data, not a database migration. Back up the complete private artwork-names directory.
