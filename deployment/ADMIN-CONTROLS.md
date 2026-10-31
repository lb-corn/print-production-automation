# Admin controls review

Branch: `codex/admin-users-and-reset`. Do not merge or deploy before review.

Sign in as an existing admin, open Account → Users, and choose Create user. Normal users retain the production workflow but cannot access either admin screen, including by direct URL. Use Edit to change name, email, access, or password. A blank password retains the current one. Passwords require 12 characters and confirmation. An admin cannot demote their own account.

For Mike, choose Create Mike’s account on Users. This prefills Mike, mike@luckycornhole.com, and Normal user. Choose and securely share a password during review; no real Mike account or default password is seeded by this branch. Mike records in automated tests exist only in an in-memory database.

Account → Reset portal data shows counts of records to remove. Back up the database and pause the factory agent first. The form requires the current admin password, an acknowledgement that factory work is paused, and the exact phrase RESET PORTAL. The reset transaction deletes batch confirmations, batches, artwork decisions, and production items, in foreign-key order. Users, migration history, environment credentials, source files, external Shopify/Drive data, sessions, and server logs are preserved. This screen does not delete remote factory-PC spool files or undo physical prints. A fresh import may make previously printed orders eligible again.

On the existing single HostGator server, all authenticated portal and factory API writes share an OS file lock. Concurrent writes return 409 instead of overlapping a reset or long-running sync. If hosting is later split across servers or a separate CLI importer is introduced, those processes must use an equivalent shared lock. Reads can continue during updates.

After resetting, use Overview’s Shopify backlog refresh to import again. No automatic import or production reset runs during deployment.

Validation: `php tests/admin-controls.php`, `php tests/bulk-review.php`, `php artisan view:cache`, and `php artisan route:list --path=admin`. The reset tests use only an isolated in-memory database.

## User status and deletion

Users shows Active/Inactive status and Activate/Deactivate and Delete actions for normal users. Admin accounts cannot be deactivated or deleted through these actions. Inactive users cannot sign in, and existing sessions are rejected on their next request. Deleting a user requires the acting admin password and the target email; production records and signed account names remain.

## Replace the initial account roster

After deploying this version, run from the production application directory:

```bash
php artisan migrate --force
php artisan production:replace-users manojpoosalingam@gmail.com mike@luckycornhole.com
```

Confirm the two-account replacement, then enter and confirm the Admin password and Mike password at the hidden prompts. This transaction leaves exactly these two active accounts and deletes all other users. It preserves production history and migration records. Passwords are hashed and never stored in repository files or supplied as shell arguments. Do not run the demo DatabaseSeeder afterward. New users can be created through the production Admin UI.
