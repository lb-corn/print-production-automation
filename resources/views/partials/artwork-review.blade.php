@if(!$item->production_batch && !$item->is_custom)
<div class="art artwork-missing">
<label>PRODUCTION ARTWORK</label>
<div class="panel artwork-review artwork-missing-frame">
<div class="artwork-missing-heading">
<strong>{{($item->artwork_review['lookup_status'] ?? '') === 'not_checked' ? 'Check production artwork' : ($item->match_status === 'AMBIGUOUS' ? 'Multiple matches · review needed' : 'Production file not found')}}</strong>
@if($item->artwork_review['folder_url'] ?? null)
<a href="{{$item->artwork_review['folder_url']}}" target="_blank" rel="noopener noreferrer" class="artwork-folder-link" aria-label="Open Google Drive Folder">Open folder ↗</a>
@endif
</div>
<p class="artwork-folder"><span>Folder</span> {{$item->artwork_review['expected_folder'] ?? 'Check SKU series folder'}}</p>
@foreach(array_slice($item->artwork_review['issues'] ?? [], 0, 1) as $issue)<p class="artwork-reason">{{$issue}}</p>@endforeach
<div class="artwork-filename-list">
@foreach($item->artwork_review['suggested_filenames'] ?? [] as $filename)
<div class="artwork-filename-row"><code title="{{$filename}}">{{$filename}}</code><button type="button" data-copy-filename="{{$filename}}" aria-label="Copy filename {{$filename}}">Copy</button></div>
@endforeach
</div>
<div class="artwork-missing-actions">
@if(auth()->user()?->role !== 'demo')
<form method="post" action="{{route('production.rematch')}}" data-rematch-form>
@csrf<input type="hidden" name="confirm_rematch" value="1"><button type="submit" class="chip">Rematch Artwork</button>
</form>
@endif
<small>All unbatched items</small>
</div>
<details class="artwork-match-details"><summary>Naming guidance &amp; details</summary>
<p>Names are suggestions, not proof of a file. Keep its actual extension. Confirm artwork content before renaming; do not rename a mockup or another color into production artwork.</p>
@foreach(array_slice($item->artwork_review['issues'] ?? [], 1) as $issue)<p>{{$issue}}</p>@endforeach
@foreach($item->artwork_review['mismatches'] ?? [] as $mismatch)<p>{{$mismatch['filename']}}: mismatched {{implode(', ', $mismatch['attributes'])}}.</p>@endforeach
@foreach($item->artwork_review['corrections'] ?? [] as $correction)<p>{{$correction['existing']}} → <code>{{$correction['suggested']}}</code> <button type="button" data-copy-filename="{{$correction['suggested']}}">Copy</button></p>@endforeach
@foreach($item->artwork_review['candidates'] ?? [] as $candidate)<p>{{$candidate['side']}}: {{$candidate['name']}}</p>@endforeach
</details>
</div>
</div>
@endif
