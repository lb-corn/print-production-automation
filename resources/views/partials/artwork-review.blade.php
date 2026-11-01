@if(!$item->production_batch && !$item->is_custom)
<div class="art artwork-missing"><label>PRODUCTION ARTWORK</label><div class="panel artwork-review artwork-missing-frame">
@if(!$item->valid_artwork)
<strong>{{$item->match_status === 'AMBIGUOUS' ? 'Ambiguous artwork — operator review required' : 'Missing production artwork'}}</strong>
@endif
<p>Expected Google Drive folder: <b>{{$item->artwork_review['expected_folder'] ?? 'Rematch to locate the SKU series folder'}}</b></p>
@if($item->artwork_review['folder_url'] ?? null)
<a href="{{$item->artwork_review['folder_url']}}" target="_blank" rel="noopener noreferrer">Open Google Drive Folder</a>
@endif
@foreach($item->artwork_review['issues'] ?? [] as $issue)<p class="warn">{{$issue}}</p>@endforeach
@if(!$item->valid_artwork)
@foreach($item->artwork_review['suggested_filenames'] ?? [] as $filename)
<p>Suggested filename: <code>{{$filename}}</code> <button type="button" class="chip" data-copy-filename="{{$filename}}">Copy Filename</button></p>
@endforeach
@foreach($item->artwork_review['mismatches'] ?? [] as $mismatch)<p>{{ $mismatch['filename'] }}: missing or mismatched {{ implode(', ', $mismatch['attributes']) }}.</p>@endforeach
@foreach($item->artwork_review['corrections'] ?? [] as $correction)
<p>Existing file: <code>{{$correction['existing']}}</code>. Suggested correction: <code>{{$correction['suggested']}}</code> <button type="button" class="chip" data-copy-filename="{{$correction['suggested']}}">Copy Filename</button></p>
@endforeach
@if(auth()->user()?->role !== 'demo')
<form method="post" action="{{route('production.rematch')}}" data-rematch-form>
@csrf<input type="hidden" name="confirm_rematch" value="1">
<button type="submit" class="chip">Rematch Artwork</button> <small>Refreshes all unbatched stock items.</small>
</form>
@endif
<p>Suggested names are conventions; they do not prove a file exists. Preserve the actual extension when correcting an existing file, then rematch. Review duplicate files in Drive; no file is selected automatically.</p>
@foreach($item->artwork_review['candidates'] ?? [] as $candidate)<p>Candidate: {{$candidate['name']}} ({{$candidate['side']}})</p>@endforeach
@endif
</div></div>
@endif
