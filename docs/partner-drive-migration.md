# Partner Drive migration

Working branch: `codex/2026-11-10-partner-drive-migration`. Current production source settings remain unchanged.

Verified on 2026-11-10: the existing app/factory service account can read `1_PRINT_FACTORY`, its LuckyBags and Vibe children, and the Bag Material List spreadsheet in Utilities. Google Sheets API was enabled and both tab ranges were successfully read through the existing credentials. No replacement service-account key is needed.

## Lucky configuration when activating this branch locally

The current artwork reader expects the manufacturing groups directly below its configured root. Use the LuckyBags child folder while migrating Lucky; do not use the factory parent as the legacy artwork root.

```dotenv
GOOGLE_DRIVE_ARTWORK_ROOT_ID=1Jl1fACb76S7TQUKm4NK_tMDhLZDbApIJ
GOOGLE_SERVICE_ACCOUNT_FILE=service-account.json
MATERIALS_SHEET_ID=1VKbVpQWHvwWIFQJXPTZsFq_KpyLshWTDLyZKpFBLmBQ
MATERIALS_SHEET_TAB=Lucky
MATERIALS_LUCKY_TAB=Lucky
MATERIALS_VIBE_TAB=Vibe
MATERIALS_AUTHENTICATED=true
```

Use `php artisan config:clear` after changing local settings. Hosted deployment uses its existing activation procedure to rebuild configuration. Change hosted settings only when deploying the migration branch. Factory downloads use pinned file IDs and the existing credential file; they do not need a manufacturing root setting. Existing batches and approvals require comparison with the new source file IDs before rematching or queuing.

The authenticated sheet reader reads the selected tab through Google Sheets API with read-only access. It does not require public workbook sharing. Material parsing recognizes the bag-table headings beneath a title and separately reads the backing-material color section. The Lucky runtime tab remains selected explicitly; reading Vibe configuration must not replace Lucky defaults.

## Source issues to resolve before enabling Vibe

- Vibe Bag Code cells B3:B11 are empty. Fill stable codes consistent with product SKUs; do not derive them from display names or reuse Lucky mappings.
- Vibe currently has only Backside Scan Materials, not artwork manufacturing groups.
- Lucky backing folder name has been verified as `Viewmont`, matching the sheet. No alias mapping is needed.
- Artwork folder names can differ from sheet bag names/codes (for example `SFE`). Keep any required folder-to-series mapping in editable configuration rather than adding partner-specific names to code.

The spreadsheet currently contains materials and backing colors, but no partner folder-ID registry. Before the shared factory parent becomes the runtime root, agree an explicit registry for partner roots, artwork groups and backing folders. Keep these identifiers in editable configuration. Shopify connections, record ownership, filters, permissions and batch isolation must be partner-scoped before Vibe imports are enabled. Allstar stays inactive until configured.

## Backing-color verification

The local branch now supports a required, editable backing-color dropdown for single-sided bags. Choices are read from the selected tab's Single Side Back Material Configuration table. General Shopify/design colors are never substituted for backing colors. The existing Approve action saves the exact selected sheet color and artwork decision atomically; there is no separate confirmation button or checkbox. The confirmation records account, time, material, color and source/settings context; queued batches lock that snapshot. Changed product image URL or material/color rules invalidate readiness/approval. Approved backing controls are locked until Return to review removes the approval from Ready for Print; queued jobs use the existing guarded batch-return action. Existing locked batches stay unchanged.

Automatic comparison uses GD (PHP extension required). It reads the original website product image from configured Shopify CDN hosts and the allowed material's reference scans into bounded memory. It isolates a likely plain backside among two separate bag shapes, compares perceptual color profiles, and suggests a match only if it is sufficiently distinct. These distance thresholds are heuristic, not calibrated confidence scores. Missing swatches, uncertain segmentation, close colors, download failures or unsupported hosts leave the sheet-only dropdown available for manual selection. Suggestions never approve themselves. Image bytes are not stored, cached or served by the app; only numeric reference profiles/results and Drive metadata are cached briefly.

Local activation has already run the additive migration `2026_11_10_000014_add_backing_color_confirmation.php`. Deploy this migration before enabling the feature on the server. No AI/API key or new paid service is required.

```dotenv
BACKING_COLOR_MATCHING_ENABLED=true
BACKING_SWATCH_FOLDER_NAME="Backside Scan Materials"
BACKING_SWATCH_FOLDER_MAP='{}'
BACKING_PRODUCT_IMAGE_HOSTS=cdn.shopify.com
```

The backing folder has been renamed to Viewmont and matches the sheet exactly. The local folder map is empty. An optional mapping can be supplied if names differ later; mapped folders must still be direct children of the current partner’s backing-swatch folder. Future partners need their own scoped configuration before enabling this feature.

The live read-only Duck Camo comparison suggested Latte against the allowed Viewmont reference scans. No live order confirmation or approval was saved by this check.

The compact single-sided review uses three columns: website image, print artwork and backing selector/swatches. The original website image is shown once. Suggestions preselect the sheet-only dropdown; operators may change it before clicking the existing Approve button. Optional swatch tiles and retry remain in the backing column. For custom files, the selected backing color is staged when uploading so the PC can build its labeled preview; the existing custom artwork approval still controls readiness, and changing a staged color requires a replacement labeled upload.
