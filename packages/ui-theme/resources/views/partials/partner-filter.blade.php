@php
    $productionPartners=\App\Services\PartnerContext::allowed(auth()->user());
@endphp
@if(count($productionPartners)>1)
<form method="get" action="{{url()->current()}}" class="partner-control {{($partnerTile??false)?'partner-control-tile':''}} {{($companyPanel??false)?'company-filter-panel':''}}" data-partner-filter aria-label="Filter by company">
@foreach(request()->except(['partner','page']) as $key=>$value)@if(is_scalar($value))<input type="hidden" name="{{$key}}" value="{{$value}}">@endif @endforeach
@if($partnerTile??false)<span class="tool-icon">@include('partials.nav-icon',['name'=>'bag-production'])</span>@endif
<div class="tool-copy">@include('partials.company-field')</div>
<noscript><button class="chip" type="submit">Apply</button></noscript>
</form>
@endif
