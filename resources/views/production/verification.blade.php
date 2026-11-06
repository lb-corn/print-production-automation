<x-layouts.app heading="Production Verification" subheading="Compare Shopify order artwork against factory Drive artwork">
@if(auth()->user()->allows('bags.artwork.edit') || auth()->user()->allows('bags.sync.edit'))
<section class="production-refresh" aria-labelledby="production-refresh-heading">
<div class="production-refresh-heading"><h2 id="production-refresh-heading">Orders &amp; artwork</h2><p>Print batches stay locked. Changed artwork needs approval again.</p></div>
<div class="production-refresh-actions">
@if(auth()->user()->allows('bags.sync.edit'))<form method="post" action="{{route('production.sync-backlog')}}" data-backlog-form>
@csrf<input type="hidden" name="return_to" value="production">
<div><strong>Shopify backlog</strong><p>Pull current orders and match their Drive artwork.</p></div>
<button type="submit" class="primary">Refresh backlog</button>
</form>@endif
@if(auth()->user()->allows('bags.artwork.edit'))<form method="post" action="{{route('production.rematch')}}" data-rematch-form>
@csrf<input type="hidden" name="confirm_rematch" value="1">
<div><strong>Drive artwork</strong><p>Recheck artwork for orders already in this portal.</p></div>
<button type="submit" class="chip">Rematch Artwork</button>
</form>@endif
</div>
</section>
@endif
<div class="review-filter-heading">
<strong>Review status</strong>
<button type="button" class="chip active" data-filter="any" data-filter-group="state">All statuses</button>
</div>
<div class="review-summary" aria-label="Filter by production status">
@foreach(['approved'=>'Approved','rejected'=>'Rejected','pending'=>'Pending','in_print'=>'In print','completed'=>'Completed','failed'=>'Needs attention'] as $state=>$label)
<button type="button" class="review-total {{$state}}" data-filter="{{$state}}" data-filter-group="state">
<span>{{$label}}</span>
<strong>{{$reviewSummary[$state]}}</strong>
</button>
@endforeach
</div>
<div class="toolbar design-toolbar" aria-label="Filter by design type">
<span>Design type</span>
<div>
<button type="button" data-filter="all" data-filter-group="type" class="chip active">All designs <span class="design-count" data-design-count="all">{{$items->count()}}</span></button>
<button type="button" data-filter="single" data-filter-group="type" class="chip">Single side <span class="design-count" data-design-count="single">{{$items->filter(fn($i)=>!$i->is_custom && $i->design_type==='single')->count()}}</span></button>
<button type="button" data-filter="double" data-filter-group="type" class="chip">Double side <span class="design-count" data-design-count="double">{{$items->filter(fn($i)=>!$i->is_custom && $i->design_type==='double')->count()}}</span></button>
<button type="button" data-filter="custom" data-filter-group="type" class="chip">Custom <span class="design-count" data-design-count="custom">{{$items->where('is_custom',true)->count()}}</span></button>
</div>
</div>
@if(auth()->user()->allows('bags.artwork.approve'))<form id="bulk-review" class="bulk-toolbar" method="post" action="{{route('production.bulk-verify')}}">

@csrf
<label class="bulk-select">
<input type="checkbox" data-select-visible> Select visible</label>
<span class="bulk-count" data-selection-count aria-live="polite">0 selected</span>
<button type="button" class="chip" data-clear-selection disabled>Clear</button>
<div class="bulk-buttons">
<button type="submit" class="reject" name="decision" value="WRONG" data-bulk-action disabled>Reject selected</button>
<button type="submit" class="approve" name="decision" value="CORRECT" data-bulk-action disabled>Approve selected</button>
</div>
</form>
@endif<div class="cards verification-cards">
@forelse($items as $item)
@php
    $approved = $item->workflow_state === 'approved';
    $rejected = $item->workflow_state === 'rejected';
    $stale = $item->decision && !$item->approval_current;
    $reviewLabel = $item->production_batch ? ($item->workflow_state === 'completed' ? 'Completed' : ($item->workflow_state === 'failed' ? 'Print failed' : 'In print · '.str_replace('_', ' ', strtolower($item->production_batch['status'])))) : ($approved ? 'Verified' : ($rejected ? 'Rejected' : ($stale ? 'Artwork changed · Re-verify' : ($item->is_custom ? 'Custom · Pending' : 'Not verified'))));
