@php
$customReady=\App\Services\CustomArtworkService::ready();
$customSides=$item->design_type==='double'?['front','back']:['single'];
$customUsable=$customReady && in_array($item->design_type,['single','double'],true) && !$item->production_batch;
@endphp
<section class="custom-artwork-review {{count($customSides)===2?'custom-artwork-double':'custom-artwork-single'}}" data-custom-review data-create-url="{{route('custom.create',$item)}}" data-confirm-url="{{route('custom.confirm',$item)}}" data-status-base="{{url('/custom-transfers')}}" data-csrf="{{csrf_token()}}">
@if(count($customSides)===1)
<span class="custom-side-heading">CUSTOM PRODUCTION ARTWORK</span>
<div class="custom-single-frame">
<div class="custom-upload-heading"><span class="badge">Saved on Print PC only</span></div>
<p class="custom-upload-hint">Upload the print original. Compare it with the order preview.</p>
@endif
<div class="custom-upload-slots {{count($customSides)===1?'one':''}}">
@foreach($customSides as $side)
@php $saved=collect($item->matches??[])->firstWhere('side',$side); @endphp
<div class="custom-upload-slot art" data-custom-slot data-side="{{$side}}" data-upload-id="{{$saved['upload_id']??''}}">
@if(count($customSides)===2)<span class="custom-side-heading">CUSTOM {{strtoupper($side)}} ARTWORK</span>@endif
<label class="custom-file-drop"><span class="custom-slot-title">{{strtoupper($side)}} artwork</span><span>Drop file or <b>choose file</b></span><input type="file" accept="image/jpeg,image/png,image/tiff,.tif,.tiff" data-custom-file @disabled(!$customUsable || !auth()->user()->allows('bags.artwork.edit')) aria-label="Upload {{$side}} custom artwork"><small>JPEG, PNG or single-page TIFF · up to 100 MB</small></label>
<img class="custom-local-preview" data-local-preview hidden alt="Selected {{$side}} artwork">
@if(($saved['upload_id']??null) && ($item->production_batch['status']??null)!=='PRINTED')@include('partials.custom-artwork-preview',['uploadId'=>$saved['upload_id'],'side'=>$side,'previewItem'=>$item])@endif
<p class="custom-upload-filename" data-file-name>{{$saved['name']??''}}</p><progress data-custom-progress hidden max="100"></progress><p class="custom-upload-status" role="status" aria-live="polite" data-custom-status>{{$saved?'Validated on Print PC':'Waiting for file'}}</p><button type="button" class="chip" data-custom-view hidden>Review labeled copy from PC</button><button type="button" class="chip" data-custom-cancel hidden>Cancel transfer</button>
</div>
@endforeach
</div>
<div class="custom-review-footer">
@if(count($customSides)===2)
<div class="custom-upload-heading"><strong>Custom production artwork</strong><span class="badge">Saved on Print PC only</span></div>
<p class="custom-upload-hint">Upload print originals directly to the PC. Use the order preview for comparison.</p>
@endif
@if(!$customReady)<p class="custom-upload-status bad">Custom upload needs the server database update.</p>@elseif(!$item->design_type)<p class="custom-upload-status bad">The custom bag SKU needs a matching code in the {{\App\Services\PartnerContext::label()}} sheet. Sync again after correcting it.</p>@endif
@if($customUsable && auth()->user()->allows('bags.artwork.approve'))<label class="confirmation-check custom-review-check"><input type="checkbox" data-custom-reviewed><span>I checked all uploaded print sides against the order preview.</span></label><button type="button" class="primary" data-custom-confirm disabled>Confirm artwork</button>@endif
<p class="custom-upload-status" role="status" aria-live="polite" data-custom-result></p>
</div>
@if(count($customSides)===1)</div>@endif
</section>
