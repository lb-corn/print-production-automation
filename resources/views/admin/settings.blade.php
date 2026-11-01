<x-layouts.app heading="Admin Settings" subheading="Manage administrative tools and production controls">
<div class="admin-settings-layout">
<nav class="admin-settings-nav" aria-label="Admin settings sections">

<a href="{{route('admin.settings',array_filter(['section'=>'artwork','plan'=>$plan['token']??request('plan')]))}}" class="{{$section==='artwork'?'active':''}}" @if($section==='artwork') aria-current="page" @endif>
<span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'verification'])</span><span><b>Artwork filenames</b></span>
</a>
<a href="{{route('admin.settings',array_filter(['section'=>'reset','plan'=>$plan['token']??request('plan')]))}}" class="{{$section==='reset'?'active':''}}" @if($section==='reset') aria-current="page" @endif>
<span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'batches'])</span><span><b>Reset portal data</b></span>
</a>

</nav>
<section class="admin-settings-content" aria-label="{{$section==='artwork'?'Artwork filenames':'Reset portal data'}}">
<div class="admin-settings-section-title"><div><span>{{$section==='reset'?'DANGER ZONE':'PRODUCTION TOOLS'}}</span><h2>{{$section==='artwork'?'Artwork filenames':'Reset portal data'}}</h2></div><span class="badge">Admin only</span></div>
@if($section==='artwork')
@include('admin.settings.artwork')
@else
@include('admin.settings.reset')
@endif
</section>
</div>
</x-layouts.app>
