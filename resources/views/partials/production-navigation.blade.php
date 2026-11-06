<a class="workspace-overview-link {{request()->routeIs('dashboard')?'active':''}}" href="{{route('dashboard')}}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
@include('partials.nav-icon',['name'=>'overview'])
<span>Overview</span>
</a>
@if(auth()->user()->allows('bags.overview.view'))
<details name="{{$navAccordion ?? 'workspace'}}" class="production-nav-group" data-nav-group="bags" data-nav-user="{{auth()->id()}}" @if(!request()->routeIs('admin.*','apparel.*') && request('area','bags')==='bags') open @endif>
<summary><span class="nav-section-icon">@include('partials.nav-icon',['name'=>'bag-production'])</span><span class="nav-section-label">Bag Production</span><span class="nav-section-chevron">@include('partials.nav-icon',['name'=>'chevron'])</span></summary>
<div class="production-nav-children">
@foreach(['bags.artwork.view'=>['production','Artwork verification','verification'],'bags.queue.view'=>['queue','Ready for print','queue'],'bags.batches.view'=>['batches','Print batches','batches']] as $permission=>[$route,$label,$icon])
@if(auth()->user()->allows($permission))
<a class="{{request()->routeIs($route) || ($route==='batches' && request()->routeIs('batch.*'))?'active':''}}" href="{{route($route)}}" @if(request()->routeIs($route)) aria-current="page" @endif>
@include('partials.nav-icon',['name'=>$icon])<span>{{$label}}</span>
</a>
@endif
@endforeach
</div>
</details>
@endif
@if(auth()->user()->allows('apparel.overview.view'))
<details name="{{$navAccordion ?? 'workspace'}}" class="production-nav-group" data-nav-group="apparel" data-nav-user="{{auth()->id()}}" @if(!request()->routeIs('admin.*') && (request()->routeIs('apparel.*') || request('area')==='apparel' || !auth()->user()->allows('bags.overview.view'))) open @endif>
<summary><span class="nav-section-icon">@include('partials.nav-icon',['name'=>'apparel-production'])</span><span class="nav-section-label">Apparel Production</span><span class="nav-section-chevron">@include('partials.nav-icon',['name'=>'chevron'])</span></summary>
<div class="production-nav-children">
@if(auth()->user()->allows('apparel.orders.view'))
<a href="{{route('apparel.orders.index')}}" class="{{request()->routeIs('apparel.*')?'active':''}}">@include('partials.nav-icon',['name'=>'apparel-production'])<span>Apparel orders</span></a>
@endif
</div>
</details>
@endif
