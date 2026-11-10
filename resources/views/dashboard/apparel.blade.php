<x-layouts.app heading="Production Overview" subheading="Orders, artwork readiness and factory print status">
@include('partials.order-sync-card')
<section class="overview-workspace" aria-label="Production overview workspace">
@include('partials.overview-tabs')
<div class="overview-tab-panel apparel-overview" id="production-overview-panel" role="tabpanel" aria-labelledby="overview-tab-apparel" tabindex="0">
<div class="stats apparel-overview-stats">
@foreach([
    ['Waiting to start',$summary['unfulfilled'],'Unfulfilled order lines','amber',['fulfillment_status'=>'UNFULFILLED']],
    ['In progress',$summary['partially_fulfilled'],'Partially fulfilled lines','',['fulfillment_status'=>'PARTIALLY_FULFILLED']],
    ['Remarks to review',$summary['pending_remarks'],'Notes awaiting processing','purple',['fulfillment_status'=>'all','attention'=>'pending_remarks']],
    ['Open units',$summary['open_units'],$summary['open_lines'].' open order lines','green',['fulfillment_status'=>'all','attention'=>'open']]
] as [$label,$count,$description,$color,$query])
<div class="stat {{$color}}"><span>{{$label}}</span><b>{{$count}}</b><em>{{$description}}</em>@if(auth()->user()->allows('apparel.orders.view'))<a class="overview-stat-link" href="{{route('apparel.orders.index',$query)}}" aria-label="View {{$label}}">View orders →</a>@endif</div>
@endforeach
</div>
@if(auth()->user()->allows('apparel.orders.view'))
<div class="apparel-overview-grid">
<section class="panel apparel-work-queue">
    <div class="panel-head"><div><h2>Open production queue</h2><p>Oldest open orders first &middot; {{$summary['open_units']}} units across open lines</p></div><a class="chip" href="{{route('apparel.orders.index',['fulfillment_status'=>'all','attention'=>'open'])}}">View open orders →</a></div>
    @forelse($attentionOrders as $order)
    <a class="apparel-work-row" href="{{route('apparel.orders.index',['search'=>$order->shopify_order_number,'fulfillment_status'=>'all','attention'=>'open'])}}">
        <div class="apparel-work-copy"><div class="apparel-work-title"><strong>{{$order->shopify_order_number}}</strong><span>{{$order->ordered_at ? $order->ordered_at->timezone(config('app.timezone'))->format('M j, Y') : 'Order date unavailable'}}</span></div><p>{{$order->product_title}}</p><small>{{$order->variant_title ?: 'Standard variant'}} &middot; Qty {{$order->quantity}}</small>
        @if(filled($order->remark) && !$order->remark_processed_at)<span class="apparel-work-flag">Remark pending</span>@endif</div>
        <span class="apparel-work-state {{$order->shopify_fulfillment_status==='UNFULFILLED'?'waiting':'progress'}}">{{$order->shopify_fulfillment_status==='UNFULFILLED'?'Waiting to start':'In progress'}}</span>
    </a>
    @empty<div class="apparel-overview-empty"><strong>No open apparel production</strong><p>There are no unfulfilled or partially fulfilled order lines. Sync orders above to check for new work.</p></div>@endforelse
</section>
<div class="apparel-attention-column">
<section class="panel apparel-attention-panel"><div class="panel-head"><div><h2>Needs attention</h2><p>Follow up on the next action</p></div></div>
<a class="apparel-attention-link" href="{{route('apparel.orders.index',['fulfillment_status'=>'all','attention'=>'missing_tracking'])}}"><span><strong>Tracking not added</strong><small>In-progress order lines without tracking</small></span><b>{{$summary['missing_tracking']}}</b></a>
<a class="apparel-attention-link" href="{{route('apparel.orders.index',['fulfillment_status'=>'all','attention'=>'pending_remarks'])}}"><span><strong>Pending remarks</strong><small>Review and mark notes as processed</small></span><b>{{$summary['pending_remarks']}}</b></a>
@foreach($pendingRemarks as $order)<a class="apparel-remark-preview" href="{{route('apparel.orders.index',['search'=>$order->shopify_order_number,'fulfillment_status'=>'all','attention'=>'pending_remarks'])}}"><strong>{{$order->shopify_order_number}}</strong><p>{{\Illuminate\Support\Str::limit($order->remark,140)}}</p></a>@endforeach
</section>
<section class="panel apparel-history-summary"><h2>Order history</h2><p><strong>{{$summary['fulfilled']}}</strong> fulfilled lines &middot; <strong>{{$summary['total']}}</strong> total imported lines</p><a href="{{route('apparel.orders.index',['fulfillment_status'=>'all'])}}">View all apparel orders →</a></section>
</div>
</div>
@endif
</div></section>
</x-layouts.app>
