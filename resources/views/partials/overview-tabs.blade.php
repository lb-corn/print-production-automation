@php($selectedArea=request('area',auth()->user()->allows('bags.overview.view')?'bags':'apparel'))
<div class="overview-toolbar">
<nav class="overview-tabs" role="tablist" aria-label="Production overview" data-overview-tabs>
@foreach(['bags'=>['Bag Production','bag-production'],'apparel'=>['Apparel Production','apparel-production']] as $area=>[$label,$icon])
@if(auth()->user()->allows($area.'.overview.view'))
<a id="overview-tab-{{$area}}" role="tab" href="{{route('dashboard',['area'=>$area])}}" aria-controls="production-overview-panel" aria-selected="{{$selectedArea===$area?'true':'false'}}" tabindex="{{$selectedArea===$area?'0':'-1'}}">
@include('partials.nav-icon',['name'=>$icon])<span>{{$label}}</span>
</a>
@endif
@endforeach
</nav>

</div>
