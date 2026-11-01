<section class="panel naming-intro">
<div>
<strong>Standardize Google Drive artwork</strong>
<p>Checks approved series folders and the product catalog, including products with no orders. Files keep their ID, extension and contents. No folders are moved or deleted.</p>
</div>
<form method="post" action="{{route('admin.artwork-names.scan')}}">

@csrf<button class="primary" type="submit" data-naming-scan>Check filenames</button>
</form>
</section>
<section class="naming-scope" aria-label="Artwork scan scope">
<div class="naming-scope-title"><strong>Scan scope · these folders only</strong><a href="https://drive.google.com/drive/folders/{{rawurlencode(config('services.google_drive.artwork_root_id'))}}" target="_blank" rel="noopener noreferrer">Artwork root ↗</a></div>
<div class="naming-scope-groups">

@foreach($scope as $root=>$folders)
<div><b>✓ {{$root}}</b><div class="naming-scope-folders">

@if($plan)

@forelse($folders as $folder)
<a href="https://drive.google.com/drive/folders/{{rawurlencode($folder['id'])}}" target="_blank" rel="noopener noreferrer">{{$folder['name']}} ↗</a>

@empty<span>No eligible version folders found</span>

@endforelse

@else

@if(str_contains($root,'GROUP A'))<span>Snipers</span><span>Celtics</span><span>Pro Snipers</span><span>Surefire</span>

@else<span>Luciano</span><span>Elite S</span><span>Clover</span><span>Supreme</span><span>SFE</span><span>PSE</span>

@endif

@endif
</div></div>

@endforeach
</div>
<p>Direct files in these version folders only. <strong>Excluded:</strong> MOCKUPS, PREVIEWS, ARCHIVE and all other nested folders.</p>
</section>
<p class="muted">The existing service account needs Editor access to the approved folders to apply renames. Scanning does not require changing permissions.</p>
<p class="muted">Format: <code>LB-2027-SNP-DESIGN-FRONT-ID-8F23A19C4D72.jpg</code> · example only; the actual extension is always preserved. Color is included where applicable; a stable Drive-ID suffix prevents filename clashes. Distinct names can still represent ambiguous artwork. Folder context cannot identify an unknown design. Map uncertain files before reviewing a new preview.</p>


@if(!config('services.google_drive.rename_enabled'))<p class="error">Drive rename is disabled on this server. Scanning and preview are available.</p>

@endif


@if($plan)


@php($counts=array_count_values(array_column($plan['entries'],'status')))
<div class="naming-counts">

@foreach(['ready'=>'Ready','standard'=>'Already standard','review'=>'Needs mapping','conflict'=>'Conflicts','locked'=>'Batched · protected','renamed'=>'Renamed'] as $status=>$label)<span>
<b>{{$counts[$status]??0}}</b> {{$label}}</span>

@endforeach</div>


@if($plan['status']!=='preview')
<section class="panel">
<strong>{{ucfirst($plan['status'])}} · {{$plan['renamed']??0}} renamed</strong>
<p>{{$plan['warning']??'Review the audit below. Explicit artwork approval is still required before printing.'}}</p>


@if($plan['match_counts']??null)<p>Matches refreshed: {{$plan['match_counts']['matched']}} matched · {{$plan['match_counts']['missing']}} missing · {{$plan['match_counts']['ambiguous']}} ambiguous · {{$plan['match_counts']['skipped']}} skipped.</p>

@endif


@if($plan['status']==='applying')<p>A run may have been interrupted. Check the audit and scan again to reconcile the current names; do not retry an uncertain rename blindly.</p>

@endif
</section>


@endif


@if($plan['status']==='preview')
<details class="panel naming-mapping">
<summary>Map an unclear file to a catalog variant</summary>
<form method="post" action="{{route('admin.artwork-names.review')}}">

@csrf<input type="hidden" name="plan" value="{{$plan['token']}}">
<label>Drive file<select name="file_id" required>
<option value="">Choose a file</option>

@foreach($plan['entries'] as $entry)

@if(in_array($entry['status'],['review','ready','conflict']))<option value="{{$entry['id']}}">{{$entry['source']['name']}} — {{$entry['folder']}}</option>

@endif

@endforeach</select>
</label>
<label>Correct catalog variant<select name="catalog_key" required data-naming-catalog>
<option value="">Choose product / SKU</option>

