@if(!$item->production_batch && !$item->is_custom)
<div class="art artwork-missing">
<label>{{isset($missingSide) ? 'DRIVE '.strtoupper($missingSide) : 'PRODUCTION ARTWORK'}}</label>
<div class="panel artwork-review artwork-missing-frame">
<div class="artwork-missing-heading">
<strong>{{($item->artwork_review['lookup_status'] ?? '') === 'not_checked' ? 'Check production artwork' : ($item->match_status === 'AMBIGUOUS' ? 'Multiple matches · review needed' : 'Production file not found')}}</strong>
@if($item->artwork_review['folder_url'] ?? null)
<a href="{{$item->artwork_review['folder_url']}}" target="_blank" rel="noopener noreferrer" class="artwork-folder-link" aria-label="Open Google Drive Folder">Open folder ↗</a>
@endif
</div>
<p class="artwork-folder"><span>Folder</span> {{$item->artwork_review['expected_folder'] ?? 'Check SKU series folder'}}</p>
@if(isset($missingSide))
<p class="artwork-reason">No verified {{$missingSide}} artwork selected for this design and variant.</p>
@else
@foreach(array_slice($item->artwork_review['issues'] ?? [], 0, 1) as $issue)<p class="artwork-reason">{{$issue}}</p>@endforeach
@endif
@if($item->match_status==='AMBIGUOUS')
<p class="artwork-reason">Review the conflicting files and confirm the correct artwork in Admin Settings.</p>
<div class="artwork-ambiguous-list">
@foreach(array_slice($item->artwork_review['candidates']??[],0,6) as $candidate)<a href="https://drive.google.com/file/d/{{rawurlencode($candidate['id'])}}/view" target="_blank" rel="noopener noreferrer">{{$candidate['side']}} · {{$candidate['name']}} ↗</a>@endforeach
</div>
@endif
<div class="artwork-missing-actions">
@if(auth()->user()->allows('bags.artwork.edit'))
<form method="post" action="{{route('production.rematch')}}" data-rematch-form>
@csrf<input type="hidden" name="confirm_rematch" value="1"><button type="submit" class="chip">Rematch Artwork</button>
</form>
@endif
<small>All unbatched items</small>
</div>
@if(auth()->user()->allows('admin.artwork.view'))
<p><a class="artwork-folder-link" href="{{route('admin.settings',['section'=>'artwork','artwork_search'=>$item->product ?: $item->sku])}}">Choose artwork for this product →</a></p>
<p class="artwork-reason">Select and confirm the production files in Admin Settings, then return here to approve artwork.</p>
@else
<p class="artwork-reason">If rematching finds no valid artwork, ask an administrator to confirm this product’s production files in Admin Settings.</p>
@endif
<details class="artwork-match-details"><summary>Matching details</summary>
@foreach(array_slice($item->artwork_review['issues'] ?? [], 1) as $issue)<p>{{$issue}}</p>@endforeach
@foreach($item->artwork_review['mismatches'] ?? [] as $mismatch)<p>{{$mismatch['filename']}}: mismatched {{implode(', ', $mismatch['attributes'])}}.</p>@endforeach
@foreach($item->artwork_review['candidates'] ?? [] as $candidate)<p>{{$candidate['side']}}: {{$candidate['name']}}</p>@endforeach
</details>
</div>
</div>
@endif
