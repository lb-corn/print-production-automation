# Configurable user roles

Branch: `codex/user-role-updates`. Do not merge until local acceptance is complete.

## Names and defaults

Admin retains unrestricted access and is not editable as a configurable role. Existing Operator accounts migrate to Manager, with access to both production areas and no administration permissions. Bag Production and Apparel Production are starting templates that administrators can customize. Demo retains its current bag-only, read-only behavior. Each user has one assigned role; a custom role may combine permissions across production areas, including approval without editing.

## Permission enforcement

`AccessCatalog` defines the supported permissions. `RouteAccess` maps every authenticated feature route and settings section. `RequireFeature` denies unmapped routes and unauthorized direct requests before controllers run. HTML denials render the shared theme with an Access restricted message, administrator guidance, and a safe Overview link; JSON requests receive HTTP 403. Personal profile, documentation and logout remain available. Accounts with no production overview permission land on a neutral workspace.

Editing and approval grant the matching feature view and area overview. Administrative privileges can be delegated explicitly. User management and role assignment are distinct: assigning a role requires role management permission. Only Admin may create or modify Admin accounts. Delegated role administrators cannot grant permissions they lack, edit a role with broader permissions, or edit their own role. Admin accounts cannot be deleted or deactivated, and self-demotion remains blocked. Manager is configurable; Demo is protected read only.

Role creation, role updates and user-role assignment record actor, before/after access and timestamp in `access_audits` transactionally. Passwords are excluded. Role identifiers remain stable when display names change. Revocations apply when the next request resolves the account and its role. Existing requests already running are not cancelled.

## Navigation and shared theme

Role management is inside Admin Settings, immediately before Manage Users. It is not a separate workspace sidebar entry.

Overview offers Bag Production and Apparel Production area links according to permission. Each area has a collapsible navigation group. The internal UI theme lives under `packages/ui-theme`; both existing layout entry points render its single shared Blade layout. Assets and shared partials continue using the existing application stylesheet and components. This is an internal view package, not a standalone Composer-distributed design system.

## Apparel phase boundary

The jersey-orders repository was reviewed read only. Its orders, tracking, remarks, processed-remarks and sync routes informed the future apparel permissions. The apparel overview currently states that integration is planned: it does not import orders or display invented metrics. Actual feature integration and UI adaptation follow local acceptance on a separate branch. Future apparel endpoints must join the shared authenticated feature middleware and map to the reserved permissions.

## Local validation

Run `php tests/role-permissions.php` and `php tests/admin-controls.php`. These create isolated in-memory SQLite databases. The permission test checks every authenticated route mapping, area isolation, cross-area approval, privilege escalation, permission revocation, legacy account migration, themed HTML and JSON denial responses, and shared view rendering.

The temporary local preview at `http://127.0.0.1:8091` uses `tmp/roles-preview.sqlite`, synthetic accounts and the material fixture. Storage is isolated under `tmp/roles-preview-storage`; live Shopify, Google and factory credentials are disabled in this preview. It is separate from the configured application database. Accounts: `admin@preview.test`, `manager@preview.test`, `bag-production@preview.test`, `apparel-production@preview.test`, `demo@preview.test`. Their shared random test password is in `tmp/roles-preview-credentials.txt`. Preview files are ignored by Git. Restart with `php -S 127.0.0.1:8091 -t public tmp/roles-preview-router.php` if needed. Never deploy this temporary router.

The configured local SQLite database was backed up, then the Demo mapping and configurable-roles migrations were applied to fix the missing roles table on port 8000. Local Admin remains Admin and Mike is Manager. Existing user identities, passwords, active flags and production record counts were verified against the backup. Production has not been changed; apply migrations there only after the branch is approved and deployed. The migration seeds roles and preserves user credentials. Its rollback refuses to widen custom or production-role accounts to the old unrestricted Operator role: reassign those users deliberately first.

Acceptance: verify Admin sees both areas and administration, Manager sees both production areas, single-area users see only their area, and Demo cannot write. Create a custom bag reviewer with an apparel approval permission; verify view access is granted without apparel edit access. Remove a permission during an active session and confirm its next direct request shows the themed 403 page. No merge or apparel data migration is authorized by completing these checks alone.

## Production activation after approval

After a verified database backup and deployment of this branch, run `php artisan migrate --force`, then `php artisan optimize:clear`. The existing `deployment/post-deploy.sh` already applies migrations during activation. Existing Admin accounts remain Admin; Operator accounts become Manager; the known `demo@lbprint.com` account maps to Demo through the existing Demo migration. Intended assignments: Manoj = Admin, Mike = Manager, Demo = Demo. Manoj and Demo were absent locally, so their production account records still require verification; this development session has not accessed production or elevated users by name. Do not run the roster replacement command to apply these role changes: it is a separate destructive account-management operation.
