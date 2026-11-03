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
<a href="{{route('admin.settings',['section'=>'users'])}}" class="{{$section==='users'?'active':''}}" @if($section==='users') aria-current="page" @endif><span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'overview'])</span><span><b>Manage Users</b></span></a>
<a href="{{route('admin.settings',['section'=>'agent'])}}" class="{{$section==='agent'?'active':''}}" @if($section==='agent') aria-current="page" @endif><span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'overview'])</span><span><b>Factory Agent</b></span></a>
<a href="{{route('admin.settings',array_filter(['section'=>'reset','plan'=>$plan['token']??request('plan')]))}}" class="{{$section==='reset'?'active':''}}" @if($section==='reset') aria-current="page" @endif>
<span class="admin-settings-tab-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'batches'])</span><span><b>Reset portal data</b></span>
</a>

</nav>
@php($sectionTitle=['artwork'=>'Artwork matching','filenames'=>'Filename cleanup','sync'=>'Data sync','users'=>'Manage Users','agent'=>'Factory connection','reset'=>'Reset portal data'][$section])
<section class="admin-settings-content" aria-label="{{$sectionTitle}}">
@if($section!=='agent')<div class="admin-settings-section-title"><div>@if($section==='reset')<span>DANGER ZONE</span>@endif<h2>{{$sectionTitle}}</h2></div></div>@endif
@if($section==='artwork')
@include('admin.settings.artwork')
@elseif($section==='sync')
@include('admin.settings.sync')
@elseif($section==='filenames')
@include('admin.settings.filenames')
@elseif($section==='users')
@include('admin.settings.users')
@elseif($section==='agent')
@include('admin.settings.agent')
@else
@include('admin.settings.reset')
@endif
</section>
</div>
</x-layouts.app>
