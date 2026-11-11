@php
$queueAvailable=$factoryQueueAvailable??app(\App\Services\FactoryDispatchService::class)->consentReady();
$catalog=app(\App\Services\MaterialCatalog::class);$materialNames=$catalog->materials();
@endphp
<x-layouts.app heading="Ready for Print" subheading="Choose a material, review approved artwork and queue a job">
<div class="queue-workspace">
@include('partials.material-catalog-status')
@if(!$queueAvailable)<div class="progress-failure" role="alert"><strong>Printing is waiting for a server update.</strong><p>Your approved items are saved. An administrator must complete the database update before queueing.</p>@if(auth()->user()?->role==='admin')<p>Run <code>bash deployment/post-deploy.sh</code> in the hosting application folder, then refresh.</p>@endif</div>@endif
<div class="queue-summary"><span><b>{{$summary['approved_orders']}}</b> approved items</span><span><b>{{$summary['total_sets']}}</b> sets</span><span><b>{{$summary['artwork_files']}}</b> artwork files</span><span><b>{{number_format($summary['printable_yards'],3)}} yd</b> printed fabric</span></div>
@if(auth()->user()->allows('bags.batches.edit') && $items->isNotEmpty())
<section class="panel queue-controls"><form id="queue-selection" data-queue-selection data-queue-blocked="{{($queueAvailable && empty($materialCatalogError))?'0':'1'}}" method="post" action="{{route('batch.create')}}">@csrf<input type="hidden" name="dispatch_confirmed" value="1"><div class="queue-material-field"><label for="queue-material">Printed material</label><select id="queue-material" data-material-filter @disabled(!$queueAvailable)><option value="">All materials</option>@foreach($materialNames as $material)<option value="{{$material}}">{{$material}} · {{$summary['printable_materials'][$material]['sides']??0}} print sides</option>@endforeach</select></div><label class="confirmation-check"><input type="checkbox" data-select-all @disabled(!$queueAvailable)><span>Select visible orders</span></label><button type="button" class="primary" data-queue-open disabled>Queue selected (0)</button></form><p class="muted">Selected orders are split automatically into separate print batches by material. Double-sided bags can create two material batches. Single-sided backing never creates a print job.</p></section>
@endif
<details class="panel queue-material-details"><summary><strong><svg class="queue-material-chevron" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="m6 4 4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Material totals</strong><span>{{number_format($summary['printable_yards'],3)}} yd printed · {{number_format($summary['nonprint_yards'],3)}} yd backing</span></summary>@include('partials.material-requirements')</details>
<div class="queue-list"><p class="panel queue-empty" data-material-empty hidden>No approved print sides for this material. Choose another material or All materials.</p>
@forelse($items as $item)
@php $groupName=\App\Services\PrintLabelService::group($item); @endphp
<article class="panel queue-item" data-queue-card data-material-group="{{$groupName}}" data-materials="{{json_encode(\App\Services\PrintLabelService::materials($item))}}">
@if(auth()->user()->allows('bags.batches.edit'))<label class="queue-item-select"><input type="checkbox" name="items[]" value="{{$item->id}}" form="queue-selection" data-queue-item data-material-group="{{$groupName}}" data-materials="{{json_encode(\App\Services\PrintLabelService::materials($item))}}" aria-label="Select {{$item->product}}" @disabled(!$queueAvailable)></label>@endif
<div class="queue-item-images">
@foreach($item->matches as $match)
@php
$side=$match['side']??'single';$material=$materials[$item->series_code][$side==='back'?1:0]??'Material';$printLabel=\App\Services\PrintLabelService::label($item,$material);
@endphp
<figure>@if(($match['source']??null)==='pc_custom')@include('partials.custom-artwork-preview',['uploadId'=>$match['upload_id'],'side'=>$side,'previewItem'=>$item])@else<button type="button" class="queue-preview-open" data-labeled-preview-open aria-label="Open labeled {{$side}} print preview for {{$item->order_number}}"><span class="queue-artwork-preview" data-labeled-artwork><img src="https://drive.google.com/thumbnail?id={{$match['id']}}&sz=w800" alt="Approved {{$side}} artwork"><span class="queue-label-overlay">{{$printLabel}}</span></span><span class="queue-preview-hint">Open labeled preview ↗</span></button>@endif<figcaption>{{strtoupper($side)}} · {{$material}}</figcaption></figure>
@endforeach
</div>
<div class="queue-item-info"><div class="queue-item-heading"><div><span class="muted">{{$item->order_number}}</span><span class="partner-badge">{{\App\Services\PartnerContext::label($item->partner??'lucky')}}</span><h2>{{$item->product}}</h2></div><span class="badge">{{$item->design_type==='double'?'FRONT + BACK':'SINGLE'}}</span></div><dl class="queue-item-facts"><div><dt>Variant</dt><dd>{{$item->variant?:'—'}}</dd></div><div><dt>Quantity</dt><dd>{{$item->quantity}}</dd></div><div><dt>Printed material</dt><dd>{{$groupName}}</dd></div><div><dt>Confirmed backing color</dt><dd>{{\App\Services\BackingColorService::color($item)?:'Not applicable'}}</dd></div></dl>
@foreach($item->matches as $match)
@php $material=$materials[$item->series_code][($match['side']??'single')==='back'?1:0]??'Material'; @endphp
<p class="queue-print-label"><span>Print label</span><code>{{\App\Services\PrintLabelService::label($item,$material)}}</code></p>
@endforeach
<details class="queue-source-details"><summary>Approved source files</summary>@foreach($item->matches as $match)@if(($match['source']??null)==='pc_custom')<p>{{$match['name']}} · confirmed custom file on Print PC</p>@else<a href="{{$match['webViewLink']??'https://drive.google.com/file/d/'.$match['id'].'/view'}}" target="_blank" rel="noopener">{{$match['name']}} ↗</a>@endif
@endforeach</details><span class="ok">✓ Artwork approved</span>@if(auth()->user()->allows('bags.artwork.approve') && $item->getKey())<form method="post" action="{{route('production.return-review',$item)}}" class="ready-return-review">@csrf<button type="submit" class="chip">Return to review</button></form>@endif</div>
</article>
@empty<div class="panel queue-empty"><strong>No approved items waiting</strong><p>Track queued jobs in Print batches, or approve artwork to prepare another job.</p><a class="primary" href="{{route('batches')}}">View print batches →</a></div>
@endforelse
</div>
@if(auth()->user()->allows('bags.batches.edit') && $items->isNotEmpty())
<dialog class="print-confirmation" data-queue-dialog aria-labelledby="queue-confirm-title"><h2 id="queue-confirm-title">Queue selected artwork?</h2><p><strong data-selected-count></strong> orders · <strong data-selected-material></strong></p><p class="admin-notice">{{$factoryReadiness['message']??'Check Factory connection before processing.'}}</p><p>Each material receives a separate batch containing only its print sides. The worker prepares labeled copies of the approved originals. Review and sign Handoff before delivery to Epson. Files are retained until signed physical print completion.</p><div class="confirmation-actions"><button type="button" class="chip" data-queue-cancel>Cancel</button><button type="button" class="primary" data-queue-confirm @disabled(!$queueAvailable)>Queue &amp; prepare</button></div></dialog>
@endif
</div>
<dialog class="labeled-preview-dialog" data-labeled-preview-dialog aria-labelledby="labeled-preview-title">
<div class="labeled-preview-heading"><div><h2 id="labeled-preview-title">Labeled print preview</h2><p>The print copy includes this label. Approved Drive source files remain unchanged.</p></div><button type="button" class="chip" data-labeled-preview-close aria-label="Close labeled preview">Close</button></div>
<div class="labeled-preview-stage"><span class="queue-artwork-preview" data-labeled-artwork><img data-labeled-preview-image alt="Enlarged labeled print preview"><span class="queue-label-overlay" data-labeled-preview-label></span></span></div>
<p class="labeled-preview-caption" data-labeled-preview-caption></p>
</dialog>
</x-layouts.app>