@if(!request()->routeIs('apparel.*') && request('area')!=='apparel' && auth()->user()?->allows('bags.overview.view') && auth()->user()?->allows('bags.batches.view'))
<div class="sidebar-help sidebar-live"><div class="sidebar-live-heading"><strong>Production now</strong><span data-workspace-live>Live</span></div>@include('partials.workspace-status')<p>Production items · updates every minute</p></div>

@else
<div class="sidebar-help"><a href="{{route('docs')}}">Quick guide &rarr;</a></div>
@endif
