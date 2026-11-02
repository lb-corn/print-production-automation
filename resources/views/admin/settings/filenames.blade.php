<div class="filename-cleanup-toolbar">
<div class="filename-cleanup-search"><input type="search" data-filename-search placeholder="Search product, filename or SKU" aria-label="Search filename cleanup" autocomplete="off"><button class="chip" type="button" data-filename-clear hidden>Clear</button></div>
<small data-filename-count role="status">{{count($filenameEntries)}} files</small><a class="chip" href="{{route('admin.settings',['section'=>'filenames'])}}">Refresh</a>
</div>
@if($loadError)<p class="admin-notice" role="alert">{{$loadError}}</p>
@else
@if(!config('services.google_drive.rename_enabled'))<p class="admin-notice" role="alert">Renaming is disabled on this server. Set GOOGLE_DRIVE_RENAME_ENABLED=true and clear the server configuration cache to enable processing.</p>@endif
<div class="naming-table-wrap filename-cleanup-table"><table><thead><tr><th>Product / series</th><th>Current name in Drive</th><th>New name · editable</th><th></th></tr></thead><tbody>
@forelse($filenameEntries as $entry)
@php($canProcess=$entry['status']==='ready' && config('services.google_drive.rename_enabled') && !empty($entry['source']['canRename']))
<tr data-filename-row data-filename-search-text="{{$entry['source']['name'].' '.$entry['proposal']['target'].' '.$entry['proposal']['product'].' '.$entry['proposal']['variant'].' '.$entry['proposal']['sku']}}">
<td><strong>{{$entry['proposal']['product']}}</strong><small>{{$entry['proposal']['variant']}} · {{$entry['proposal']['series']}}</small></td>
<td><a href="https://drive.google.com/file/d/{{rawurlencode($entry['id'])}}/view" target="_blank" rel="noopener noreferrer">{{$entry['source']['name']}} ↗</a></td>
<td><input class="filename-cleanup-name" name="suggested_name" form="filename-process-{{$loop->index}}" value="{{$entry['proposal']['target']}}" aria-label="New filename for {{$entry['proposal']['product']}} {{$entry['proposal']['variant']}}" required maxlength="255" @disabled(!$canProcess)></td>
<td>
@if($entry['status']==='locked')<span class="badge">In print batch</span>
@elseif($entry['status']!=='ready')<small>{{$entry['reason']??'Name conflicts with another file.'}}</small>
@elseif(!config('services.google_drive.rename_enabled'))<span class="badge">Renaming disabled</span>
@elseif(empty($entry['source']['canRename']))<small>Drive Editor access required</small>
@else
<form id="filename-process-{{$loop->index}}" method="post" action="{{route('admin.filename-cleanup.process')}}" data-filename-process>@csrf<input type="hidden" name="plan" value="{{$plan['token']}}"><input type="hidden" name="file_id" value="{{$entry['id']}}"><button class="primary" type="submit">Process suggested name</button></form>
@endif
</td></tr>
@empty<tr><td colspan="4">No filenames need cleanup.</td></tr>@endforelse
</tbody></table></div>
<p class="muted" data-filename-empty hidden>No filenames match your search.</p>
<p class="filename-cleanup-note">Keep the series, design, color, side and extension. Files in print batches are protected; affected unbatched orders need approval again.</p>
@endif