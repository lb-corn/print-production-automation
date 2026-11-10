@php
    $backingDefinition=\App\Services\BackingColorService::definition($item);
    $backingConfirmed=\App\Services\BackingColorService::confirmed($item);
    $backingLocked=$item->decision?->decision==='CORRECT';
    $backingEditable=auth()->user()->allows('bags.artwork.approve') && !$backingLocked;
    $backingForm=$item->is_custom?null:'approve-artwork-'.$item->id;
@endphp
<section id="backing-review-{{$item->id}}" class="backing-color-review" data-backing-review data-suggest-url="{{route('backing.suggest',$item)}}" data-locked="{{$backingLocked?'true':'false'}}">
    <div class="backing-inline-controls">
        <label for="backing-color-{{$item->id}}">{{$backingDefinition['material']}} backing</label>
        <select id="backing-color-{{$item->id}}" name="backing_color" @if($backingForm) form="{{$backingForm}}" @endif required data-backing-color @disabled(!$backingEditable)><option value="">Choose color</option>@foreach($backingDefinition['colors'] as $color)<option value="{{$color}}" @selected($backingConfirmed && $item->backing_selection['color']===$color)>{{$color}}</option>@endforeach</select>
        <input type="hidden" name="backing_context" @if($backingForm) form="{{$backingForm}}" @endif value="{{\App\Services\BackingColorService::context($item)}}" data-backing-context>
        <img class="backing-selected-swatch" data-backing-selected-image hidden alt="Selected backing color swatch">
    </div>
    <p class="backing-suggestion" data-backing-message role="status">{{$backingLocked?'Approved · return to review to change.':($backingConfirmed?'Selected sheet color · saved with Approve.':'Identifying backside color… You can also choose a color.')}}</p>
    @if(!$backingDefinition['colors'])<small class="warn">No allowed colors in the sheet for this material.</small>@endif
    <details class="backing-swatch-details" open><summary>Compare swatches</summary><div class="backing-swatches" data-backing-swatches></div><button type="button" class="chip" data-backing-compare @disabled(!$backingEditable)>Retry comparison</button></details>
</section>
