

@php
    use App\Enums\ShopifyFulfillmentStatus;

    $currentUri = request()->getRequestUri();
    $currentUser = auth()->user();
    $canEditRemark = $currentUser?->allows('apparel.remarks.edit') ?? false;
    $canProcessRemark = $currentUser?->allows('apparel.remarks.approve') ?? false;
    $canEditTracking = $currentUser?->allows('apparel.tracking.edit') ?? false;
    $badgeClass = function (?string $status): string {
        return match ($status) {
            'UNFULFILLED' => 'badge-yellow',
            'PARTIALLY_FULFILLED' => 'badge-orange',
            'FULFILLED' => 'badge-green',
            'IN_PROGRESS' => 'badge-blue',
            'ON_HOLD', 'RESTOCKED' => 'badge-gray',
            'SCHEDULED' => 'badge-purple',
            default => 'badge-neutral',
        };
    };
@endphp

<x-layouts.app heading="Apparel Orders" subheading="Jersey and hoodie production, tracking and remarks">
<link rel="stylesheet" href="{{asset('css/apparel.css')}}?v={{filemtime(public_path('css/apparel.css'))}}">
<div class="apparel-workspace">
<div class="apparel-report-content">
<section class="panel apparel-sync-toolbar" aria-label="Shopify synchronization">
<div class="apparel-sync-copy">
<div class="apparel-sync-title"><strong>Shopify sync</strong><span class="sync-health {{$syncHealth['class']}}" title="{{$syncHealth['message']}}" aria-label="{{$syncHealth['label']}}. {{$syncHealth['message']}}">{{$syncHealth['label']}}</span><span class="apparel-sync-timestamp">Last sync: {{$lastSyncLabel}} @if($lastSyncRelative) &middot; {{$lastSyncRelative}} @endif</span></div>
@if($syncHealth['state']==='error')
<p class="apparel-sync-error" role="alert">{{$syncHealth['message']}}</p>
@endif
</div>
@if(auth()->user()->allows('apparel.sync.edit'))
<form method="post" action="{{route('apparel.orders.sync')}}" data-sync-form>@csrf<button class="button button-primary" type="submit" data-default-label="Sync Orders" data-loading-label="Syncing..."><span class="button-spinner" aria-hidden="true"></span><span class="button-label">Sync Orders</span></button></form>
@endif
</section>
        <section class="summary-grid" aria-label="Filter by fulfillment status">
            @foreach([
                'UNFULFILLED'=>['Unfulfilled','unfulfilled','dot-warning'],
                'PARTIALLY_FULFILLED'=>['Partially Fulfilled','partially_fulfilled','dot-orange'],
                'FULFILLED'=>['Fulfilled','fulfilled','dot-success'],
                'all'=>['Total Apparel Items','total','dot-info']
            ] as $status=>[$label,$countKey,$dot])
            <a class="summary-card summary-filter" href="{{route('apparel.orders.index',['fulfillment_status'=>$status,'per_page'=>$filters['per_page'] ?? 25])}}" @if(($filters['fulfillment_status'] ?? 'UNFULFILLED')===$status) aria-current="page" @endif aria-label="{{$label}}: {{number_format($summary[$countKey])}} order lines. Show matching orders.">
                <span class="summary-label"><i class="summary-dot {{$dot}}" aria-hidden="true"></i>{{$label}}</span>
                <strong>{{ number_format($summary[$countKey]) }}</strong>
            </a>
            @endforeach
        </section>

        <section class="panel filter-card">
            <div class="apparel-filter-heading"><h2>Filter orders</h2><span>Find orders by number, product or tracking</span></div>
            <form method="GET" action="{{ route('apparel.orders.index') }}" class="filters" data-filter-form>
                <div class="apparel-filter-fields">
                <label class="filter-search">
                    <span>Search</span>
                    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}">
                </label>

                <label>
                    <span>Apparel/product</span>
                    <select name="product">
                        <option value="">All products</option>
                        @foreach ($filterOptions['products'] as $product)
                            <option value="{{ $product }}" @selected(($filters['product'] ?? '') === $product)>{{ $product }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>Variant</span>
                    <select name="variant">
                        <option value="">All variants</option>
                        @foreach ($filterOptions['variants'] as $variant)
                            <option value="{{ $variant }}" @selected(($filters['variant'] ?? '') === $variant)>{{ $variant }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>Color</span>
                    <select name="color">
                        <option value="">All colors</option>
                        @foreach ($filterOptions['colors'] as $color)
                            <option value="{{ $color }}" @selected(($filters['color'] ?? '') === $color)>{{ $color }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>Size</span>
                    <select name="size">
                        <option value="">All sizes</option>
                        @foreach ($filterOptions['sizes'] as $size)
                            <option value="{{ $size }}" @selected(($filters['size'] ?? '') === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>Status</span>
                    <select name="fulfillment_status">
                        <option value="all" @selected(($filters['fulfillment_status'] ?? '') === 'all')>All statuses</option>
                        @foreach ($filterOptions['statuses'] as $status)
                            <option value="{{ $status }}" @selected(($filters['fulfillment_status'] ?? 'UNFULFILLED') === $status)>{{ str_replace('_', ' ', $status) }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>Per page</span>
                    <select name="per_page">
                        @foreach ([25, 50, 100] as $perPage)
                            <option value="{{ $perPage }}" @selected((int) ($filters['per_page'] ?? 25) === $perPage)>{{ $perPage }}</option>
                        @endforeach
                    </select>
                </label>

                </div>
                <div class="filter-actions">
                    <button type="submit" class="button button-primary" data-default-label="Apply" data-loading-label="Loading...">
                        <span class="button-spinner" aria-hidden="true"></span>
                        <span class="button-label">Apply</span>
                    </button>
                    <a href="{{ route('apparel.orders.index') }}" class="button button-subtle" data-reset-link>Reset</a>
                </div>
            </form>
        </section>

        <section class="panel table-panel" data-table-panel>
            <div class="apparel-report-heading"><div><h2>Apparel orders</h2><p>{{number_format($orders->total())}} matching order {{$orders->total()===1?'line':'lines'}}</p></div><span>{{str_replace('_',' ',($filters['fulfillment_status'] ?? 'UNFULFILLED')==='all'?'All statuses':$filters['fulfillment_status'])}}</span></div>
            <div class="table-wrap">
                <table class="orders-table apparel-order-tiles">
                    <thead>
                    <tr>
                        <th>Order</th>
                        <th>Apparel</th>
                        <th>Details</th>
                        <th>Production</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($orders as $order)
                        @php
                            $locked = !$canEditTracking || $order->shopify_fulfillment_status === ShopifyFulfillmentStatus::Fulfilled->value || $order->shopify_cancelled_at !== null;
                            $storefrontUrl = $order->product_handle && $storefrontBase ? $storefrontBase.$order->product_handle : null;
                            $personalizationFields = $order->manufacturerPersonalizationFields();
                            $previewField = $personalizationFields[0] ?? null;
                            $hasRemark = filled($order->remark);
                            $remarkProcessed = $hasRemark && $order->remark_processed_at !== null;
                            $remarkRowClass = $hasRemark ? ($remarkProcessed ? 'remark-processed-row' : 'remark-active-row') : '';
                            $variantTitle = trim((string) $order->variant_title);
                            $detailsPrimary = $variantTitle !== '' && strcasecmp($variantTitle, 'Default Title') !== 0 ? $variantTitle : null;
                            $detailValues = collect([$order->style, $order->color, $order->size])
                                ->map(fn ($value) => trim((string) $value))
                                ->filter()
                                ->unique(fn ($value) => mb_strtolower($value))
                                ->values();

                            if ($detailsPrimary && $detailValues->contains(fn ($value) => strcasecmp($detailsPrimary, $value) === 0)) {
                                $detailsPrimary = null;
                            }

                            if ($detailsPrimary && collect(explode(' / ', $detailsPrimary))->every(fn($part) => $detailValues->contains(fn($value) => strcasecmp(trim($part), $value) === 0))) {
                                $detailsPrimary = null;
                            }
                            $detailMeta = collect([
                                ['label'=>'Style','value'=>$order->style],
                                ['label'=>'Color','value'=>$order->color],
                                ['label'=>'Size','value'=>$order->size],
                                ['label'=>'Qty','value'=>$order->quantity],
                            ])->filter(fn($meta)=>$meta['value']!==null && $meta['value']!=='');
                        @endphp
                        <tr class="{{ trim('order-card '.$remarkRowClass) }}">
                            <td class="order-section" data-label="Order">
                                <div class="order-cell">
                                    <div class="order-number">{{ $order->shopify_order_number }}</div>
                                    <div class="muted">{{ $order->ordered_at?->format('M j, Y') }}</div>
                                </div>
                                <div class="badge-stack compact">
                                    <span class="badge {{ $badgeClass($order->shopify_fulfillment_status) }}">{{ str_replace('_', ' ', $order->shopify_fulfillment_status) }}</span>
                                    @if ($order->shopify_financial_status && strtoupper($order->shopify_financial_status)!=='PAID')
                                        <span class="badge badge-neutral">{{ str_replace('_', ' ', $order->shopify_financial_status) }}</span>
                                    @endif
                                    @if ($order->shopify_cancelled_at)
                                        <span class="badge badge-red">Cancelled</span>
                                    @endif
                                </div>
                            </td>
                            <td class="jersey-section" data-label="Jersey">
                                <div class="jersey-cell">
                                    @if ($order->image_url)
                                        <a class="thumbnail-link" href="{{ $order->image_url }}" target="_blank" rel="noopener noreferrer" title="Open full image" aria-label="Open full image for {{ $order->product_title }}">
                                            <img src="{{ $order->image_url }}" alt="" loading="lazy">
                                        </a>
                                    @else
                                        <div class="thumb-empty">No image</div>
                                    @endif
                                    <div class="product-content">
                                        <div class="product-title" title="{{ $order->product_title }}">{{ $order->product_title }}</div>
                                        @if ($storefrontUrl)
                                            <a class="storefront-link" href="{{ $storefrontUrl }}" target="_blank" rel="noreferrer">Storefront</a>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="details-section" data-label="Details">
                                @if ($detailsPrimary)
                                    <div class="details-primary" data-detail-label="Variant">{{ $detailsPrimary }}</div>
                                @endif
                                <div class="details-meta">
                                    @foreach ($detailMeta as $meta)
                                        <span class="detail-meta-item"><small>{{$meta['label']}}</small><b>{{$meta['value']}}</b></span>
                                    @endforeach
                                </div>
                                @if ($personalizationFields !== [])
                                    <div class="personalization-inline">
                                        @foreach ($personalizationFields as $field)
                                            <span class="personalization-row">
                                                <span>{{ $field['key'] }}:</span>
                                                <strong>{{ $field['value'] === '' ? '-' : $field['value'] }}</strong>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="production-section" data-label="Production">
                                @php($trackingFormId = 'tracking-form-'.$order->id)
                                <div class="production-stack">
                                    <form id="{{ $trackingFormId }}" method="POST" action="{{ route('apparel.orders.tracking.update', $order) }}" class="tracking-form production-tracking-form" data-disable-on-submit>
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="return_to" value="{{ $currentUri }}">
                                        @if(!$locked)
                                        <div class="production-tracking-row">
                                            <input type="text" name="tracking_number" value="{{ $order->tracking_number }}" maxlength="255" placeholder="Enter tracking number" required @disabled($locked) aria-label="Tracking for {{$order->shopify_order_number}}">
                                            <button type="submit" class="button button-small button-save tracking-save-button" @disabled($locked)>Save</button>
                                        </div>
                                        @else
                                        <div class="apparel-tracking-readonly"><span>Tracking</span><strong>{{$order->tracking_number ?: 'Not added'}}</strong></div>
                                        @endif
                                        @if ($order->shopify_fulfillment_status === ShopifyFulfillmentStatus::Fulfilled->value)
                                            <div class="tracking-helper tracking-helper-fulfilled">Fulfilled in Shopify</div>
                                        @elseif ($order->shopify_cancelled_at)
                                            <div class="tracking-helper tracking-helper-cancelled">Cancelled order</div>
                                        @endif
                                        @if ($order->tracking_updated_at)
                                            <div class="tracking-helper">Updated {{ $order->tracking_updated_at->format('M j, Y g:i A') }} by {{ $order->trackingUpdatedBy?->name ?? 'Unknown user' }}</div>
                                        @endif
                                    </form>

                                    @if ($hasRemark)
                                        <div class="remark-inline-summary {{ $remarkProcessed ? 'remark-inline-summary-processed' : 'remark-inline-summary-active' }}">
                                            <div class="remark-inline-text"><span aria-hidden="true">{!! $remarkProcessed ? '&#10003;' : '&#9888;' !!}</span> {{ $order->remark }}</div>
                                            <div class="remark-inline-meta">
                                                @if ($remarkProcessed)
                                                    <span>Processed</span>
                                                    <span aria-hidden="true">&bull;</span>
                                                @endif
                                                {{ $order->remarkUpdatedBy?->name ?? 'Lucky Bags Admin' }}
                                                @if ($order->remark_updated_at)
                                                    <span aria-hidden="true">&bull;</span> {{ $order->remark_updated_at->format('M j, Y g:i A') }}
                                                @endif
                                            </div>
                                        </div>
                                    @endif

                                    <div class="production-secondary-actions">
                                        @if ($canEditRemark)
                                            <details class="remark-popover {{ $hasRemark ? ($remarkProcessed ? 'remark-popover-processed' : 'remark-popover-active') : 'remark-popover-empty' }}">
                                                <summary class="remark-tag row-action-small">
                                                    <span>{{ $hasRemark ? 'Edit Remark' : '+ Add Remark' }}</span>
                                                    @if ($remarkProcessed)
                                                        <span class="remark-status-badge">Processed</span>
                                                    @endif
                                                </summary>
                                                <div class="remark-tooltip">
                                                    <form method="POST" action="{{ route('apparel.orders.remark.update', $order) }}" class="remark-form">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="return_to" value="{{ $currentUri }}">
                                                        <textarea name="remark" rows="3" placeholder="Add remark">{{ $order->remark }}</textarea>
                                                        <button type="submit" class="button button-small button-primary">Save Remark</button>
                                                    </form>
                                                    @if ($hasRemark)
                                                        <p class="remark-meta">
                                                            Remark by {{ $order->remarkUpdatedBy?->name ?? 'Lucky Bags Admin' }}
                                                            @if ($order->remark_updated_at)
                                                                &middot; {{ $order->remark_updated_at->format('M j, Y g:i A') }}
                                                            @endif
                                                        </p>
                                                    @endif
                                                </div>
                                            </details>
                                        @endif
                                        @if ($canProcessRemark && $hasRemark)
                                            <form method="POST" action="{{ route('apparel.orders.remark.processed.update', $order) }}" class="remark-processed-form">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="return_to" value="{{ $currentUri }}">
                                                <input type="hidden" name="processed" value="0">
                                                <label class="remark-checkbox-row row-action-small">
                                                    <input type="checkbox" name="processed" value="1" @checked($remarkProcessed) onchange="this.form.submit()">
                                                    <span>Remark processed</span>
                                                </label>
                                            </form>
                                        @endif
                                        <button
                                            type="button"
                                            class="button button-small button-view row-action-small view-order"
                                            title="Preview order details"
                                            data-url="{{ route('apparel.orders.show', $order) }}"
                                            data-preview-product="{{ $order->product_title }}"
                                            data-preview-variant="{{ $order->variant_title }}"
                                            data-preview-customer-label="{{ $previewField['key'] ?? '' }}"
                                            data-preview-customer-value="{{ $previewField['value'] ?? $order->display_name ?? '' }}"
                                            data-preview-status="{{ $order->shopify_fulfillment_status }}"
                                        >
                                            <svg class="button-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                                <path d="M1.6 8s2.3-4 6.4-4 6.4 4 6.4 4-2.3 4-6.4 4-6.4-4-6.4-4Z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                                <circle cx="8" cy="8" r="2" fill="none" stroke="currentColor" stroke-width="1.4"/>
                                            </svg>
                                            <span>View</span>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="empty-state">No apparel orders found.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="table-loading-overlay" aria-hidden="true">
                <span class="button-spinner"></span>
                <span>Loading...</span>
            </div>

            {{ $orders->links('apparel.pagination') }}
        </section>


</div>
    @include('apparel.orders.partials.order-modal')

<script src="{{asset('js/apparel-orders.js')}}?v={{filemtime(public_path('js/apparel-orders.js'))}}" defer></script>
</div>
</x-layouts.app>
