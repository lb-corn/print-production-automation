<x-layouts.app heading="Production Overview" subheading="Orders, artwork readiness and factory print status">
@include('partials.order-sync-card')
<section class="overview-workspace" aria-label="Production overview workspace">
@include('partials.overview-tabs')
<div class="overview-tab-panel" id="production-overview-panel" role="tabpanel" aria-labelledby="overview-tab-bags" tabindex="0">
@include('partials.material-catalog-status')
<div class="stats"><div class="stat"><span>Current bag order lines</span><b>{{$total}}</b><em>{{$openOrderCount??0}} Shopify orders</em></div><div class="stat green"><span>Ready for Print</span><b>{{empty($materialCatalogError)?$ready:'—'}}</b><em>Artwork approved and ready</em></div><div class="stat amber"><span>Needs review</span><b>{{$review}}</b><em>Artwork or material review</em></div><div class="stat purple"><span>Custom artwork</span><b>{{$custom}}</b><em>Custom print order lines</em></div></div>
@include('partials.dashboard-charts')
<div class="dashboard-actions">
@if(auth()->user()->allows('bags.artwork.view'))<a class="dashboard-action" href="{{route('production')}}"><span class="dashboard-action-icon">@include('partials.nav-icon',['name'=>'verification'])</span><span><strong>Artwork review</strong><small>Check and approve production files</small></span><b>{{$review}}</b><span aria-hidden="true">↗</span></a>@endif
@if(auth()->user()->allows('bags.queue.view'))<a class="dashboard-action" href="{{route('queue')}}"><span class="dashboard-action-icon">@include('partials.nav-icon',['name'=>'queue'])</span><span><strong>Ready for Print</strong><small>Prepare approved work</small></span><b>{{$ready}}</b><span aria-hidden="true">↗</span></a>@endif
@if(auth()->user()->allows('bags.batches.view'))<a class="dashboard-action" href="{{route('batches')}}"><span class="dashboard-action-icon">@include('partials.nav-icon',['name'=>'batches'])</span><span><strong>Print batches</strong><small>Track preparation and handoff</small></span><span aria-hidden="true">↗</span></a>@endif
</div>
<div class="dashboard-details-grid">
<details class="panel dashboard-detail"><summary><span>Material requirements <small>{{$summary['total_sets']}} approved sets · {{number_format($summary['printable_yards'],3)}} yd to print</small></span><span>View details</span></summary><div class="dashboard-detail-body">
@if(empty($materialCatalogError))@include('partials.material-requirements')@else<p class="chart-empty">Material totals are temporarily unavailable. Refresh the sheet settings before preparing print jobs.</p>@endif
</div></details>
<details class="panel dashboard-detail"><summary><span>Recent batches <small>Latest factory handoffs</small></span><span>View details</span></summary><div class="dashboard-detail-body">
@forelse($batches->take(3) as $batch)<a class="batch-row" href="{{auth()->user()->allows('bags.batches.view')?route('batch.show',$batch):route('dashboard')}}"><div><b>{{$batch->batch_id}}</b><span>{{\App\Services\PartnerContext::label($batch->partner)}} · {{$batch->order_count}} orders · {{$batch->artwork_file_count}} files</span></div><strong class="badge">{{str_replace('_',' ',$batch->status)}}</strong></a>@empty<p class="chart-empty">No print batches yet.</p>@endforelse
</div></details>
</div>
</div></section>
</x-layouts.app>
