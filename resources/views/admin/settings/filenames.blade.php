<div class="naming-simple-header"><div><strong>Nonstandard filenames</strong><span>Optional cleanup · process one suggested name at a time.</span></div><a class="chip" href="{{route('admin.settings',['section'=>'filenames'])}}">Refresh list</a></div>
@if($loadError)<p class="admin-notice" role="alert">{{$loadError}}</p>
@else
<p class="muted">Renames update Google Drive and refresh app matches. Files used by print batches are blocked. Affected unbatched orders need approval again.</p>
@if(!config('services.google_drive.rename_enabled'))<p class="admin-notice" role="alert">Renaming is disabled on this server. Set GOOGLE_DRIVE_RENAME_ENABLED=true and clear the server configuration cache to enable processing.</p>@endif
<div class="naming-table-wrap"><table><thead><tr><th>Current filename</th><th>Suggested name</th><th>Action</th></tr></thead><tbody>
@forelse($filenameEntries as $entry)
<tr><td><a href="https://drive.google.com/file/d/{{rawurlencode($entry['id'])}}/view" target="_blank" rel="noopener noreferrer">{{$entry['source']['name']}} ↗</a><small>{{$entry['proposal']['product']}} · {{$entry['proposal']['variant']}}</small></td>
<td><code>{{$entry['proposal']['target']}}</code></td><td>
@if($entry['status']==='locked')<span class="badge">Protected by print batch</span>
@elseif($entry['status']!=='ready')<small>{{$entry['reason']??'Suggested name conflicts with another file. Resolve the duplicate first.'}}</small>
@elseif(!config('services.google_drive.rename_enabled'))<span class="badge">Renaming disabled</span>
@elseif(empty($entry['source']['canRename']))<small>Drive Editor access required</small>
@else
<form method="post" action="{{route('admin.filename-cleanup.process')}}" data-filename-process>@csrf<input type="hidden" name="plan" value="{{$plan['token']}}"><input type="hidden" name="file_id" value="{{$entry['id']}}"><button class="primary" type="submit">Process suggested name</button></form>
@endif
</td></tr>
@empty<tr><td colspan="3">No nonstandard filenames with a reliable suggestion were found.</td></tr>@endforelse
</tbody></table></div>
<p class="muted">Files without a reliable product match are excluded. Confirm their artwork in <a href="{{route('admin.settings',['section'=>'artwork'])}}">Artwork matching</a> first.</p>
@endif
