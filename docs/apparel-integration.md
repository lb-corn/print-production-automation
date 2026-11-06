# Apparel integration

Branch: codex/apparel-production-integration, based on local main bbd49d3. Source: D:/LuckyBags/JerseyOrders/jersey-orders (read only).

Ports the original JerseyOrder model, normalization and Shopify services, fulfillment enum, report filters, ordering, pagination, local tracking, remarks, processed remarks, detail viewer, sync health, manual/CLI sync and ten-minute schedule. The source login, user seeders, environment files, framework dependencies and database were not copied. Existing application accounts and credentials remain authoritative. No live Shopify import or production deployment was performed.

UI uses the shared application layout, scoped report CSS with app theme colors, and the original detail-view JavaScript. Tracking, remark editing and remark processing are independent permissions. Existing reserved combined apparel edit permissions are expanded without granting access to additional users. Editing a remark clears its processed flag as in the source app. Tracking remains local and fulfilled/cancelled tracking is locked.

## Routes and permissions

`GET /apparel/orders` and `/apparel/orders/{jerseyOrder}` require apparel.orders.view. Tracking PATCH requires apparel.tracking.edit; remark PATCH requires apparel.remarks.edit; processed-remark PATCH requires apparel.remarks.approve; manual sync POST requires apparel.sync.edit. Approval-only users receive order-view access automatically through the role editor, without tracking or remark editing. Admin retains full access. Existing Manager and Apparel Production roles retain equivalent apparel capabilities through migration 000013. Demo and Bag Production receive no apparel privileges unless an administrator explicitly assigns a custom role (Demo remains permanently read only).

## Validation and local data

`php tests/apparel-integration.php` runs isolated in-memory fixtures and fake HTTP responses. It covers normalization, Jersey/Hoodie filtering, excluded fees/bags, repeated upserts, preservation of all local fields, independent permissions, fulfilled/cancelled tracking locks, return URL safety, report filters, numeric sorting, pagination, detail references, personalization privacy, overview totals, scheduler configuration, locking, and permission migration. `tests/role-permissions.php` additionally covers every authenticated route mapping.

The configured local database was backed up before applying migrations 000012 and 000013. Existing users and production record counts were compared to the backup and preserved. Only the isolated preview database contains the three synthetic apparel examples; the configured application database starts with zero imported apparel records. Use the preview at http://127.0.0.1:8091/apparel/orders for a populated screen.

## Deployment after approval

This branch has not been merged into main or deployed. After approval, deployment requires the normal database backup and `php artisan migrate --force`, then clearing/rebuilding application caches. No new Composer dependencies or login seeders are required. Keep the existing Shopify environment configuration. Run the initial history import with `php artisan shopify:sync-jerseys --full` only when live Shopify import is authorized. Subsequent manual syncs and scheduled runs are incremental, with a shared sync lock. The scheduler requires the normal server cron entry `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`; do not add a duplicate entry if the application already has one. All tracking and remark changes remain local; no fulfillment/customer-notification writes are made to Shopify.

Source behavior intentionally adapted: the existing shared login replaces the source's separate accounts; source fixed role names become granular permissions; routes are namespaced under /apparel; the detail report retains its behavior inside the application theme; users with both edit and process permissions can do both, rather than hiding processing behind the source's mutually exclusive role condition. No source customer records or credentials were copied.

The source sync-health comparison was corrected to report failures even when failure and last success occur within the same second; a successful sync still clears the prior error. The report filter layout is responsive to the available app workspace rather than assuming a standalone full-width page.

## Responsive UI review

The duplicate Apparel overview sidebar link was removed; Overview tabs remain permission-based. The order report now has a structured filter grid, explicit results heading, aligned tracking/actions, read-only tracking for locked records, and full product names in phone cards. Bag-only factory and production-status widgets are hidden on apparel screens. Browser checks at desktop width, 390 px and 320 px verified no horizontal page overflow; the mobile details dialog was checked as well. The standard browser viewport was restored afterward. The role suite explicitly tests tab visibility for Manager, Bag Production, Apparel Production and Demo.

Deployment follow-up: shared theme packaging and database-independent view fixtures were published separately to main (00d8929 includes both fixes). Apparel UI changes remain on this integration branch and are not deployed to production by that main update.
