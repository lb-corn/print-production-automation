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
        $shop = $this->shopify->shopDomain();
        $knownItems = ProductionItem::query()
            ->whereNotNull('shopify_order_id')
            ->where(function ($query) use ($shop) {
                $query->whereNull('shopify_shop')
                    ->orWhere('shopify_shop', $shop);
            })
            ->get(['shopify_order_id', 'line_item_id']);

        $b = $this->shopify->getProductionReconciliation(
            $mode,
            $knownItems->pluck('shopify_order_id')->filter()->unique()->values()->all(),
            $knownItems->pluck('line_item_id')->filter()->unique()->values()->all()
        );
        $index = $this->drive->artworkIndex();

        $added = $updated = $unchanged = $matched = $missing = $quantityDeltas = $inactive = $conflicts = 0;

        DB::transaction(function () use (
            $b,
            $index,
            &$added,
            &$updated,
            &$unchanged,
            &$matched,
            &$missing,
            &$quantityDeltas,
            &$inactive,
            &$conflicts
        ) {
            foreach ($b['items'] as $r) {
                $item = ProductionItem::where('shopify_shop', $r['shopify_shop'] ?? null)
                    ->where('shopify_order_id', $r['order_id'])
                    ->where('line_item_id', $r['line_item_id'])
                    ->lockForUpdate()
                    ->first()
                    ?: ProductionItem::where('line_item_id', $r['line_item_id'])
                        ->lockForUpdate()
                        ->first();

                $quantity = (int) $r['quantity'];
                $isActive = (bool) ($r['is_active'] ?? true);
                $inactiveReason = $isActive ? null : ($r['inactive_reason'] ?? 'Not currently eligible in Shopify');

                $shopifyFields = [
                    'shopify_shop' => $r['shopify_shop'] ?? null,
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
                    'last_shopify_seen_at' => now(),
                    'is_active' => $isActive,
                    'inactive_reason' => $inactiveReason,
                    'inactivated_at' => $isActive ? null : now(),
                ];

                if ($item) {
                    $previous = (int) ($item->last_synced_quantity ?? $item->quantity);
                    $delta = $quantity - $previous;
                    $conflict = $this->conflictReason($item, $quantity, $inactiveReason);

                    $item->fill($shopifyFields + [
                        'last_synced_quantity' => $quantity,
                        'quantity_delta' => $delta,
                        'sync_conflict' => $conflict,
                    ]);

                    if ($delta !== 0) {
                        $quantityDeltas++;
                    }

                    if (!$isActive) {
                        $inactive++;
                    }

                    if ($conflict !== null) {
                        $conflicts++;
                    }

                    if ($item->isDirty()) {
                        $item->save();
                        $updated++;
                    } else {
                        $unchanged++;
                    }

                    continue;
                }

                if (!($r['mode_included'] ?? true) || !$isActive) {
                    continue;
                }

                $match = $this->matcher->match($r, $index);

                ProductionItem::create([
                    'line_item_id' => $r['line_item_id'],
                    ...$shopifyFields,
                    'last_synced_quantity' => $quantity,
                    'quantity_delta' => 0,
                    'is_active' => true,
                    'last_shopify_seen_at' => now(),
                    'sync_conflict' => null,
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
            'inactive' => $inactive,
            'conflicts' => $conflicts,
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

    private function conflictReason(ProductionItem $item, int $shopifyOutstanding, ?string $inactiveReason): ?string
    {
        $activeAllocated = (int) $item->batchItems()
            ->whereHas('batch', function ($query) {
                $query->where('type', 'normal')
                    ->whereNotIn('status', ['PRINTED', 'FAILED']);
            })
            ->sum('quantity');

        if ($activeAllocated <= 0) {
            return null;
        }

        if ($inactiveReason !== null) {
            return $inactiveReason.' while '.$activeAllocated.' set(s) are allocated to an active batch.';
        }

        if ($shopifyOutstanding < $activeAllocated) {
            return 'Shopify outstanding quantity '.$shopifyOutstanding.' is below active batch allocation '.$activeAllocated.'.';
        }

        return null;
    }
}
