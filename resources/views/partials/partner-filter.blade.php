@php
    $productionPartners=\App\Services\PartnerContext::allowed(auth()->user());
    $partnerOptions=str_starts_with(request()->route()?->getName()??'','admin.')?$productionPartners:array_merge(['all'],$productionPartners);
@endphp
@if(count($productionPartners)>1)
<form method="get" action="{{url()->current()}}" class="partner-control {{($partnerTile??false)?'partner-control-tile':''}}" data-partner-filter aria-label="Filter by partner">
@foreach(request()->except(['partner','page']) as $key=>$value)@if(is_scalar($value))<input type="hidden" name="{{$key}}" value="{{$value}}">@endif @endforeach
@if($partnerTile??false)<span class="tool-icon">@include('partials.nav-icon',['name'=>'bag-production'])</span>@endif
<div class="tool-copy"><label for="partner-view">Partner</label><select id="partner-view" name="partner" onchange="this.form.submit()">@foreach($partnerOptions as $partner)<option value="{{$partner}}" @selected(\App\Services\PartnerContext::current()===$partner)>{{$partner==='all'?'All partners':\App\Services\PartnerContext::label($partner)}}</option>@endforeach</select></div>
<noscript><button class="chip" type="submit">Apply</button></noscript>
</form>
@endif
