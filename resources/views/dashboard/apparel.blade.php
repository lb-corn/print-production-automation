<x-layouts.app heading="Production Overview" subheading="Orders, artwork readiness and factory print status">
@include('partials.order-sync-card')
<section class="overview-workspace" aria-label="Production overview workspace">
@include('partials.overview-tabs')
<div class="overview-tab-panel apparel-overview" id="production-overview-panel" role="tabpanel" aria-labelledby="overview-tab-apparel" tabindex="0">
<div class="stats apparel-overview-stats">
@foreach([
    ['Imported apparel lines',$summary['total'],'Across the selected companies',''],
    ['Open units',$summary['open_units'],$summary['open_lines'].' open order lines','amber'],
    ['Fulfilled lines',$summary['fulfilled'],'Completed Shopify fulfillment','green'],
    ['Needs tracking',$summary['missing_tracking'],'In-progress lines without tracking','purple']
] as [$label,$count,$description,$color])
<div class="stat {{$color}}"><span>{{$label}}</span><b>{{$count}}</b><em>{{$description}}</em></div>
@endforeach
</div>
@include('partials.dashboard-charts')
@if(auth()->user()->allows('apparel.orders.view'))
<div class="dashboard-actions">
@foreach([
['Open orders',$summary['open_lines'],'Review waiting and in-progress lines','apparel-production',['fulfillment_status'=>'all','attention'=>'open']],
['Tracking to add',$summary['missing_tracking'],'Add shipment details','queue',['fulfillment_status'=>'all','attention'=>'missing_tracking']],
['Remarks to review',$summary['pending_remarks'],'Review production notes','verification',['fulfillment_status'=>'all','attention'=>'pending_remarks']]
] as [$label,$count,$hint,$icon,$query])
<a class="dashboard-action" href="{{route('apparel.orders.index',$query)}}"><span class="dashboard-action-icon">@include('partials.nav-icon',['name'=>$icon])</span><span><strong>{{$label}}</strong><small>{{$hint}}</small></span><b>{{$count}}</b><span aria-hidden="true">↗</span></a>
@endforeach
</div>
<details class="panel dashboard-detail"><summary><span>Next orders to work on <small>Oldest open lines first</small></span><span>View queue</span></summary><div class="dashboard-detail-body">
@forelse($attentionOrders->take(3) as $order)
<a class="apparel-work-row" href="{{route('apparel.orders.index',['search'=>$order->shopify_order_number,'fulfillment_status'=>'all','partner'=>$order->partner])}}">
<div class="apparel-work-copy"><div class="apparel-work-title"><strong>{{$order->shopify_order_number}}</strong><span class="partner-badge">{{\App\Services\PartnerContext::label($order->partner)}}</span></div><p>{{$order->product_title}}</p><small>{{$order->variant_title ?: 'Standard variant'}} · Qty {{$order->quantity}}</small></div>
<span class="apparel-work-state {{$order->shopify_fulfillment_status==='UNFULFILLED'?'waiting':'progress'}}">{{$order->shopify_fulfillment_status==='UNFULFILLED'?'Waiting':'In progress'}}</span>
</a>
@empty<p class="chart-empty">No open apparel orders. Sync above to check for new work.</p>@endforelse
</div></details>
@endif
</div></section>
</x-layouts.app>
