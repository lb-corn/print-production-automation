@if(!empty($productionSummary['areas']))
<section class="production-snapshot" aria-label="Authorized production summary" data-workspace-stats data-status-url="{{route('workspace.status')}}">
<div class="production-snapshot-heading"><strong>Production summary</strong><span data-workspace-live>Live</span></div>
@foreach($productionSummary['areas'] as $area=>$values)
<div class="production-snapshot-row" data-summary-area="{{$area}}">
<a class="production-snapshot-area" href="{{route('dashboard',['area'=>$area])}}">{{$area==='bags'?'Bags':'Apparel'}}</a>
@foreach($values as $key=>$value)
@php($label=($key==='ready'?'Ready':($key==='waiting'?'Waiting':($key==='active'?($area==='bags'?'Print':'Partial'):'Done'))))
<span class="production-snapshot-metric" title="{{$area==='bags'?'Bag order lines':'Non-cancelled apparel order lines'}}: {{$label}}"><b data-summary-count="{{$area}}.{{$key}}">{{$value===null?'—':number_format($value)}}</b><small>{{$label}}</small></span>
@endforeach
</div>
@endforeach
<a class="production-snapshot-guide" href="{{route('docs')}}">Quick guide &rarr;</a>
</section>
@else
<a class="production-summary-guide" href="{{route('docs')}}">Quick guide &rarr;</a>
@endif
