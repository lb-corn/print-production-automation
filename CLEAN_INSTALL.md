# Lucky Bags Manufacturing — clean replacement

This package retains the September Laravel/Factory Agent functionality and repairs the shared Blade layouts and styling. It is **not** a verified reconstruction of the deleted October v2 workflow. Do not enable unattended printing before factory-PC testing.

## Install without losing local state
1. Back up the old project **outside** the target folder, including `.env`, `database/database.sqlite`, and the service-account JSON. Do not upload backups or secrets to GitHub.
2. Keep the old `.git` directory to preserve repository identity/history; replace application source files only. A new ZIP has no `.git`.
3. Restore `.env`, SQLite database and the **rotated** Google service-account file from your secure local backup.
4. Run `composer install --no-scripts` first, inspect Composer scripts and dependencies, then run `composer dump-autoload` and `php artisan package:discover` after review.
5. Create `storage/framework/{cache/data,sessions,views}`, `storage/logs`, and `bootstrap/cache`. Run `php artisan optimize:clear`, `php artisan migrate:status`, then `php artisan serve`.
6. Visit `/dashboard`; validate CSS, login, verification, batch views and API status. Avoid `migrate:fresh`.
7. Review `git status`, stage only intended source files, scan for secrets and suspicious scripts, then push a feature branch and merge through a PR.

## Factory print PC
- Configure the Windows agent separately using `automation/config.example.env` and `automation/README.md`.
- Use a unique `FACTORY_API_KEY` shared only between Laravel and the agent.
- Set `PRODUCTION_API` to the Laravel API URL reachable from the factory PC, not `127.0.0.1` unless Laravel runs on that same PC.
- Configure `GOOGLE_SERVICE_ACCOUNT_FILE`, `FACTORY_PRINT_DIR`, and `EDGE_PRINT_HOT_FOLDER` for that PC.
- Run `automation/run-once.ps1` for a controlled test before enabling `install-autostart.ps1`.
- Use the current automation/README.md and automation/SECURITY_REVIEW.md. Single-set hot-folder handoff requires signed confirmation; multi-set quantity/preset integration remains blocked pending a controlled factory test.

## Security
The previously shared Laravel APP_KEY, Shopify secret, and Google service-account private key should be considered exposed. Rotate credentials; changing APP_KEY can invalidate encrypted data/sessions, so plan that separately. Never commit `.env` or service-account files.
