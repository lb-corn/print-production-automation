@php($reviewMaterial=app(\App\Services\MaterialCatalog::class)->row($item->series_code))
@if($reviewMaterial)
<span class="review-material-labels" aria-label="Print materials from {{\App\Services\PartnerContext::label()}} sheet">
<span class="review-material-badge" title="{{ $reviewMaterial['type']==='double'?'Front':'Single printed side' }} material: {{$reviewMaterial['front']}}">{{$reviewMaterial['type']==='double'?'Front':'Print'}} · {{$reviewMaterial['front']}}</span>
@if($reviewMaterial['type']==='double')<span class="review-material-badge" title="Back material: {{$reviewMaterial['back']}}">Back · {{$reviewMaterial['back']}}</span>@endif
</span>
@else
<span class="review-material-labels"><span class="review-material-badge unavailable">Material unavailable</span></span>
@endif
