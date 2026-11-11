# Lucky and Vibe setup

The existing bag and apparel workflows run separately for Lucky and Vibe. Select the partner above the page content. The common Overview sync processes every partner and production area the signed-in user can sync. The scheduled `shopify:sync-orders` command processes both configured shops every ten minutes. The server still needs its normal Laravel scheduler installed.

## Configuration

Keep credentials in the environment, never in source control:

```dotenv
LB_SHOP=h0yf1i-yt.myshopify.com
LB_CLIENT_ID=
LB_CLIENT_SECRET=
LB_API_VERSION=2026-07
VIBE_SHOP=vibe-bag-co.myshopify.com
VIBE_CLIENT_ID=
VIBE_CLIENT_SECRET=
VIBE_API_VERSION=2026-07
GOOGLE_SERVICE_ACCOUNT_FILE=service-account.json
GOOGLE_DRIVE_FACTORY_ROOT_ID=1HCPXfG2xPQ1_ckXYg5e3KSEz70qcZbJN
MATERIALS_SHEET_ID=1VKbVpQWHvwWIFQJXPTZsFq_KpyLshWTDLyZKpFBLmBQ
MATERIALS_SHEET_TAB=Lucky
MATERIALS_LUCKY_TAB=Lucky
MATERIALS_VIBE_TAB=Vibe
MATERIALS_AUTHENTICATED=true
```

`MATERIALS_SHEET_TAB` is the legacy fallback for Lucky. Vibe reads its own tab. Bag codes, names, materials, print type, template sizes and allowed backing colors come from the sheet. Vibe's Premiere spelling and nine bag codes are now readable.

Default bag product types are `ACL2026|ACL2027|ACL2028` for Lucky and `Vibe Bags` for Vibe. Override with `LB_BAG_PRODUCT_TYPES` / `VIBE_BAG_PRODUCT_TYPES` if Shopify changes. Vibe's artwork catalog collection defaults to `All Bags` (`VIBE_ARTWORK_COLLECTION`). Apparel uses the existing jersey/hoodie classification; unrelated merchandise is excluded.

The single factory root resolves the direct `LuckyBags` and `Vibe` child folders. Separate artwork-root environment values are unnecessary. Folder names can be overridden with `LB_DRIVE_FOLDER` and `VIBE_DRIVE_FOLDER` when renamed. Missing or duplicate partner folders stop matching rather than falling back to another partner. Legacy separate root values remain supported only when the factory root is unset.

## Deployment and validation

1. Set both shops' environment values, roots and sheet tabs on the target server. The existing Google service-account file is reusable when it has access to the new files.
2. Run `php artisan migrate --force` and refresh Laravel's configuration cache using the deployment procedure. The additive migration assigns existing records to Lucky and preserves approvals and batches.
3. Review Admin roles. Existing roles receive access to both partners; remove Lucky or Vibe access to restrict a role. Area and action permissions continue to apply.
4. Sync from Overview. Confirm the partner result indicators, then switch partners and check both bag verification and apparel orders. Importing does not approve or print orders.
5. For a bag with available originals, verify artwork and backing color, approve it, and inspect Ready for Print. Mike's label order stays material first: `RAVE9 LB4303 …` or `RAVE8 VB4303 …`. New batches and standardized filenames use LB/VB prefixes. Existing locked batches retain their stored payloads.

## Files and product naming to finish manually

Both artwork roots use the existing allowed manufacturing group folders, with direct series folders. Originals are searched recursively inside each series, including Print and its subfolders. Mockup/preview/archive branches and Drive shortcuts are excluded. Use Admin matching/naming after uploading originals; no remote folder, filename or SKU was changed by the integration.

Read-only audit on November 11, 2026:

- Lucky: all ten expected series folders are visible. The service account lists four originals in Luciano and no children in the other nine series folders. Folder visibility is confirmed; file availability/sharing needs checking separately. If your own Drive account sees files there, share those originals with the service account and recheck.
- Vibe: the root currently exposes Backside Scan Materials, including Macarena scans, but no manufacturing artwork groups/originals. Add the intended folders and originals, then rematch.
- Ensure every Vibe variant SKU contains its actual sheet series code, for example `VB-BEL-…-001` for Believe and `VB-PRE-…-001` for Premiere. Some catalog variants were observed using another series' code; correct those in Shopify before relying on matching. Missing or ambiguous artwork stays in review.
- Backing labels use the exact allowed color name from the selected partner's sheet. Where scans are unavailable or comparison is uncertain, choose the sheet color in verification and approve once; the app does not invent color names or approve automatically.

Local validation imported two active Vibe bag lines and twelve Vibe apparel lines. Both bag lines remain unapproved with missing originals. No print jobs were submitted.
