@php($productionPartners=\App\Services\PartnerContext::allowed(auth()->user()))
@if(count($productionPartners)>1)
<form method="get" action="{{url()->current()}}" class="partner-filter" data-partner-filter>
    @foreach(request()->except(['partner','page']) as $key=>$value)@if(is_scalar($value))<input type="hidden" name="{{$key}}" value="{{$value}}">@endif @endforeach
    <label for="production-partner">Partner</label><select id="production-partner" name="partner" onchange="this.form.submit()">@foreach($productionPartners as $partner)<option value="{{$partner}}" @selected($partner===\App\Services\PartnerContext::current())>{{\App\Services\PartnerContext::label($partner)}}</option>@endforeach</select>
    <noscript><button class="chip" type="submit">Apply</button></noscript>
</form>
@elseif(count($productionPartners)===1)<div class="partner-filter partner-filter-static"><span>Partner</span><strong>{{\App\Services\PartnerContext::label()}}</strong></div>@endif
