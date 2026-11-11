<?php
namespace App\Console\Commands;

use App\Services\OrderSyncService;
use Illuminate\Console\Command;

class SyncProductionOrders extends Command
{
    protected $signature = 'shopify:sync-orders {--source=command : command, scheduled, or manual}';
    protected $description = 'Sync bag backlog/artwork and incremental apparel orders together.';

    public function handle(OrderSyncService $sync): int
    {
        // Coordinate scheduled imports with the same file lock used by portal writes.
        $handle = fopen(storage_path('framework/portal-write.lock'), 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) fclose($handle);
            $this->warn('Another portal update is running. Sync will retry at the next scheduled run.');
            return self::FAILURE;
        }
        try {
            $run = $sync->syncPartners((string)$this->option('source'));
            foreach($run['partners'] as $partner=>$result)foreach(['bags','apparel'] as $area)$this->line(ucfirst($partner).' '.ucfirst($area).': '.$result['areas'][$area]['state']);
            return $run['state'] === 'success' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Order sync failed to start. Check application logs.');
            return self::FAILURE;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
