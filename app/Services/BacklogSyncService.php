<?php

namespace App\Services;

use App\Models\ProductionItem;
use App\Models\ProductionSync;
use Illuminate\Support\Facades\DB;

class BacklogSyncService
{
    public function __construct(
        private ShopifyService $shopify,
        private GoogleDriveService $drive,
        private ArtworkMatcherService $matcher
    ) {}

    public function sync(
        string $mode = 'unfulfilled',
        ?int $userId = null
    ): array {
        $b = $this->shopify->getProductionBacklog($mode);
        $index = $this->drive->artworkIndex();

        $added = $updated = $unchanged = $matched = $missing = $quantityDeltas = 0;

        DB::transaction(function () use (
            $b,
            $index,
            &$added,
            &$updated,
            &$unchanged,
            &$matched,
            &$missing,
            &$quantityDeltas
        ) {
            foreach ($b['items'] as $r) {
                $item = ProductionItem::where('line_item_id', $r['line_item_id'])
                    ->lockForUpdate()
                    ->first();

                $quantity = (int) $r['quantity'];

                $shopifyFields = [
                    'shopify_order_id' => $r['order_id'],
                    'order_number' => $r['order_number'],
                    'order_date' => $r['order_date'],
                    'customer' => $r['customer'],
                    'product' => $r['product'],
                    'product_type' => $r['product_type'],
                    'variant' => $r['variant'],
                    'sku' => $r['sku'],
                    'quantity' => $quantity,
                    'shopify_image' => $r['shopify_image'],
                    'financial_status' => $r['financial_status'],
                    'fulfillment_status' => $r['fulfillment_status'],
                    'last_synced_at' => now(),
                ];

                if ($item) {
                    $previous = (int) ($item->last_synced_quantity ?? $item->quantity);
                    $delta = $quantity - $previous;

                    $item->fill($shopifyFields + [
                        'last_synced_quantity' => $quantity,
                        'quantity_delta' => $delta,
                    ]);

                    if ($delta !== 0) {
                        $quantityDeltas++;
                    }

                    if ($item->isDirty()) {
                        $item->save();
                        $updated++;
                    } else {
                        $unchanged++;
                    }

                    continue;
                }

                $match = $this->matcher->match($r, $index);

                ProductionItem::create([
                    'line_item_id' => $r['line_item_id'],
                    ...$shopifyFields,
                    'last_synced_quantity' => $quantity,
                    'quantity_delta' => 0,
                    ...$match,
                ]);

                $added++;
                count($match['matches']) ? $matched++ : $missing++;
            }
        });

        $result = [
            'mode' => $mode,
            'shopify_items' => $b['item_count'],
            'orders_examined' => $b['order_count'],
            'added' => $added,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'matched' => $matched,
            'missing' => $missing,
            'quantity_deltas' => $quantityDeltas,
            'drive_files' => count($index),
        ];

        ProductionSync::create($result + ['created_by' => $userId]);

        return $result;
    }

    public function recordFailure(
        string $mode,
        string $message,
        ?int $userId = null
    ): void {
        ProductionSync::create([
            'mode' => $mode,
            'error' => $message,
            'created_by' => $userId,
        ]);
    }
}
