<?php

namespace App\Http\Controllers;

use App\Models\JerseyOrder;
use App\Services\Shopify\JerseyOrderSyncService;
use DateTimeInterface;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class JerseyOrderController extends Controller
{
    /**
     * @var list<int>
     */
    private array $allowedPerPage = [25, 50, 100];

    public function index(Request $request): ViewContract
    {
        $filters = $this->validatedFilters($request);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $status = array_key_exists('fulfillment_status', $filters)
            ? $filters['fulfillment_status']
            : 'UNFULFILLED';

        $query = JerseyOrder::query()
            ->with(['trackingUpdatedBy', 'remarkUpdatedBy', 'remarkProcessedBy'])
            ->needsAttention($filters['attention'] ?? null)
            ->search($filters['search'] ?? null)
            ->forProduct($filters['product'] ?? null)
            ->forVariant($filters['variant'] ?? null)
            ->forColor($filters['color'] ?? null)
            ->forSize($filters['size'] ?? null);

        if ($status !== 'all') {
            $query->eligibleForProduction()->forFulfillmentStatus($status);
        }

        $orders = $query
            ->orderByRaw($this->shopifyOrderNumberSortExpression().' DESC')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $filterOptions = [
            'products' => $this->distinctValues('product_title'),
            'variants' => $this->distinctValues('variant_title'),
            'colors' => $this->distinctValues('color'),
            'sizes' => $this->distinctValues('size'),
            'statuses' => $this->distinctValues('shopify_fulfillment_status'),
        ];

        $summary = [
            'unfulfilled' => JerseyOrder::eligibleForProduction()->where('shopify_fulfillment_status', 'UNFULFILLED')->count(),
            'partially_fulfilled' => JerseyOrder::eligibleForProduction()->where('shopify_fulfillment_status', 'PARTIALLY_FULFILLED')->count(),
            'fulfilled' => JerseyOrder::eligibleForProduction()->where('shopify_fulfillment_status', 'FULFILLED')->count(),
            'total' => JerseyOrder::count(),
        ];

        $lastSync = $this->normalizeLastSync(Cache::get(JerseyOrderSyncService::LAST_SUCCESSFUL_SYNC_CACHE_KEY_V2))
            ?? $this->normalizeLastSync(Cache::get(JerseyOrderSyncService::LAST_SUCCESSFUL_SYNC_CACHE_KEY))
            ?? $this->normalizeLastSync(JerseyOrder::max('last_synced_at'));
        $syncHealth = $this->syncHealth($lastSync);
        $storefrontBase = $this->storefrontBase();

        return view('apparel.orders.index', [
            'orders' => $orders,
            'filters' => array_merge($filters, ['fulfillment_status' => $status, 'per_page' => $perPage]),
            'filterOptions' => $filterOptions,
            'summary' => $summary,
            'lastSync' => $lastSync,
            'lastSyncLabel' => $this->lastSyncLabel($lastSync),
            'lastSyncRelative' => $this->lastSyncRelative($lastSync),
            'syncHealth' => $syncHealth,
            'storefrontBase' => $storefrontBase,
        ]);
    }

    public function show(JerseyOrder $jerseyOrder): JsonResponse
    {
        $jerseyOrder->load(['trackingUpdatedBy', 'remarkUpdatedBy', 'remarkProcessedBy']);

        return response()->json([
            'order' => $jerseyOrder,
            'storefront_url' => $jerseyOrder->product_handle && $this->storefrontBase() ? $this->storefrontBase().$jerseyOrder->product_handle : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'max:255'],
            'product' => ['nullable', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:255'],
            'fulfillment_status' => ['nullable', 'string', 'max:255'],
            'attention' => ['nullable', Rule::in(['open','pending_remarks','missing_tracking'])],
            'per_page' => ['nullable', Rule::in($this->allowedPerPage)],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();
    }

    /**
     * @return list<string>
     */
    private function distinctValues(string $column): array
    {
        return JerseyOrder::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    private function storefrontBase(): ?string
    {
        $store = trim((string) config('services.shopify.store'));
        $store = Str::of($store)->replaceStart('https://', '')->replaceStart('http://', '')->trim('/')->toString();

        return $store === '' ? null : 'https://'.$store.'/products/';
    }

    private function shopifyOrderNumberSortExpression(): string
    {
        if (JerseyOrder::query()->getConnection()->getDriverName() === 'mysql') {
            return "CAST(REPLACE(shopify_order_number, '#', '') AS UNSIGNED)";
        }

        return "CAST(REPLACE(shopify_order_number, '#', '') AS INTEGER)";
    }

    private function normalizeLastSync(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function lastSyncLabel(?DateTimeInterface $lastSync): string
    {
        if (! $lastSync) {
            return 'Never';
        }

        return Carbon::instance($lastSync)
            ->timezone(config('app.timezone'))
            ->format('M j, Y g:i A');
    }

    private function lastSyncRelative(?DateTimeInterface $lastSync): ?string
    {
        if (! $lastSync) {
            return null;
        }

        return Carbon::instance($lastSync)->diffForHumans();
    }

    /**
     * @return array{state:string,label:string,class:string,message:string,error:?string}
     */
    private function syncHealth(?DateTimeInterface $lastSync): array
    {
        $failedAt = $this->normalizeLastSync(Cache::get(JerseyOrderSyncService::LAST_SYNC_FAILED_AT_CACHE_KEY));
        $errorSummary = Cache::get(JerseyOrderSyncService::LAST_SYNC_ERROR_SUMMARY_CACHE_KEY);

        if ($failedAt && (! $lastSync || $failedAt >= $lastSync)) {
            return [
                'state' => 'error',
                'label' => 'Error',
                'class' => 'sync-health-error',
                'message' => 'The latest sync attempt failed. Review the application log.',
                'error' => is_string($errorSummary) ? $errorSummary : null,
            ];
        }

        if (! $lastSync) {
            return [
                'state' => 'never',
                'label' => 'Never Synced',
                'class' => 'sync-health-never',
                'message' => 'Run Sync Orders or start the scheduler.',
                'error' => null,
            ];
        }

        if (Carbon::instance($lastSync)->greaterThanOrEqualTo(now()->subMinutes(20))) {
            return [
                'state' => 'active',
                'label' => 'Active',
                'class' => 'sync-health-active',
                'message' => 'Orders are syncing automatically every 10 minutes.',
                'error' => null,
            ];
        }

        return [
            'state' => 'delayed',
            'label' => 'Delayed',
            'class' => 'sync-health-delayed',
            'message' => 'Automatic sync may not be running. Check the scheduler.',
            'error' => null,
        ];
    }
}
