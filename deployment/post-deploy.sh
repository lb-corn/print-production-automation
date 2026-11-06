#!/usr/bin/env bash
# Run on the hosting server from the existing prod application directory after uploading.
set -euo pipefail
PHP_BIN="${PHP_BIN:-php}"
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
test -f artisan && test -f .env && test -f vendor/autoload.php || {
  echo 'Existing Laravel application, .env and vendor dependencies are required.' >&2
  exit 1
}
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || {
  echo 'Use the hosting PHP 8.3+ CLI binary for this release.' >&2
  exit 1
}
echo 'Ensure a verified database backup exists before continuing this deployment.'
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
"$PHP_BIN" artisan down --retry=60
trap 'echo "Activation failed. Application remains in maintenance mode; inspect the error and restore from your backup if necessary." >&2' ERR
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!app(App\Services\FactoryDispatchService::class)->consentReady()) { fwrite(STDERR,"Print queue setup is incomplete. Ensure the latest database migrations were uploaded and applied to this application database.\n"); exit(1); } if (!App\Services\CustomArtworkService::ready()) { fwrite(STDERR,"Custom artwork setup is incomplete. Upload and apply migration 000009.\n"); exit(1); } if (!Illuminate\Support\Facades\Schema::hasColumn("production_items","shopify_active")) { fwrite(STDERR,"Shopify backlog setup is incomplete. Upload and apply migration 000010.\n"); exit(1); } if (!Illuminate\Support\Facades\Schema::hasTable("roles") || !Illuminate\Support\Facades\Schema::hasTable("jersey_orders")) { fwrite(STDERR,"Role and apparel database setup is incomplete. Upload and apply the latest migrations.\n"); exit(1); } echo "Production database setup verified.\n";'
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan up
trap - ERR
echo 'Server activation completed. Check login, dashboard, Ready for Print and a batch detail page.'
