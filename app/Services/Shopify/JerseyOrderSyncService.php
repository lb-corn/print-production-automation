<?php

namespace App\Services\Shopify;

use App\Models\JerseyOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JerseyOrderSyncService
{
    public const LAST_SUCCESSFUL_SYNC_CACHE_KEY = 'shopify.jersey_orders.last_successful_sync_at';

    public const LAST_SYNC_STARTED_AT_CACHE_KEY = 'jersey_orders.last_sync_started_at';

    public const LAST_SUCCESSFUL_SYNC_CACHE_KEY_V2 = 'jersey_orders.last_successful_sync';

    public const LAST_SYNC_FAILED_AT_CACHE_KEY = 'jersey_orders.last_sync_failed_at';

    public const LAST_SYNC_ERROR_SUMMARY_CACHE_KEY = 'jersey_orders.last_sync_error_summary';

    public const LAST_SYNC_SOURCE_CACHE_KEY = 'jersey_orders.last_sync_source';

    private const PAGE_SIZE = 50;

    /**
     * @var list<string>
     */
    private const LOCAL_MANAGED_COLUMNS = [
        'tracking_number',
        'tracking_updated_at',
        'tracking_updated_by',
        'remark',
        'remark_updated_at',
        'remark_updated_by',
        'remark_processed_at',
        'remark_processed_by',
    ];

    /**
     * @var list<string>
     */
    private array $displayNameKeys = [
        'Custom Name',
        'Player Name',
        'Name on Back',
        'Jersey Name',
        'Name',
    ];

    /**
     * @var list<string>
     */
    private array $sizeKeys = ['Size', 'Apparel Size', 'Jersey Size', 'Shirt Size'];

    /**
     * @var list<string>
     */
    private array $colorKeys = ['Color', 'Colour', 'Jersey Color', 'Jersey Colour'];

    /**
     * @var list<string>
     */
    private array $styleKeys = ['Pick Your Jersey Style', 'Jersey Style', 'Style', 'Shirt Style'];

    /**
     * @var list<string>
     */
    private array $knownSizes = [
        'XS', 'S', 'Small', 'M', 'Medium', 'L', 'Large', 'XL',
        '2X', '2XL', '3X', '3XL', '4X', '4XL', '5X', '5XL', '6X', '6XL',
    ];

    /**
     * @var list<string>
     */
    private array $knownStyles = [
        'Short Sleeve Hoodie',
        'Long Sleeve Hoodie',
        'Sleeveless Hoodie',
        'Short Sleeve Jersey',
        'Long Sleeve Jersey',
        'Sleeveless Jersey',
        'T-Shirt',
        'Tank Top',
        'Hoodie',
        'Jersey',
    ];

    public function __construct(private readonly ShopifyGraphqlClient $client) {}

    /**
     * @return array{pages_fetched:int, shopify_orders_scanned:int, jersey_line_items_matched:int, inserted:int, updated:int, skipped:int}
     */
    public function sync(bool $full = false, ?string $since = null, ?int $limit = null, string $source = 'command'): array
    {
        $lock=Cache::lock('apparel:orders:sync',900);
        if(!$lock->get()) throw new \RuntimeException('An apparel sync is already running.');
        $this->recordSyncStarted($source);

        try {
            $stats = $this->performSync($full, $since, $limit);
            $this->recordLastSuccessfulSync($source);

            return $stats;
        } catch (\Throwable $exception) {
            $this->recordSyncFailed($source, $exception);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{pages_fetched:int, shopify_orders_scanned:int, jersey_line_items_matched:int, inserted:int, updated:int, skipped:int}
     */
    private function performSync(bool $full = false, ?string $since = null, ?int $limit = null): array
    {
        $stats = [
            'pages_fetched' => 0,
            'shopify_orders_scanned' => 0,
            'jersey_line_items_matched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        $cursor = null;
        $queryFilter = $full ? null : $this->buildIncrementalQuery($since);

        do {
            $payload = $this->client->query($this->ordersQuery(), [
                'first' => self::PAGE_SIZE,
                'after' => $cursor,
                'query' => $queryFilter,
            ]);

            $orders = data_get($payload, 'data.orders.nodes', []);
            $pageInfo = data_get($payload, 'data.orders.pageInfo', []);
            $stats['pages_fetched']++;
            $stats['shopify_orders_scanned'] += is_countable($orders) ? count($orders) : 0;

            $records = [];

            foreach ($orders as $order) {
                foreach (data_get($order, 'lineItems.nodes', []) as $lineItem) {
                    $record = $this->normalizeLineItem($order, $lineItem);

                    if ($record === null) {
                        $stats['skipped']++;

                        continue;
                    }

                    $stats['jersey_line_items_matched']++;
                    $records[] = $record;
                }
            }

            [$inserted, $updated] = $this->upsertRecords($records);
            $stats['inserted'] += $inserted;
            $stats['updated'] += $updated;

            $cursor = is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null;
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
        } while ($hasNextPage && ($limit === null || $stats['pages_fetched'] < $limit));

        return $stats;
    }

    public function recordSyncStarted(string $source): CarbonInterface
    {
        $startedAt = now();
        Cache::forever(self::LAST_SYNC_STARTED_AT_CACHE_KEY, $startedAt->toIso8601String());
        Cache::forever(self::LAST_SYNC_SOURCE_CACHE_KEY, $this->normalizeSource($source));

        return $startedAt;
    }

    public function recordLastSuccessfulSync(string $source = 'command'): CarbonInterface
    {
        $syncedAt = now();
        Cache::forever(self::LAST_SUCCESSFUL_SYNC_CACHE_KEY, $syncedAt->toIso8601String());
        Cache::forever(self::LAST_SUCCESSFUL_SYNC_CACHE_KEY_V2, $syncedAt->toIso8601String());
        Cache::forever(self::LAST_SYNC_SOURCE_CACHE_KEY, $this->normalizeSource($source));
        Cache::forget(self::LAST_SYNC_FAILED_AT_CACHE_KEY);
        Cache::forget(self::LAST_SYNC_ERROR_SUMMARY_CACHE_KEY);

        return $syncedAt;
    }

    public function recordSyncFailed(string $source, \Throwable $exception): CarbonInterface
    {
        $failedAt = now();
        Cache::forever(self::LAST_SYNC_FAILED_AT_CACHE_KEY, $failedAt->toIso8601String());
        Cache::forever(self::LAST_SYNC_ERROR_SUMMARY_CACHE_KEY, $this->safeErrorSummary($exception));
        Cache::forever(self::LAST_SYNC_SOURCE_CACHE_KEY, $this->normalizeSource($source));

        return $failedAt;
    }

    private function normalizeSource(string $source): string
    {
        return in_array($source, ['scheduled', 'manual', 'command'], true) ? $source : 'command';
    }

    private function safeErrorSummary(\Throwable $exception): string
    {
        $message = preg_replace('/(shpss_|shpat_|shpca_)[A-Za-z0-9_\-]+/', '[redacted]', $exception->getMessage());
        $message = preg_replace('/access[_ -]?token[=: ]+[A-Za-z0-9_\-\.]+/i', 'access token [redacted]', (string) $message);

        foreach (['client_id', 'client_secret'] as $key) {
            $value = (string) config('services.shopify.'.$key);

            if ($value !== '') {
                $message = str_replace($value, '[redacted]', (string) $message);
            }
        }

        return Str::limit(class_basename($exception).($message ? ': '.$message : ''), 240, '...');
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $lineItem
     * @return array<string, mixed>|null
     */
    public function normalizeLineItem(array $order, array $lineItem): ?array
    {
        if (! $this->isJerseyLineItem($lineItem)) {
            return null;
        }

        $variant = data_get($lineItem, 'variant');
        $product = data_get($variant, 'product');
        $customFields = $this->keyValueList(data_get($lineItem, 'customAttributes', []), 'key');
        $selectedOptions = $this->keyValueList(data_get($variant, 'selectedOptions', []), 'name');
        $variantTitle = $this->cleanValue(data_get($variant, 'title'));
        $normalized = $this->normalizeDetails($customFields, $selectedOptions, $variantTitle);

        return [
            'shopify_order_gid' => (string) data_get($order, 'id'),
            'shopify_order_number' => (string) data_get($order, 'name'),
            'shopify_line_item_gid' => (string) data_get($lineItem, 'id'),
            'shopify_product_gid' => $this->cleanValue(data_get($product, 'id')),
            'shopify_variant_gid' => $this->cleanValue(data_get($variant, 'id')),
            'ordered_at' => $this->parseDate(data_get($order, 'createdAt')),
            'product_title' => (string) data_get($product, 'title'),
            'product_handle' => $this->cleanValue(data_get($product, 'handle')),
            'variant_title' => $variantTitle,
            'style' => $normalized['style'],
            'color' => $normalized['color'],
            'size' => $normalized['size'],
            'display_name' => $normalized['display_name'],
            'quantity' => max(1, (int) data_get($lineItem, 'quantity', 1)),
            'image_url' => $this->cleanValue(data_get($variant, 'image.url')) ?: $this->cleanValue(data_get($product, 'featuredMedia.preview.image.url')),
            'custom_fields' => $customFields === [] ? null : $customFields,
            'selected_options' => $selectedOptions === [] ? null : $selectedOptions,
            'product_tags' => data_get($product, 'tags', []) ?: null,
            'shopify_fulfillment_status' => (string) data_get($order, 'displayFulfillmentStatus', 'UNKNOWN'),
            'shopify_financial_status' => $this->cleanValue(data_get($order, 'displayFinancialStatus')),
            'shopify_cancelled_at' => $this->parseDate(data_get($order, 'cancelledAt')),
            'shopify_closed_at' => $this->parseDate(data_get($order, 'closedAt')),
            'last_synced_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    public function isJerseyLineItem(array $lineItem): bool
    {
        $variant = data_get($lineItem, 'variant');
        $product = data_get($variant, 'product');

        if (! is_array($product)) {
            return false;
        }

        $titles = [
            (string) data_get($lineItem, 'title', ''),
            (string) data_get($product, 'title', ''),
        ];

        foreach ($titles as $title) {
            $lower = Str::lower($title);

            if (str_contains($lower, 'shipping fee') || str_contains($lower, 'handling fee') || str_contains($lower, 'custom jersey shipping fee')) {
                return false;
            }
        }

        $productTitle = Str::lower((string) data_get($product, 'title', ''));

        if (str_contains($productTitle, 'jersey') || str_contains($productTitle, 'hoodie')) {
            return true;
        }

        $tags = data_get($product, 'tags', []);

        if (! is_array($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (in_array(Str::lower(trim((string) $tag)), ['jersey', 'jerseys', 'hoodie'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{key:string,value:string}>  $customFields
     * @param  list<array{name:string,value:string}>  $selectedOptions
     * @return array{display_name:?string,style:?string,color:?string,size:?string}
     */
    public function normalizeDetails(array $customFields, array $selectedOptions, ?string $variantTitle): array
    {
        $displayName = $this->firstValueByKey($customFields, $this->displayNameKeys, 'key')
            ?: $this->combinedName($customFields, 'First Name', 'Last Name')
            ?: $this->combinedName($customFields, 'Firstname', 'Lastname');

        $style = $this->firstValueByKey($selectedOptions, $this->styleKeys, 'name');
        $color = $this->firstValueByKey($selectedOptions, $this->colorKeys, 'name');
        $size = $this->normalizeSize($this->firstValueByKey($selectedOptions, $this->sizeKeys, 'name'));

        $parts = $this->variantParts($variantTitle);

        if ($size === null && $parts !== []) {
            $size = $this->normalizeSize(end($parts) ?: null);
        }

        if ($style === null) {
            foreach ($parts as $part) {
                if ($this->isKnownStyle($part)) {
                    $style = $part;

                    break;
                }
            }
        }

        if ($color === null && $parts !== []) {
            $candidate = $parts[0];

            if (! $this->isKnownStyle($candidate) && ! $this->isKnownSize($candidate)) {
                $color = $candidate;
            }
        }

        return [
            'display_name' => $this->cleanValue($displayName),
            'style' => $this->cleanValue($style),
            'color' => $this->cleanValue($color),
            'size' => $this->cleanValue($size),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{0:int,1:int}
     */
    private function upsertRecords(array $records): array
    {
        $inserted = 0;
        $updated = 0;

        DB::transaction(function () use ($records, &$inserted, &$updated): void {
            foreach ($records as $record) {
                $record = Arr::except($record, self::LOCAL_MANAGED_COLUMNS);

                $order = JerseyOrder::firstOrNew([
                    'shopify_line_item_gid' => $record['shopify_line_item_gid'],
                ]);

                $order->exists ? $updated++ : $inserted++;
                $order->fill($record);
                $order->save();
            }
        });

        return [$inserted, $updated];
    }

    private function ordersQuery(): string
    {
        return <<<'GRAPHQL'
query JerseyOrders($first: Int!, $after: String, $query: String) {
  orders(
    first: $first
    after: $after
    query: $query
    sortKey: CREATED_AT
    reverse: true
  ) {
    nodes {
      id
      name
      createdAt
      cancelledAt
      closedAt
      displayFulfillmentStatus
      displayFinancialStatus
      lineItems(first: 100) {
        nodes {
          id
          title
          quantity
          customAttributes {
            key
            value
          }
          variant {
            id
            title
            selectedOptions {
              name
              value
            }
            image {
              url
            }
            product {
              id
              title
              handle
              tags
              featuredMedia {
                preview {
                  image {
                    url
                  }
                }
              }
            }
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;
    }

    private function buildIncrementalQuery(?string $since): string
    {
        $date = $since ? Carbon::parse($since) : now()->subDays(30);
        $isoDate = $date->toIso8601String();

        return 'created_at:>='.$isoDate.' OR updated_at:>='.$isoDate;
    }

    /**
     * @return list<array<string, string>>
     */
    private function keyValueList(mixed $items, string $keyName): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function (mixed $item) use ($keyName): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $key = $this->cleanValue($item[$keyName] ?? null);
                $value = $this->cleanValue($item['value'] ?? null);

                if ($key === null || $value === null) {
                    return null;
                }

                return [$keyName => $key, 'value' => $value];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, string>>  $items
     * @param  list<string>  $keys
     */
    private function firstValueByKey(array $items, array $keys, string $keyName): ?string
    {
        $normalizedKeys = collect($keys)->map(fn (string $key): string => Str::lower($key))->all();

        foreach ($items as $item) {
            $key = Str::lower((string) ($item[$keyName] ?? ''));

            if (in_array($key, $normalizedKeys, true)) {
                return $this->cleanValue($item['value'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, string>>  $fields
     */
    private function combinedName(array $fields, string $firstKey, string $lastKey): ?string
    {
        $first = $this->firstValueByKey($fields, [$firstKey], 'key');
        $last = $this->firstValueByKey($fields, [$lastKey], 'key');

        return $this->cleanValue(trim(($first ?? '').' '.($last ?? '')));
    }

    private function normalizeSize(?string $value): ?string
    {
        $value = $this->cleanValue($value);

        if ($value === null || ! $this->isKnownSize($value)) {
            return null;
        }

        $map = [
            'small' => 'Small',
            'medium' => 'Medium',
            'large' => 'Large',
            'xs' => 'XS',
            's' => 'S',
            'm' => 'M',
            'l' => 'L',
            'xl' => 'XL',
            '2x' => '2X',
            '2xl' => '2XL',
            '3x' => '3X',
            '3xl' => '3XL',
            '4x' => '4X',
            '4xl' => '4XL',
            '5x' => '5X',
            '5xl' => '5XL',
            '6x' => '6X',
            '6xl' => '6XL',
        ];

        return $map[Str::lower($value)] ?? $value;
    }

    private function isKnownSize(?string $value): bool
    {
        $value = $this->cleanValue($value);

        if ($value === null) {
            return false;
        }

        return in_array(Str::lower($value), collect($this->knownSizes)->map(fn (string $size): string => Str::lower($size))->all(), true);
    }

    private function isKnownStyle(?string $value): bool
    {
        $value = $this->cleanValue($value);

        if ($value === null) {
            return false;
        }

        return in_array(Str::lower($value), collect($this->knownStyles)->map(fn (string $style): string => Str::lower($style))->all(), true);
    }

    /**
     * @return list<string>
     */
    private function variantParts(?string $variantTitle): array
    {
        $variantTitle = $this->cleanValue($variantTitle);

        if ($variantTitle === null || Str::lower($variantTitle) === 'default title') {
            return [];
        }

        return collect(explode(' / ', $variantTitle))
            ->map(fn (string $part): ?string => $this->cleanValue($part))
            ->filter()
            ->values()
            ->all();
    }

    private function cleanValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
