<x-layouts.app heading="Production Overview" subheading="Apparel orders, tracking and production remarks">
<section class="overview-workspace" aria-label="Production overview workspace">
@include('partials.overview-tabs')
<div class="overview-tab-panel" id="production-overview-panel" role="tabpanel" aria-labelledby="overview-tab-apparel" tabindex="0">
<div class="stats"><div class="stat amber"><span>Unfulfilled</span><b>{{$summary['unfulfilled']}}</b><em>Apparel order lines</em></div><div class="stat"><span>Partially fulfilled</span><b>{{$summary['partially_fulfilled']}}</b><em>Production in progress</em></div><div class="stat green"><span>Fulfilled</span><b>{{$summary['fulfilled']}}</b><em>Shopify fulfillment status</em></div><div class="stat purple"><span>Total apparel items</span><b>{{$summary['total']}}</b><em>Jersey and hoodie order lines</em></div></div>
<section class="panel"><div class="panel-head"><div><h2>Apparel Production</h2><p>{{$summary['pending_remarks']}} remarks awaiting processing</p></div>
@if(auth()->user()->allows('apparel.orders.view'))
<a class="primary" href="{{route('apparel.orders.index')}}">View apparel orders</a>
@endif
</div><p class="muted">Last successful sync: {{$lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->timezone(config('app.timezone'))->format('M j, Y g:i A') : 'Not synced yet'}}</p>
@if(auth()->user()->allows('apparel.orders.view'))
@forelse($recentOrders as $order)
<a class="batch-row" href="{{route('apparel.orders.index',['search'=>$order->shopify_order_number,'fulfillment_status'=>'all'])}}"><div><b>{{$order->shopify_order_number}}</b><span>{{$order->product_title}} &middot; {{$order->variant_title}} &middot; Qty {{$order->quantity}}</span></div><strong class="badge">{{str_replace('_',' ',$order->shopify_fulfillment_status)}}</strong></a>
@empty
<div class="empty">No apparel orders imported yet. Use Sync Orders after Shopify access is configured.</div>
@endforelse
@endif
</section>
</div></section>
</x-layouts.app>
