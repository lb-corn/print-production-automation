@props(['title','description'=>null])
<div {{ $attributes->class(['panel-head','ui-panel-header']) }}><div><h2>{{ $title }}</h2>@if($description)<p>{{ $description }}</p>@endif</div>@if(isset($actions))<div class="ui-panel-actions">{{ $actions }}</div>@endif{{ $slot }}</div>
