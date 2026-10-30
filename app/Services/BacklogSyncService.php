<?php
namespace App\Services;

use App\Models\ProductionItem;
use Illuminate\Support\Facades\DB;

class BacklogSyncService
{
    public function __construct(private ShopifyService $shopify, private GoogleDriveService $drive, private ArtworkMatcherService $matcher) {}

    public function sync(): array
    {
        $backlog = $this->shopify->getProductionBacklog();
        $processed = app(ProductionService::class)->batchIndex(true);
        // Existing artwork decisions and locked batch contents are retained.
        $known = ProductionItem::pluck('line_item_id')->flip();
        $hasNew = collect($backlog['items'])->contains(fn($row) => !$known->has($row['line_item_id']) && !isset($processed[$row['line_item_id']]));
        $index = $hasNew ? $this->drive->artworkIndex() : [];
        $added = $updated = $unchanged = $matched = $missing = $skipped = 0;

        DB::transaction(function () use ($backlog, $index, &$added, &$updated, &$unchanged, &$matched, &$missing, &$skipped) {
            foreach ($backlog['items'] as $row) {
                $item = ProductionItem::where('line_item_id', $row['line_item_id'])->lockForUpdate()->first();
                // Refresh inside the transaction after obtaining the item lock.
                $processed = app(ProductionService::class)->batchIndex(true);
                if (isset($processed[$row['line_item_id']])) { $skipped++; continue; }
                $fields = array_intersect_key($row, array_flip(['order_number','product','variant','sku','quantity','shopify_image']));
                if ($item) {
                    $item->fill($fields);
                    if ($item->isDirty()) { $item->save(); $updated++; }
                    else { $unchanged++; }
                    continue;
                }
                $match = $this->matcher->match($row, $index);
                ProductionItem::create(['line_item_id'=>$row['line_item_id'], ...$fields, ...$match]);
                $added++;
                count($match['matches']) ? $matched++ : $missing++;
            }
        });

        return ['shopify_items'=>$backlog['item_count'], 'orders_examined'=>$backlog['order_count'], 'added'=>$added,
            'updated'=>$updated, 'unchanged'=>$unchanged, 'matched'=>$matched, 'missing'=>$missing,
            'processed_skipped'=>$skipped, 'drive_files'=>count($index)];
    }
}
