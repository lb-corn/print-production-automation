<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyService
{
    private string $store;
    private string $clientId;
    private string $clientSecret;
    private string $apiVersion;
    private string $staticAccessToken;
    private int $connectTimeout;
    private int $requestTimeout;
    private int $retryAttempts;
    private int $retrySleepMs;

    private ?string $accessToken = null;

    public function __construct()
    {
        $this->store = trim(
            (string) config('services.shopify.store')
        );

        $this->clientId = trim(
            (string) config('services.shopify.client_id')
        );

        $this->staticAccessToken = trim(
            (string) config('services.shopify.access_token')
        );

        $this->clientSecret = trim(
            (string) config('services.shopify.client_secret')
        );

        $this->apiVersion = trim(
            (string) config(
                'services.shopify.api_version',
                '2026-07'
            )
        );

        $this->connectTimeout = max(1, (int) config('services.shopify.connect_timeout', 5));
        $this->requestTimeout = max($this->connectTimeout, (int) config('services.shopify.request_timeout', 20));
        $this->retryAttempts = max(0, (int) config('services.shopify.retry_attempts', 2));
        $this->retrySleepMs = max(0, (int) config('services.shopify.retry_sleep_ms', 350));

        if (
            $this->store === '' ||
            (
                $this->staticAccessToken === '' &&
                (
                    $this->clientId === '' ||
                    $this->clientSecret === ''
                )
            )
        ) {
            throw new RuntimeException(
                'Shopify production integration is not configured.'
            );
        }
    }

    /**
     * Get a Shopify access token using the
     * client credentials flow.
     */
    private function getAccessToken(): string
    {
        if ($this->staticAccessToken !== '') {
            return $this->staticAccessToken;
        }

        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $cacheKey = $this->tokenCacheKey();
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            $this->accessToken = $cachedToken;

            return $cachedToken;
        }

        $url = sprintf(
            'https://%s/admin/oauth/access_token',
            $this->store
        );

        $response = $this->sendWithRetry(
            fn () => Http::asForm()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->requestTimeout)
                ->post($url, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]),
            'Shopify token request'
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'Unable to obtain Shopify access token. HTTP ' .
                $response->status() .
                '. Verify app installation, network access, and Shopify API credentials.'
            );
        }

        $token = $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        if (!$token) {
            throw new RuntimeException(
                'Shopify did not return an access token.'
            );
        }

        $this->accessToken = $token;
        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expiresIn - 120)));

        return $token;
    }

    /**
     * Execute a Shopify Admin GraphQL query.
     */
    public function graphql(
        string $query,
        array $variables = []
    ): array {
        $url = sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->store,
            $this->apiVersion
        );

        $payload = [
            'query' => $query,
        ];

        /*
         * Do not send an empty variables parameter.
         * Shopify rejects it for some requests.
         */
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = $this->sendGraphql($url, $payload);

        if (
            in_array($response->status(), [401, 403], true) &&
            $this->staticAccessToken === ''
        ) {
            $this->forgetCachedToken();
            $response = $this->sendGraphql($url, $payload);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Shopify GraphQL request failed. HTTP ' .
                $response->status() .
                '. Check Shopify API access, query limits, and network connectivity.'
            );
        }

        $json = $response->json();

        if (!empty($json['errors'])) {
            throw new RuntimeException(
                'Shopify GraphQL error: ' .
                json_encode($json['errors'])
            );
        }

        return $json['data'] ?? [];
    }

    private function sendGraphql(string $url, array $payload): Response
    {
        return $this->sendWithRetry(
            fn () => Http::withHeaders([
                'X-Shopify-Access-Token' => $this->getAccessToken(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->requestTimeout)
                ->post($url, $payload),
            'Shopify GraphQL request'
        );
    }

    private function sendWithRetry(callable $request, string $label): Response
    {
        $attempt = 0;
        $lastException = null;

        do {
            try {
                $response = $request();

                if (
                    !$this->isTransientStatus($response->status()) ||
                    $attempt >= $this->retryAttempts
                ) {
                    return $response;
                }
            } catch (ConnectionException $e) {
                $lastException = $e;

                if ($attempt >= $this->retryAttempts) {
                    break;
                }
            }

            $attempt++;

            if ($this->retrySleepMs > 0) {
                usleep($this->retrySleepMs * 1000);
            }
        } while ($attempt <= $this->retryAttempts);

        $message = $lastException?->getMessage() ?? 'transient Shopify network failure';

        throw new RuntimeException(sprintf(
            '%s failed after %d attempt(s). %s Timeout settings: connect %ss, request %ss.',
            $label,
            $this->retryAttempts + 1,
            $this->sanitizeHttpError($message),
            $this->connectTimeout,
            $this->requestTimeout
        ));
    }

    private function isTransientStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    private function tokenCacheKey(): string
    {
        return 'shopify:access-token:'.sha1($this->store.'|'.$this->clientId);
    }

    private function forgetCachedToken(): void
    {
        $this->accessToken = null;
        Cache::forget($this->tokenCacheKey());
    }

    private function sanitizeHttpError(string $message): string
    {
        $message = preg_replace('/client_secret=[^&\s]+/i', 'client_secret=[redacted]', $message);
        $message = preg_replace('/client_id=[^&\s]+/i', 'client_id=[redacted]', $message);
        $message = preg_replace('/shpss_[A-Za-z0-9_]+/i', '[redacted]', $message);

        if (str_contains(strtolower($message), 'timed out')) {
            return 'Connection timed out while contacting Shopify. Check internet access, DNS, firewall/proxy rules, and Shopify availability.';
        }

        return $message ?: 'No response from Shopify.';
    }

    /**
     * Read-only Shopify connection test.
     */
    public function testConnection(): array
    {
        $query = <<<'GRAPHQL'
query ProductionShopTest {
    shop {
        name
        myshopifyDomain
    }
}
GRAPHQL;

        return $this->graphql($query);
    }

    public function shopDomain(): string
    {
        return $this->store;
    }

    /**
     * Read the current Lucky Bags manufacturing backlog.
     *
     * This method is READ-ONLY.
     * It does not modify Shopify or the local database.
     */
    public function getProductionBacklog(
        string $mode = 'unfulfilled',
        int $first = 50
    ): array
    {
        $statusQuery = $mode === 'unfulfilled_partial'
            ? 'fulfillment_status:unfulfilled OR fulfillment_status:partial'
            : 'fulfillment_status:unfulfilled';

        $query = <<<'GRAPHQL'
query ProductionBacklog($first: Int!, $after: String, $statusQuery: String!) {
    orders(
        first: $first
        after: $after
        sortKey: CREATED_AT
        reverse: true
        query: $statusQuery
    ) {
        nodes {
            id
            name
            createdAt
            cancelledAt
            displayFinancialStatus
            displayFulfillmentStatus

            customer {
                displayName
            }

            lineItems(first: 100) {
                nodes {
                    id
                    name
                    quantity
                    unfulfilledQuantity
                    sku
                    variantTitle

                    product {
                        id
                        title
                        productType
                    }

                    image {
                        url
                    }
                }

                pageInfo {
                    hasNextPage
                    endCursor
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

        $items = [];
        $ordersExamined = 0;
        $after = null;

        do {
            $data = $this->graphql(
                $query,
                [
                    'first' => $first,
                    'after' => $after,
                    'statusQuery' => $statusQuery,
                ]
            );

            $orders = $data['orders']['nodes'] ?? [];
            $ordersExamined += count($orders);

            foreach ($orders as $order) {
                $lineItems = $order['lineItems']['nodes'] ?? [];

                if ($order['lineItems']['pageInfo']['hasNextPage'] ?? false) {
                    $lineItems = array_merge(
                        $lineItems,
                        $this->getRemainingLineItems(
                            $order['id'],
                            $order['lineItems']['pageInfo']['endCursor']
                        )
                    );
                }

                $order['lineItems']['nodes'] = $lineItems;
                $items = array_merge(
                    $items,
                    $this->rowsFromOrder($order, true, [], true)
                );
            }

            $pageInfo = $data['orders']['pageInfo'] ?? [];
            $after = $pageInfo['endCursor'] ?? null;
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
        } while ($hasNextPage);

        return [
            'items' => $items,

            /*
             * Number of Shopify orders examined.
             * This is NOT necessarily the number
             * of manufacturing orders.
             */
            'order_count' => $ordersExamined,

            /*
             * Number of eligible bag-production
             * line items after filtering.
             */
            'item_count' => count($items),

            'has_next_page' => false,

            'end_cursor' => null,
        ];
    }

    /**
     * Read Shopify rows for the selected backlog plus already-imported orders.
     * Known orders are fetched outside the selected filter so cancellations,
     * refunds, fulfilled lines and quantity changes can be reconciled in place.
     */
    public function getProductionReconciliation(
        string $mode = 'unfulfilled',
        array $knownOrderIds = [],
        array $knownLineItemIds = [],
        int $first = 50
    ): array {
        $backlog = $this->getProductionBacklog($mode, $first);
        $itemsByLine = [];

        foreach ($backlog['items'] as $item) {
            $item['mode_included'] = true;
            $item['shopify_shop'] = $this->shopDomain();
            $itemsByLine[$item['line_item_id']] = $item;
        }

        $known = $this->getKnownOrderRows($knownOrderIds, $knownLineItemIds);

        foreach ($known['items'] as $item) {
            $item['shopify_shop'] = $this->shopDomain();
            $existing = $itemsByLine[$item['line_item_id']] ?? null;
            $item['mode_included'] = (bool) ($existing['mode_included'] ?? false);
            $itemsByLine[$item['line_item_id']] = array_merge($existing ?? [], $item);
        }

        return [
            'items' => array_values($itemsByLine),
            'order_count' => $backlog['order_count'] + $known['order_count'],
            'item_count' => count(array_filter(
                $itemsByLine,
                fn (array $item) => (bool) ($item['is_active'] ?? true)
                    && (bool) ($item['mode_included'] ?? false)
            )),
            'has_next_page' => false,
            'end_cursor' => null,
        ];
    }

    private function getKnownOrderRows(array $knownOrderIds, array $knownLineItemIds): array
    {
        $knownOrderIds = array_values(array_unique(array_filter($knownOrderIds)));
        $knownLineItemIds = array_values(array_unique(array_filter($knownLineItemIds)));

        if (!$knownOrderIds) {
            return ['items' => [], 'order_count' => 0];
        }

        $query = <<<'GRAPHQL'
query ProductionKnownOrders($ids: [ID!]!) {
    nodes(ids: $ids) {
        ... on Order {
            id
            name
            createdAt
            cancelledAt
            displayFinancialStatus
            displayFulfillmentStatus

            customer {
                displayName
            }

            lineItems(first: 100) {
                nodes {
                    id
                    name
                    quantity
                    unfulfilledQuantity
                    sku
                    variantTitle

                    product {
                        id
                        title
                        productType
                    }

                    image {
                        url
                    }
                }

                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
    }
}
GRAPHQL;

        $items = [];
        $ordersExamined = 0;

        foreach (array_chunk($knownOrderIds, 50) as $ids) {
            $data = $this->graphql($query, ['ids' => $ids]);

            foreach ($data['nodes'] ?? [] as $order) {
                if (!$order) {
                    continue;
                }

                $ordersExamined++;
                $lineItems = $order['lineItems']['nodes'] ?? [];

                if ($order['lineItems']['pageInfo']['hasNextPage'] ?? false) {
                    $lineItems = array_merge(
                        $lineItems,
                        $this->getRemainingLineItems(
                            $order['id'],
                            $order['lineItems']['pageInfo']['endCursor']
                        )
                    );
                }

                $order['lineItems']['nodes'] = $lineItems;
                $items = array_merge(
                    $items,
                    $this->rowsFromOrder($order, false, $knownLineItemIds, false)
                );
            }
        }

        return [
            'items' => $items,
            'order_count' => $ordersExamined,
        ];
    }

    private function rowsFromOrder(
        array $order,
        bool $modeIncluded,
        array $knownLineItemIds = [],
        bool $eligibleOnly = true
    ): array {
        $rows = [];

        foreach ($order['lineItems']['nodes'] ?? [] as $line) {
            $lineId = (string) ($line['id'] ?? '');
            $productTitle = trim((string) ($line['product']['title'] ?? ''));
            $productType = trim((string) ($line['product']['productType'] ?? ''));
            $unfulfilledQuantity = (int) ($line['unfulfilledQuantity'] ?? 0);
            $knownLine = in_array($lineId, $knownLineItemIds, true);

            $inactiveReason = $this->inactiveReason(
                $order,
                $productTitle,
                $productType,
                $unfulfilledQuantity
            );

            if ($eligibleOnly && $inactiveReason !== null) {
                continue;
            }

            if (!$eligibleOnly && $inactiveReason !== null && !$knownLine) {
                continue;
            }

            $rows[] = [
                'line_item_id' => $lineId,
                'order_id' => $order['id'],
                'order_number' => $order['name'],
                'order_date' => $order['createdAt'],
                'customer' => $order['customer']['displayName'] ?? null,
                'product' => $productTitle !== '' ? $productTitle : (string) ($line['name'] ?? ''),
                'product_type' => $productType,
                'variant' => $line['variantTitle'] ?? null,
                'sku' => $line['sku'] ?? null,
                'quantity' => max(0, $unfulfilledQuantity),
                'shopify_image' => $line['image']['url'] ?? null,
                'financial_status' => $order['displayFinancialStatus'] ?? null,
                'fulfillment_status' => $order['displayFulfillmentStatus'] ?? null,
                'is_active' => $inactiveReason === null,
                'inactive_reason' => $inactiveReason,
                'mode_included' => $modeIncluded,
            ];
        }

        return $rows;
    }

    private function inactiveReason(
        array $order,
        string $productTitle,
        string $productType,
        int $unfulfilledQuantity
    ): ?string {
        if (!empty($order['cancelledAt'])) {
            return 'Shopify order cancelled';
        }

        if (($order['displayFinancialStatus'] ?? '') === 'REFUNDED') {
            return 'Shopify order refunded';
        }

        if ($productTitle === '') {
            return 'Shopify product title missing';
        }

        if (!in_array($productType, [
            'ACL Pro Stamped 2026',
            'ACL Pro Stamped 2027',
        ], true)) {
            return 'Not a stock bag production item';
        }

        if (stripos($productTitle, 'Custom') !== false) {
            return 'Custom artwork item excluded from stock workflow';
        }

        if ($unfulfilledQuantity <= 0) {
            return 'No outstanding Shopify quantity';
        }

        return null;
    }

    private function getRemainingLineItems(string $orderId, ?string $after): array
    {
        $query = <<<'GRAPHQL'
query ProductionBacklogLineItems($orderId: ID!, $after: String) {
    order(id: $orderId) {
        lineItems(first: 100, after: $after) {
            nodes {
                id
                name
                quantity
                unfulfilledQuantity
                sku
                variantTitle

                product {
                    id
                    title
                    productType
                }

                image {
                    url
                }
            }

            pageInfo {
                hasNextPage
                endCursor
            }
        }
    }
}
GRAPHQL;

        $items = [];

        do {
            $data = $this->graphql($query, [
                'orderId' => $orderId,
                'after' => $after,
            ]);

            $connection = $data['order']['lineItems'] ?? [];
            $items = array_merge($items, $connection['nodes'] ?? []);

            $after = $connection['pageInfo']['endCursor'] ?? null;
            $hasNextPage = (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
        } while ($hasNextPage);

        return $items;
    }
}
