<?php

namespace App\Services;

use App\Models\PrintBatch;
use App\Models\PrintBatchItem;
use App\Models\PrintHistory;
use App\Models\ProductionItem;
use App\Models\VerificationDecision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public const YARDS = .143;

    public const MATERIALS = [
        'SUP' => ['Rave 9', 'Supreme 5'],
        'LUC' => ['Rave 8', 'Anchorage 5'],
        'CLV' => ['Rave 9', 'Lucky 5'],
        'ELT-S' => ['Rave 9', 'Viewmont 6'],
        'PSE' => ['Rave 9', 'Viewmont 6'],
        'SRF-ELT' => ['Rave 8', 'Malley 5'],
        'SRF' => ['Rave 8', 'Embry 5'],
        'CLT' => ['Rave 8', 'Rave 9'],
        'SNP' => ['Turbo 7', 'Rave 9'],
        'PSN' => ['Turbo 6', 'Rave 9'],
    ];

    public const FACTORY_TRANSITIONS = [
        'QUEUED' => ['CLAIMED', 'FAILED'],
        'CLAIMED' => ['DOWNLOADING', 'FAILED'],
        'DOWNLOADING' => ['PREPARED', 'FAILED'],
        'PREPARED' => ['HANDOFF_REQUESTED', 'FAILED'],
        'HANDOFF_REQUESTED' => ['SENT_TO_RIP', 'FAILED'],
        'SENT_TO_RIP' => ['FAILED'],
        'FAILED' => ['QUEUED'],
    ];

    public function ids($item): array
    {
        return collect($item->matches ?? [])->pluck('id')->filter()->sort()->values()->all();
    }

    public function current($item): bool
    {
        $decision = $item->decision;

        return $decision
            && $this->ids($item) === collect($decision->drive_file_ids ?? [])->sort()->values()->all();
    }

    public function ready($item): bool
    {
        return !$item->is_custom
            && $item->decision?->decision === 'CORRECT'
            && count($this->ids($item))
            && $this->current($item);
    }

    public function verify($item, string $decision, int $userId): VerificationDecision
    {
        abort_if($item->is_custom, 422, 'Custom artwork uses a separate approval journey.');

        $ids = $this->ids($item);
        abort_if(!$ids, 422, 'No Drive artwork available.');

        return VerificationDecision::updateOrCreate(
            ['line_item_id' => $item->line_item_id],
            [
                'decision' => $decision,
                'drive_file_ids' => $ids,
                'updated_by' => $userId,
            ]
        );
    }

    public function queue(): Collection
    {
        return ProductionItem::with(['decision', 'batchItems.batch', 'printHistories'])
            ->orderBy('order_number')
            ->get()
            ->filter(function ($item) {
                if (!$this->ready($item)) {
                    return false;
                }

                $item->available_quantity = $this->availableQuantity($item);

                return $item->available_quantity > 0;
            })
            ->values();
    }

    public function availableQuantity(ProductionItem $item): int
    {
        return max(
            0,
            (int) $item->quantity
            - $this->completedNormalQuantity($item)
            - $this->activeAllocatedQuantity($item)
        );
    }

    public function completedNormalQuantity(ProductionItem $item): int
    {
        return (int) $item->printHistories()
            ->where('batch_type', 'normal')
            ->where('result', 'COMPLETED')
            ->sum('quantity');
    }

    public function activeAllocatedQuantity(ProductionItem $item): int
    {
        return (int) $item->batchItems()
            ->whereHas('batch', function ($query) {
                $query->where('type', 'normal')
                    ->whereNotIn('status', ['PRINTED', 'FAILED']);
            })
            ->sum('quantity');
    }

    public function summary($items): array
    {
        $printable = [];
        $nonprint = [];
        $sets = 0;
        $sides = 0;
        $files = 0;

        foreach ($items as $item) {
            $quantity = (int) ($item->selected_quantity ?? $item->available_quantity ?? $item->quantity);

            if ($quantity <= 0) {
                continue;
            }

            $sets += $quantity;
            $materials = self::MATERIALS[$item->series_code] ?? null;

            if (!$materials) {
                continue;
            }

            $uses = $item->design_type === 'single'
                ? [[$materials[0], 1, $quantity], [$materials[1], 0, $quantity]]
                : [[$materials[0], 1, $quantity], [$materials[1], 1, $quantity]];

            $sides += $item->design_type === 'single' ? $quantity : $quantity * 2;
            $files += $item->design_type === 'single' ? 1 : 2;

            foreach ($uses as [$material, $prints, $count]) {
                if ($prints) {
                    $printable[$material] ??= ['sides' => 0, 'yards' => 0];
                    $printable[$material]['sides'] += $count;
                    $printable[$material]['yards'] = round($printable[$material]['yards'] + $count * self::YARDS, 3);
                } else {
                    $nonprint[$material] ??= ['sides' => 0, 'yards' => 0];
                    $nonprint[$material]['sides'] += $count;
                    $nonprint[$material]['yards'] = round($nonprint[$material]['yards'] + $count * self::YARDS, 3);
                }
            }
        }

        return [
            'approved_orders' => $items->count(),
            'total_sets' => $sets,
            'printable_sides' => $sides,
            'artwork_files' => $files,
            'printable_materials' => $printable,
            'nonprint_materials' => $nonprint,
            'total_yards' => round(array_sum(array_column($printable, 'yards')) + array_sum(array_column($nonprint, 'yards')), 3),
        ];
    }

    public function preview(array $selections): array
    {
        $items = $this->selectedItems($selections, false);

        return [
            'items' => $items,
            'summary' => $this->summary($items),
        ];
    }

    public function batch(int $userId, array $selections): PrintBatch
    {
        return DB::transaction(function () use ($userId, $selections) {
            $items = $this->selectedItems($selections, true);

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Select at least one eligible item and quantity.',
                ]);
            }

            return $this->createBatch($items, $userId, 'normal');
        });
    }

    public function createReprintBatch(ProductionItem $item, int $quantity, string $reason, int $userId): PrintBatch
    {
        return DB::transaction(function () use ($item, $quantity, $reason, $userId) {
            $completed = $this->completedNormalQuantity($item);

            if ($completed <= 0 || $quantity < 1 || $quantity > $completed) {
                throw ValidationException::withMessages([
                    'quantity' => 'Reprint quantity must be within completed production quantity.',
                ]);
            }

            $sourceHistory = $item->printHistories()
                ->where('batch_type', 'normal')
                ->where('result', 'COMPLETED')
                ->latest()
                ->first();

            $item = ProductionItem::with('decision')->lockForUpdate()->findOrFail($item->id);
            $item->selected_quantity = $quantity;
            $batch = $this->createBatch(
                collect([$item]),
                $userId,
                'reprint',
                $reason,
                $sourceHistory?->print_batch_id
            );
            $batchItem = $batch->items()->first();

            PrintHistory::create([
                'print_batch_id' => $batch->id,
                'print_batch_item_id' => $batchItem->id,
                'production_item_id' => $item->id,
                'shopify_line_item_id' => $item->line_item_id,
                'result' => 'REPRINT_REQUESTED',
                'quantity' => $quantity,
                'batch_type' => 'reprint',
                'reason' => $reason,
                'recorded_by' => $userId,
                'recorded_at' => now(),
            ]);

            return $batch;
        });
    }

    public function confirmPrinted(PrintBatch $batch, array $results, int $userId): void
    {
        DB::transaction(function () use ($batch, $results, $userId) {
            $batch = PrintBatch::with('items')->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== 'SENT_TO_RIP') {
                throw ValidationException::withMessages([
                    'batch' => 'Only a batch already sent to Edge Print can be confirmed printed.',
                ]);
            }

            if ($batch->print_confirmed_at) {
                throw ValidationException::withMessages([
                    'batch' => 'This batch has already been confirmed.',
                ]);
            }

            foreach ($batch->items as $batchItem) {
                $row = $results[$batchItem->id] ?? [];
                $completed = max(0, (int) ($row['completed_quantity'] ?? 0));
                $failed = max(0, (int) ($row['failed_quantity'] ?? 0));

                if ($completed + $failed !== (int) $batchItem->quantity) {
                    throw ValidationException::withMessages([
                        'items' => 'Each item must account for its full batch quantity as printed or failed.',
                    ]);
                }

                $batchItem->update([
                    'completed_quantity' => $completed,
                    'failed_quantity' => $failed,
                    'status' => $failed > 0 ? ($completed > 0 ? 'PARTIAL' : 'FAILED') : 'COMPLETED',
                    'confirmed_at' => now(),
                    'confirmed_by' => $userId,
                ]);

                foreach ([['COMPLETED', $completed], ['FAILED', $failed]] as [$result, $quantity]) {
                    if ($quantity <= 0) {
                        continue;
                    }

                    PrintHistory::create([
                        'print_batch_id' => $batch->id,
                        'print_batch_item_id' => $batchItem->id,
                        'production_item_id' => $batchItem->production_item_id,
                        'shopify_line_item_id' => $batchItem->shopify_line_item_id,
                        'result' => $result,
                        'quantity' => $quantity,
                        'batch_type' => $batch->type,
                        'reason' => $row['reason'] ?? null,
                        'recorded_by' => $userId,
                        'recorded_at' => now(),
                    ]);
                }
            }

            $batch->update([
                'status' => 'PRINTED',
                'completed_at' => now(),
                'print_confirmed_at' => now(),
                'print_confirmed_by' => $userId,
            ]);
        });
    }

    public function transition(PrintBatch $batch, string $status, ?string $failureReason = null): PrintBatch
    {
        return DB::transaction(function () use ($batch, $status, $failureReason) {
            $batch = PrintBatch::lockForUpdate()->findOrFail($batch->id);

            if ($batch->status === $status) {
                return $batch;
            }

            $allowed = self::FACTORY_TRANSITIONS[$batch->status] ?? [];

            if (!in_array($status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => "Invalid transition {$batch->status} -> {$status}.",
                ]);
            }

            $updates = ['status' => $status];

            if ($status === 'CLAIMED') {
                $updates['claimed_at'] = $batch->claimed_at ?: now();
            }

            if ($status === 'HANDOFF_REQUESTED') {
                $updates['handoff_requested_at'] = now();
            }

            if ($status === 'SENT_TO_RIP') {
                $updates['sent_to_rip_at'] = now();
            }

            if ($status === 'FAILED') {
                $updates['failed_at'] = now();
                $updates['failure_reason'] = $failureReason;
            }

            $batch->update($updates);

            return $batch->fresh();
        });
    }

    private function selectedItems(array $selections, bool $lock): Collection
    {
        $ids = collect($selections)
            ->filter(fn ($quantity) => (int) $quantity > 0)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = ProductionItem::with(['decision', 'batchItems.batch', 'printHistories'])
            ->whereIn('id', $ids);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->map(function ($item) use ($selections) {
                if (!$this->ready($item)) {
                    throw ValidationException::withMessages([
                        'items' => "Item {$item->order_number} is not approved for print.",
                    ]);
                }

                $available = $this->availableQuantity($item);
                $quantity = (int) ($selections[$item->id] ?? 0);

                if ($quantity < 1 || $quantity > $available) {
                    throw ValidationException::withMessages([
                        'items' => "Invalid quantity for {$item->order_number}. Available: {$available}.",
                    ]);
                }

                $item->available_quantity = $available;
                $item->selected_quantity = $quantity;

                return $item;
            })
            ->values();
    }

    private function createBatch(
        Collection $items,
        int $userId,
        string $type,
        ?string $reason = null,
        ?int $reprintOfBatchId = null
    ): PrintBatch {
        $orders = [];
        $fileCount = 0;

        foreach ($items as $item) {
            [$artworks, $materials, $count] = $this->artworksFor($item);
            $fileCount += $count;

            $orders[] = [
                'line_item_id' => $item->line_item_id,
                'production_item_id' => $item->id,
                'order' => $item->order_number,
                'product' => $item->product,
                'variant' => $item->variant,
                'sku' => $item->sku,
                'series_code' => $item->series_code,
                'design_type' => $item->design_type,
                'quantity' => (int) $item->selected_quantity,
                'artworks' => $artworks,
                'reprint_reason' => $type === 'reprint' ? $reason : null,
            ];

            $item->batch_artworks = $artworks;
            $item->batch_materials = $materials;
        }

        $id = 'LB-BATCH-'.now('UTC')->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $summary = $this->summary($items);

        $payload = [
            'batch_id' => $id,
            'status' => 'QUEUED',
            'type' => $type,
            'created_at' => now('UTC')->toIso8601String(),
            'yards_per_side_per_set' => self::YARDS,
            'order_count' => count($orders),
            'artwork_file_count' => $fileCount,
            'orders' => $orders,
        ];

        $batch = PrintBatch::create([
            'batch_id' => $id,
            'type' => $type,
            'reprint_of_batch_id' => $reprintOfBatchId,
            'status' => 'QUEUED',
            'order_count' => count($orders),
            'artwork_file_count' => $fileCount,
            'total_yards' => $summary['total_yards'],
            'payload' => $payload,
            'created_by' => $userId,
        ]);

        foreach ($items as $item) {
            PrintBatchItem::create([
                'print_batch_id' => $batch->id,
                'production_item_id' => $item->id,
                'shopify_line_item_id' => $item->line_item_id,
                'quantity' => (int) $item->selected_quantity,
                'status' => 'ALLOCATED',
                'artworks' => $item->batch_artworks,
                'materials' => $item->batch_materials,
                'created_by' => $userId,
            ]);
        }

        return $batch->fresh('items');
    }

    private function artworksFor($item): array
    {
        $materials = self::MATERIALS[$item->series_code] ?? null;

        if (!$materials) {
            throw ValidationException::withMessages([
                'items' => "No material mapping for {$item->series_code}.",
            ]);
        }

        $matches = collect($item->matches ?? []);
        $artworks = [];

        if ($item->design_type === 'single') {
            $match = $matches->first();

            if (!$match) {
                throw ValidationException::withMessages(['items' => 'Single artwork missing.']);
            }

            $artworks[] = $this->art('PRINT', $materials[0], $match);

            return [$artworks, $materials, 1];
        }

        $front = $matches->firstWhere('side', 'front');
        $back = $matches->firstWhere('side', 'back');

        if (!$front || !$back) {
            throw ValidationException::withMessages(['items' => 'Double artwork incomplete.']);
        }

        $artworks[] = $this->art('FRONT', $materials[0], $front);
        $artworks[] = $this->art('BACK', $materials[1], $back);

        return [$artworks, $materials, 2];
    }

    private function art(string $side, string $material, array $match): array
    {
        return [
            'side' => $side,
            'material' => $material,
            'drive_file_id' => $match['id'] ?? null,
            'filename' => $match['name'] ?? null,
            'drive_link' => $match['webViewLink'] ?? null,
            'thumbnail' => isset($match['id'])
                ? 'https://drive.google.com/thumbnail?id='.$match['id'].'&sz=w800'
                : null,
        ];
    }
}