@endphp
<details data-item-id="{{$item->id}}" class="job-card {{$approved?'approved-card':($rejected?'wrong-card':($item->workflow_state === 'completed'?'completed-card':($item->production_batch?'in-print-card':'')))}}" {{ (!$approved && !$rejected && !$item->production_batch) ? 'open' : '' }} data-workflow="{{$item->workflow_state}}" data-type="{{$item->design_type}}" data-custom="{{$item->is_custom?'yes':'no'}}" data-ready="{{$item->ready_for_print?'yes':'no'}}" data-decision="{{$item->decision?->decision??''}}" data-status="{{$item->match_status}}">
<summary class="job-title">
@if(auth()->user()->allows('bags.artwork.approve') && !$item->production_batch && !$item->is_custom && $item->valid_artwork)<input class="review-select" type="checkbox" name="items[]" value="{{$item->id}}" form="bulk-review" aria-label="Select {{$item->order_number}} {{$item->product}} {{$item->variant}}">
@endif<div>
<span>{{$item->order_number}}</span>
<h2>{{$item->product}}</h2>
</div>
<span class="review-header-labels">
@include('partials.review-material-labels',['item'=>$item])
<span class="review-state">{{$reviewLabel}}</span>
</span>
<span class="expand-hint" aria-hidden="true">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
<path d="m6 9 6 6 6-6"/>
</svg>
</span>
</summary>
<div class="meta">
<div>
<span>Variant</span>
<b>{{$item->variant?:'—'}}</b>
</div>
<div>
<span>SKU</span>
<b>{{$item->sku?:'—'}}</b>
</div>
<div>
<span>Qty</span>
<b>{{$item->quantity}}</b>
</div>
<div>
<span>Series</span>
<b>{{$item->series_code}}</b>
</div>
<div>
<span>Type</span>
<b>{{$item->design_type}}</b>
</div>
<div>
<span>Color</span>
<b>{{$item->color?:'—'}}</b>
</div>
</div>
<div class="compare {{count($item->display_matches??[]) > 1 ? 'compare-double' : ''}}">
<div class="art">
<label>{{$item->is_custom?'CUSTOM ORDER PREVIEW':'SHOPIFY ORDER IMAGE'}}</label>
@if($item->shopify_image)<img src="{{$item->shopify_image}}" alt="Shopify order artwork for {{$item->product}}" loading="lazy">
@else<div class="placeholder">No Shopify image</div>
@endif<p>{{$item->product}}</p>
</div>
<div class="drive-grid {{$item->design_type==='single' || !count($item->display_matches??[]) ? 'one' : ''}}">
@if($item->is_custom)
@include('partials.custom-artwork-upload',['item'=>$item])
@elseif(!$item->production_batch && !count($item->display_matches??[]) && !$item->is_custom)
@include('partials.artwork-review', ['item'=>$item])
@else
@forelse($item->display_matches??[] as $m)<div class="art">
<label>DRIVE {{strtoupper($m['side']??'ARTWORK')}}</label>
<img src="https://drive.google.com/thumbnail?id={{$m['id']}}&sz=w800" alt="Drive {{$m['side']??'artwork'}}: {{$m['name']??'Artwork'}}" loading="lazy">
@include('partials.artwork-filename',['filename'=>$m['name']??''])<small>{{$item->production_batch ? 'Locked batch artwork' : ($item->valid_artwork ? (($item->artwork_review['selection_source']??'')==='admin'?'Admin-reviewed artwork choice · operator approval required':'Exact filename match · operator approval required') : 'Saved artwork preview · rematch to verify')}}</small>
<a target="_blank" rel="noopener noreferrer" href="{{$m['webViewLink']??'#'}}">Open original</a>
</div>
@empty<div class="placeholder">{{$item->is_custom?'Custom artwork pending':'No Drive artwork matched'}}</div>
@endforelse
@endif</div>
</div>
<div class="job-actions">
<div class="decision" role="status" aria-live="polite">
@if($item->production_batch)<a href="/batches/{{$item->production_batch['id']}}">{{$reviewLabel}} · {{$item->production_batch['batch_id']}} →</a>
@elseif($item->ready_for_print)<span class="ok">✓ APPROVED — READY FOR PRINT</span>
@elseif($item->decision?->decision==='WRONG' && $item->approval_current)<span class="bad">✕ WRONG ARTWORK</span>
@elseif($item->decision && !$item->approval_current)<span class="warn">⚠ ARTWORK CHANGED — RE-VERIFY</span>
@else NOT VERIFIED
@endif</div>
@if(auth()->user()->allows('bags.batches.edit') && ($item->production_batch['can_return']??false))
<form method="post" action="{{route('batch.return-item',[$item->production_batch['id'],$item->id])}}" data-return-batch data-return-scope="item" data-batch-id="{{$item->production_batch['id']}}">
@csrf<input type="hidden" name="return_confirmed" value="1"><button type="submit" class="chip">Return to Artwork Review</button><small class="muted">This item only</small>
</form>
@elseif(auth()->user()->allows('bags.artwork.approve') && !$item->production_batch && !$item->is_custom && $item->valid_artwork)<div class="individual-actions">
<form method="post" action="/production/{{$item->id}}/verify">
@csrf<input type="hidden" name="decision" value="WRONG">
<button class="reject">✕ Reject artwork</button>
</form>
<form method="post" action="/production/{{$item->id}}/verify">
@csrf<input type="hidden" name="decision" value="CORRECT">
<button class="approve">✓ Approve artwork</button>
</form>
</div>
@elseif(!$item->production_batch && $item->is_custom)<span class="muted">Confirm the uploaded artwork above.</span>
@elseif(!$item->production_batch)<span class="muted">No valid production artwork selected</span>
<button class="approve" disabled>Approve artwork</button>
@endif</div>
</details>
@empty<div class="panel empty">No artwork orders to review.</div>
@endforelse</div>
<div class="panel empty" data-filter-empty hidden>No orders match this filter.</div>
</x-layouts.app>
