<?php

namespace Tests\Feature;

use App\Models\PrintBatch;
use App\Models\PrintHistory;
use App\Models\ProductionItem;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class ManufacturingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test Operator',
            'email' => 'operator@example.test',
            'password' => Hash::make('password'),
            'role' => 'operator',
        ]);

        $this->withoutMiddleware(\App\Http\Middleware\FactoryKey::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_shopify_sync_uses_requested_fulfillment_filter_and_paginates(): void
    {
        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductionBacklog')
            ->once()
            ->with('unfulfilled_partial')
            ->andReturn([
                'items' => [$this->shopifyRow('gid://shopify/LineItem/1', 3)],
                'order_count' => 2,
                'item_count' => 1,
            ]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('artworkIndex')->once()->andReturn($this->driveIndex());

        $this->app->instance(ShopifyService::class, $shopify);
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->actingAs($this->user)
            ->post(route('production.sync-backlog'), ['mode' => 'unfulfilled_partial'])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('production_items', [
            'line_item_id' => 'gid://shopify/LineItem/1',
            'quantity' => 3,
        ]);
    }

    public function test_shopify_backlog_filters_and_paginates_orders_and_line_items(): void
    {
        $service = new class extends ShopifyService {
            public array $queries = [];

            public function __construct() {}

            public function graphql(string $query, array $variables = []): array
            {
                $this->queries[] = [$query, $variables];

                if (str_contains($query, 'ProductionBacklogLineItems')) {
                    return [
                        'order' => [
                            'lineItems' => [
                                'nodes' => [[
                                    'id' => 'line-extra',
                                    'name' => 'Extra',
                                    'quantity' => 2,
                                    'unfulfilledQuantity' => 2,
                                    'sku' => 'LB-DRAGON-SUP-BLACK',
                                    'variantTitle' => 'Black',
                                    'product' => ['title' => 'Lucky Bags Stock Set', 'productType' => 'ACL Pro Stamped 2026'],
                                    'image' => ['url' => null],
                                ]],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ];
                }

                $after = $variables['after'] ?? null;

                if ($after === null) {
                    return [
                        'orders' => [
                            'nodes' => [
                                $this->order('order-1', '#1001', [
                                    $this->line('line-good', 3, 'ACL Pro Stamped 2026', 'Lucky Bags Stock Set'),
                                    $this->line('line-custom', 1, 'ACL Pro Stamped 2026', 'Custom Lucky Bags'),
                                    $this->line('line-nonbag', 1, 'Accessory', 'Patch'),
                                    $this->line('line-zero', 0, 'ACL Pro Stamped 2026', 'Lucky Bags Stock Set'),
                                ], true),
                                $this->order('order-cancelled', '#1002', [$this->line('line-cancelled', 1)], false, '2026-10-01T00:00:00Z'),
                                $this->order('order-refunded', '#1003', [$this->line('line-refunded', 1)], false, null, 'REFUNDED'),
                            ],
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                        ],
                    ];
                }

                return [
                    'orders' => [
                        'nodes' => [
                            $this->order('order-2', '#1004', [$this->line('line-page-2', 1)]),
                        ],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ],
                ];
            }

            private function order(
                string $id,
                string $name,
                array $lines,
                bool $lineHasNext = false,
                ?string $cancelledAt = null,
                string $financial = 'PAID'
            ): array {
                return [
                    'id' => $id,
                    'name' => $name,
                    'createdAt' => '2026-10-01T00:00:00Z',
                    'cancelledAt' => $cancelledAt,
                    'displayFinancialStatus' => $financial,
                    'displayFulfillmentStatus' => 'UNFULFILLED',
                    'customer' => ['displayName' => 'Customer'],
                    'lineItems' => [
                        'nodes' => $lines,
                        'pageInfo' => ['hasNextPage' => $lineHasNext, 'endCursor' => 'line-cursor'],
                    ],
                ];
            }

            private function line(
                string $id,
                int $unfulfilled,
                string $productType = 'ACL Pro Stamped 2026',
                string $title = 'Lucky Bags Stock Set'
            ): array {
                return [
                    'id' => $id,
                    'name' => $title,
                    'quantity' => $unfulfilled,
                    'unfulfilledQuantity' => $unfulfilled,
                    'sku' => 'LB-DRAGON-SUP-BLACK',
                    'variantTitle' => 'Black',
                    'product' => ['title' => $title, 'productType' => $productType],
                    'image' => ['url' => null],
                ];
            }
        };

        $result = $service->getProductionBacklog('unfulfilled_partial', 2);

        $this->assertSame(['line-good', 'line-extra', 'line-page-2'], array_column($result['items'], 'line_item_id'));
        $this->assertSame(4, $result['order_count']);
        $this->assertSame('fulfillment_status:unfulfilled OR fulfillment_status:partial', $service->queries[0][1]['statusQuery']);
        $this->assertCount(3, $service->queries);
    }

    public function test_repeat_sync_tracks_quantity_delta_without_reopening_completed_work(): void
    {
        $item = $this->approvedItem(['quantity' => 3, 'last_synced_quantity' => 3]);
        $batch = $this->batchFor($item, 3, 'PRINTED');
        $batchItem = $batch->items()->first();

        PrintHistory::create([
            'print_batch_id' => $batch->id,
            'print_batch_item_id' => $batchItem->id,
            'production_item_id' => $item->id,
            'shopify_line_item_id' => $item->line_item_id,
            'result' => 'COMPLETED',
            'quantity' => 3,
            'batch_type' => 'normal',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
        ]);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductionBacklog')->once()->andReturn([
            'items' => [$this->shopifyRow($item->line_item_id, 4)],
            'order_count' => 1,
            'item_count' => 1,
        ]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('artworkIndex')->once()->andReturn($this->driveIndex());

        $this->app->instance(ShopifyService::class, $shopify);
        $this->app->instance(GoogleDriveService::class, $drive);

        $this->actingAs($this->user)->post(route('production.sync-backlog'));

        $this->assertSame(1, app(\App\Services\ProductionService::class)->availableQuantity($item->fresh()));
        $this->assertDatabaseHas('production_items', ['id' => $item->id, 'quantity_delta' => 1]);
    }

    public function test_batch_requires_explicit_selected_quantities_and_prevents_duplicates(): void
    {
        $item = $this->approvedItem(['quantity' => 5]);

        $this->actingAs($this->user)
            ->post(route('batch.create'), ['items' => [$item->id => 2]])
            ->assertRedirect();

        $this->assertDatabaseHas('print_batch_items', [
            'production_item_id' => $item->id,
            'quantity' => 2,
        ]);

        $this->actingAs($this->user)
            ->post(route('batch.create'), ['items' => [$item->id => 4]])
            ->assertSessionHasErrors();
    }

    public function test_factory_status_transitions_are_strict_and_physical_print_is_operator_only(): void
    {
        $item = $this->approvedItem();
        $batch = $this->batchFor($item, 1);

        $this->postJson('/api/factory/batches/'.$batch->id.'/status', ['status' => 'SENT_TO_RIP'])
            ->assertStatus(422);

        foreach (['CLAIMED', 'DOWNLOADING', 'PREPARED'] as $status) {
            $this->postJson('/api/factory/batches/'.$batch->id.'/status', ['status' => $status])
                ->assertOk();
        }

        $this->postJson('/api/factory/batches/'.$batch->id.'/status', ['status' => 'PRINTED'])
            ->assertStatus(422);
    }

    public function test_completion_records_partial_quantities_and_prevents_duplicate_confirmation(): void
    {
        $item = $this->approvedItem(['quantity' => 4]);
        $batch = $this->batchFor($item, 4, 'SENT_TO_RIP');
        $batchItem = $batch->items()->first();

        $payload = ['items' => [$batchItem->id => [
            'completed_quantity' => 2,
            'failed_quantity' => 2,
            'reason' => 'Two rejected after inspection',
        ]]];

        $this->actingAs($this->user)->post(route('batch.printed', $batch), $payload)->assertRedirect();
        $this->actingAs($this->user)->post(route('batch.printed', $batch), $payload)->assertSessionHasErrors();

        $this->assertDatabaseHas('print_histories', ['result' => 'COMPLETED', 'quantity' => 2]);
        $this->assertDatabaseHas('print_histories', ['result' => 'FAILED', 'quantity' => 2]);
    }

    public function test_reprint_creates_linked_batch_without_changing_original_history(): void
    {
        $item = $this->approvedItem(['quantity' => 2]);
        $batch = $this->batchFor($item, 2, 'PRINTED');
        $batchItem = $batch->items()->first();

        PrintHistory::create([
            'print_batch_id' => $batch->id,
            'print_batch_item_id' => $batchItem->id,
            'production_item_id' => $item->id,
            'shopify_line_item_id' => $item->line_item_id,
            'result' => 'COMPLETED',
            'quantity' => 2,
            'batch_type' => 'normal',
            'recorded_by' => $this->user->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->user)->post(route('reprint.create', $item), [
            'quantity' => 1,
            'reason' => 'Damaged in finishing',
            'confirm' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('print_batches', [
            'type' => 'reprint',
            'reprint_of_batch_id' => $batch->id,
        ]);
        $this->assertSame(1, PrintHistory::where('result', 'COMPLETED')->count());
    }

    private function approvedItem(array $overrides = []): ProductionItem
    {
        $item = ProductionItem::create(array_merge([
            'line_item_id' => 'gid://shopify/LineItem/'.uniqid(),
            'order_number' => '#1001',
            'product' => 'Lucky Bags Stock Set',
            'product_type' => 'ACL Pro Stamped 2026',
            'variant' => 'Black',
            'sku' => 'LB-DRAGON-SUP-BLACK',
            'quantity' => 1,
            'series_code' => 'SUP',
            'design_type' => 'single',
            'design' => 'DRAGON',
            'color' => 'BLACK',
            'is_custom' => false,
            'match_status' => 'REVIEW',
            'matches' => [$this->driveIndex()[0]],
        ], $overrides));

        $item->decision()->create([
            'decision' => 'CORRECT',
            'drive_file_ids' => ['drive-1'],
            'updated_by' => $this->user->id,
        ]);

        return $item->fresh('decision');
    }

    private function batchFor(ProductionItem $item, int $quantity, string $status = 'QUEUED'): PrintBatch
    {
        $item->selected_quantity = $quantity;
        $batch = app(\App\Services\ProductionService::class)->batch($this->user->id, [$item->id => $quantity]);
        $batch->update(['status' => $status]);

        return $batch->fresh('items');
    }

    private function shopifyRow(string $lineItemId, int $quantity): array
    {
        return [
            'line_item_id' => $lineItemId,
            'order_id' => 'gid://shopify/Order/1',
            'order_number' => '#1001',
            'order_date' => now()->toIso8601String(),
            'customer' => 'Customer',
            'product' => 'Lucky Bags Stock Set',
            'product_type' => 'ACL Pro Stamped 2026',
            'variant' => 'Black',
            'sku' => 'LB-DRAGON-SUP-BLACK',
            'quantity' => $quantity,
            'shopify_image' => null,
            'financial_status' => 'PAID',
            'fulfillment_status' => 'UNFULFILLED',
        ];
    }

    private function driveIndex(): array
    {
        return [[
            'id' => 'drive-1',
            'name' => 'SUP-DRAGON-BLACK.png',
            'path' => 'GROUP B (Carpet)/SUP',
            'webViewLink' => 'https://drive.example/art',
            'side' => 'single',
        ]];
    }
}
