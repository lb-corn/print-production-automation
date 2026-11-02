<x-layouts.app heading="Admin Settings" subheading="Manage administrative tools and production controls">
<div class="admin-settings-layout">
<nav class="admin-settings-nav" aria-label="Admin settings sections">

<a href="{{route('admin.settings',array_filter(['section'=>'artwork','plan'=>$plan['token']??request('plan')]))}}" class="{{$section==='artwork'?'active':''}}" @if($section==='artwork') aria-current="page" @endif>
<span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'verification'])</span><span><b>Artwork matching</b></span>
</a>
<a href="{{route('admin.settings',['section'=>'sync'])}}" class="{{$section==='sync'?'active':''}}" @if($section==='sync') aria-current="page" @endif>
<span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'verification'])</span><span><b>Data sync</b></span>
</a>
<a href="{{route('admin.settings',['section'=>'filenames'])}}" class="{{$section==='filenames'?'active':''}}" @if($section==='filenames') aria-current="page" @endif><span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'batches'])</span><span><b>Filename cleanup</b></span></a>
<a href="{{route('admin.settings',array_filter(['section'=>'reset','plan'=>$plan['token']??request('plan')]))}}" class="{{$section==='reset'?'active':''}}" @if($section==='reset') aria-current="page" @endif>
<span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'batches'])</span><span><b>Reset portal data</b></span>
</a>

</nav>
@php($sectionTitle=['artwork'=>'Artwork matching','filenames'=>'Filename cleanup','sync'=>'Data sync','reset'=>'Reset portal data'][$section])
<section class="admin-settings-content" aria-label="{{$sectionTitle}}">
<div class="admin-settings-section-title"><div><span>{{$section==='reset'?'DANGER ZONE':'PRODUCTION TOOLS'}}</span><h2>{{$sectionTitle}}</h2></div><span class="badge">Admin only</span></div>
@if($section==='artwork')
@include('admin.settings.artwork')
@elseif($section==='sync')
@include('admin.settings.sync')
@elseif($section==='filenames')
@include('admin.settings.filenames')
@else
@include('admin.settings.reset')
@endif
</section>
</div>
</x-layouts.app>
