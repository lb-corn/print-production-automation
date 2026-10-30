# Lucky Bags production deployment

This replaces the previous upload-only workflow with the same three-stage layout as the supplied DinaPotha reference: security, validation, then deployment. It runs only through **Actions → Lucky Bags Secure Production Deployment → Run workflow**. Only a run selected from `main` can enter the deployment job. Merging or pushing does not deploy the site automatically.

## Existing hosting paths

- The FTP account is already rooted at `/home2/antspixa/public_html/x_dev/luckyprodauto/prod`.
- Keep the upload at the FTP login root (`./`). Do not append `public/` and do not repeat the absolute host path.
- The domain/subdomain document root remains `public_html/x_dev/luckyprodauto/prod/public`.
- `local-dir: ./.deploy/` is a temporary allowlisted build directory. Its contents become `prod/app`, `prod/vendor`, `prod/public`, etc.; no `.deploy` prefix is created on the server.

The existing server `.env`, APP_KEY, database, Google credentials, runtime storage and host-specific `.htaccess` files are preserved. The factory-PC agent and operator documents are not part of the web-server upload.

## GitHub configuration

Keep the existing secret names, configured as repository secrets or in the `production` environment:

- `PROD_FTP_SERVER`: hostname matching the FTPS certificate, without a URL scheme.
- `PROD_FTP_USERNAME`: the existing account rooted at `prod`.
- `PROD_FTP_PASSWORD`: that account's password.

Configure the GitHub `production` environment with the desired reviewers and deployment branch restrictions. The only external action is GitHub-owned `actions/checkout`, pinned to a full commit SHA, with Git credentials not persisted. PHP and Composer come from Ubuntu 24.04 packages; policy checks, packaging and Python standard-library FTPS upload run inline in the workflow. No third-party actions or `.github/scripts` are required. The workflow uses explicit FTPS on port 21 with strict certificate validation; it will not fall back to plaintext FTP or bypass a certificate failure.

## What runs before upload

1. Reject tracked secrets, local database files, dependency directories, suspicious known files and symlinks.
2. Review a small set of high-risk code patterns and scan the source using updated ClamAV signatures.
3. Validate/install/audit locked Composer dependencies, bootstrap Laravel, validate routes and Blade, and run `php tests/bulk-review.php` in an in-memory database without live Shopify or Google credentials.
4. Build an allowlisted production package, audit production dependencies and run a second ClamAV scan over the actual package, including vendor files.
5. Upload using the existing account root. The uploader never deletes remote files. A distinct `.lb-production-python-ftps-state.json` inventory tracks SHA256 values to skip unchanged files without reusing the older uploader inventory.

These are defensive checks, not a malware-free guarantee. Do not hand-edit or remove deployed files while relying on the inventory. To force a complete re-upload after server-side repairs, remove only `.lb-production-python-ftps-state.json` using your FTP client. The old inventory and any legacy files already on the server are not automatically purged; inspect and remove obsolete files separately using a reviewed backup/recovery plan.

## Activate the uploaded release on the server

FTPS transfers files and cannot run migrations, set maintenance mode, create a database backup or refresh server caches. Do not treat a successful upload as completed application activation.

Before running the workflow, take and verify a database backup. For schema-changing releases, put the existing application into maintenance mode in cPanel Terminal/SSH before starting the upload:

```bash
cd /home2/antspixa/public_html/x_dev/luckyprodauto/prod
php artisan down --retry=60
```

After a successful upload, from that same directory run:

```bash
bash deployment/post-deploy.sh
```

Use the host's PHP 8.3+ CLI. If the default `php` command is older, specify the full cPanel binary:

```bash
PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php bash deployment/post-deploy.sh
```

The script preserves `.env` and APP_KEY, creates required Laravel runtime directories, applies additive migrations, refreshes config/views, and exits maintenance mode only after success. It does not seed users or regenerate keys. On failure it leaves the application down so you can inspect the error; restore a verified backup when necessary. Never use `migrate:fresh` or run the demo seeder on production.

FTPS upload is not an atomic or zero-downtime release mechanism. Existing database/storage permissions and the domain's `public/` document root must already be configured correctly. The workflow does not install or update anything on the Print PC.

## After activation

Check login, dashboard, artwork verification, Ready for Print, the batch list and a batch detail page with its confirmation history. Confirm the Factory API still rejects unauthenticated requests and that the existing factory configuration uses the correct HTTPS endpoint. Do not trigger an actual print job merely to test the upload.

### FTPS certificate and FTP account directory

Use a hostname matching the hosting server FTPS certificate for `PROD_FTP_SERVER`. If the connection must use an IP, set `PROD_FTP_TLS_SERVER_NAME` to the certificate hostname; TLS verification remains required for both control and data connections. Get the hostname from cPanel Configure FTP Client or HostGator support.

The FTP account home must be `/home2/antspixa/public_html/x_dev/luckyprodauto/prod`, not `prod/public`. The website document root remains `prod/public`. The uploader refuses a root containing `index.php` to prevent accidentally publishing the full Laravel application inside the public directory.

Security and Laravel validation run in parallel. PHP installation is skipped when the required runner packages already exist, and uploads skip unchanged files and reuse directory lookups. Malware and dependency checks remain enabled.

The configured IP `192.185.52.143` presents a certificate covering `hostgator.com`; the workflow uses that verified TLS identity for this specific IP. `PROD_FTP_TLS_SERVER_NAME` can override it if HostGator changes the certificate. No certificate verification is disabled.