@foreach($catalog as $row)<option value="{{$row['catalog_key']}}" data-color="{{$row['color']??''}}" data-type="{{$row['type']??''}}">{{$row['sku']}} · {{$row['product']}} · {{$row['variant']}}</option>

@endforeach</select>
</label>
<label>Print side<select name="side" data-naming-side required>
<option value="single">Single</option>
<option value="front">Front</option>
<option value="back">Back</option>
</select>
</label>
<label>Artwork color<input name="color" maxlength="40" data-naming-color placeholder="Blank only if no color label applies">
</label>
<p class="muted">Confirm the actual image design, color and side. Mapping creates a new preview; it never renames immediately. A mapping cannot rescue a mockup or an invalid folder.</p>
<button type="submit" class="chip">Update preview</button>
</form>
</details>


@endif
<form id="artwork-naming-apply" method="post" action="{{route('admin.artwork-names.begin')}}" data-step-url="{{route('admin.artwork-names.step')}}">


@csrf<input type="hidden" name="plan" value="{{$plan['token']}}">
<div class="naming-table-tools">
<input type="search" data-naming-search placeholder="Find filename, SKU or folder" aria-label="Filter filename preview">

@if($plan['status']==='preview')<button type="button" class="chip" data-naming-select>Select ready files</button>
<span class="muted" data-naming-selected>0 selected</span>

@endif</div>
<div class="naming-table-wrap">
<table>
<thead>
<tr>
<th>Select / status</th>
<th>Original Drive file</th>
<th>Proposed name</th>
<th>Catalog evidence</th>
</tr>
</thead>
<tbody>


@foreach($plan['entries'] as $entry)
<tr data-naming-row>
<td>

@if($plan['status']==='preview'&&$entry['status']==='ready')<input type="checkbox" name="ids[]" value="{{$entry['id']}}" aria-label="Rename {{$entry['source']['name']}}">

@endif<span class="badge">{{ucfirst($entry['status'])}}</span>
</td>
<td>
<b>{{$entry['source']['name']}}</b>
<small>{{$entry['folder']}}</small>
<a href="https://drive.google.com/file/d/{{rawurlencode($entry['id'])}}/view" target="_blank" rel="noopener noreferrer">Inspect original ↗</a>
</td>
<td>
<code>{{$entry['proposal']['target']??'Mapping required'}}</code>
@if($entry['artwork_duplicate']??false)<small>Duplicate artwork candidate: names will be distinct, but artwork review will still be required.</small>
@endif

@if($entry['status']==='conflict')<small>Duplicate destination or design candidate. Resolve the duplicate in Drive; no file is chosen automatically.</small>

@endif</td>
<td>{{$entry['proposal']['sku']??'—'}}<small>{{$entry['proposal']['product']??''}} · {{$entry['proposal']['variant']??''}} · {{$entry['proposal']['side']??''}}</small>
</td>
</tr>


@endforeach
</tbody>
</table>
</div>


@if($plan['status']==='preview')
<section class="panel naming-confirmation">
<strong>Review before renaming · up to 500 files per run</strong>
<label>
<input type="checkbox" name="reviewed" value="1" required> I checked the selected artwork, proposed names, colors and sides.</label>
<label>
<input type="checkbox" name="refresh" value="1" checked> Refresh unbatched artwork matches after renaming. Changed artwork loses approval; batches remain locked.</label>
<div>
<label>Admin password<input name="password" type="password" required autocomplete="current-password">
</label>
<label>Type RENAME<input name="confirmation" required pattern="RENAME" autocomplete="off">
</label>
<button class="primary" type="submit" @disabled(!config('services.google_drive.rename_enabled'))>Rename selected files</button>
</div>
<p data-naming-progress role="status" aria-live="polite">No changes until you confirm. Partial failures are recorded; no automatic retry or print action runs.</p>
</section>


@endif
</form>


@if($plan['events'])<details class="panel">
<summary>Rename audit · {{count($plan['events'])}} events</summary>

@foreach($plan['events'] as $event)<p>
<code>{{$event['old_name']}}</code> → <code>{{$event['new_name']}}</code> · {{$event['status']}} · {{$event['at']}}</p>

@endforeach</details>

@endif


@endif
<script src="{{asset('js/artwork-names.js')}}?v={{filemtime(public_path('js/artwork-names.js'))}}" defer>
</script>
