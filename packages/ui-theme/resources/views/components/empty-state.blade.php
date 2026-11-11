@props(['title','description'=>null,'icon'=>'overview'])
<div {{ $attributes->class(['ui-empty-state']) }}>
    <span class="ui-empty-icon">@include('partials.nav-icon',['name'=>$icon])</span>
    <strong>{{ $title }}</strong>
    @if($description)
        <p>{{ $description }}</p>
    @endif
    @if(trim((string)$slot)!=='')
        <div class="ui-empty-actions">{{ $slot }}</div>
    @endif
</div>
