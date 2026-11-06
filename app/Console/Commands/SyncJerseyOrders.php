<?php

namespace App\Console\Commands;

use App\Services\Shopify\JerseyOrderSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncJerseyOrders extends Command
{
    protected $signature = 'shopify:sync-jerseys
        {--full : Sync all accessible Shopify order history}
        {--since= : Sync orders created or updated after this ISO or parseable date}
        {--limit= : Limit the number of Shopify order pages fetched}
        {--source=command : Internal sync source: command, scheduled, or manual}';

    protected $description = 'Sync Jersey and Hoodie order line items from Shopify into the local database.';

    public function handle(JerseyOrderSyncService $sync): int
    {
        try {
            $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

            $stats = $sync->sync(
                full: (bool) $this->option('full'),
                since: $this->option('since') ? (string) $this->option('since') : null,
                limit: $limit,
                source: (string) $this->option('source'),
            );

            $this->info('Jersey sync complete.');
            $this->line('Pages fetched: '.$stats['pages_fetched']);
            $this->line('Shopify orders scanned: '.$stats['shopify_orders_scanned']);
            $this->line('Jersey line items matched: '.$stats['jersey_line_items_matched']);
            $this->line('Inserted: '.$stats['inserted']);
            $this->line('Updated: '.$stats['updated']);
            $this->line('Skipped: '.$stats['skipped']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Jersey sync failed. Check application logs for details.');

            return self::FAILURE;
        }
    }
}
