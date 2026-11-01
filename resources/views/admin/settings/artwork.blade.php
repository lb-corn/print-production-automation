<section class="naming-simple-header">
<div><strong>Standardize artwork names</strong><span>Images and file extensions stay unchanged.</span></div>
<form method="post" action="{{route('admin.artwork-names.scan')}}">
@csrf<button class="primary" type="submit" data-naming-scan>Check files</button></form>
</section>

<div class="naming-status-row" aria-label="Artwork status">
@foreach(['images'=>'Images','products'=>'Products','good'=>'Correct names','ready'=>'Ready to rename','review'=>'Need review','protected'=>'Protected'] as $key=>$label)
<span><b>{{$summary[$key]??'—'}}</b> {{$label}}</span>
@endforeach
<small>@if($summary)Last checked {{date('d M, H:i',$summary['checked_at'])}} · {{$summary['variants']}} variants @else Click Check files to load counts @endif</small>
</div>
<section class="naming-scope naming-scope-compact" aria-label="Artwork scan scope">
<strong>Scan scope · these folders only</strong><div class="naming-scope-roots">
@foreach($scope as $root=>$folders)<span>✓ {{$root}}</span>
@endforeach</div>
<details><summary>Version folders · no MOCKUPS or nested folders</summary><div class="naming-scope-folders">

@foreach($scope as $root=>$folders)

@if($plan)

@foreach($folders as $folder)<a href="https://drive.google.com/drive/folders/{{rawurlencode($folder['id'])}}" target="_blank" rel="noopener noreferrer">{{$folder['name']}} ↗</a>
@endforeach

@else
<span>{{str_contains($root,'GROUP A')?'Snipers · Celtics · Pro Snipers · Surefire':'Luciano · Elite S · Clover · Supreme · SFE · PSE'}}</span>

@endif

@endforeach
</div></details>
</section>

@if($plan)

@php
$counts=array_count_values(array_column($plan['entries'],'status'));
$outdated=($plan['naming_policy']??null)!==\App\Services\ArtworkNamingService::POLICY;
$preview=$plan['status']==='preview'&&!$outdated;
$ready=$counts['ready']??0;
$needsReview=($counts['review']??0)+($counts['conflict']??0);
$protected=($counts['locked']??0)+($counts['standard']??0);
$preselected=0;
@endphp

@if($outdated)
<p class="admin-notice">Naming rules were updated. Click <b>Check files</b> for a fresh preview without years. Nothing in this old preview will be renamed.</p>

@else
@if($plan['status']!=='preview')
<div class="admin-notice"><b>{{ucfirst($plan['status'])}} · {{$plan['renamed']??0}} renamed</b><p>{{$plan['warning']??'Names updated. Artwork still needs operator approval before printing.'}}</p>

@if($plan['match_counts']??null)<p>{{$plan['match_counts']['matched']}} matched · {{$plan['match_counts']['missing']}} missing · {{$plan['match_counts']['ambiguous']}} need review · {{$plan['match_counts']['skipped']}} protected.</p>
@endif

@if($plan['status']==='applying')<p>If this run was interrupted, check the audit and scan again. Do not blindly retry a rename.</p>
@endif
</div>

@endif

@if($preview&&$needsReview)
<details class="naming-simple-review" data-naming-review>
<summary>Review {{count($reviewGroups)}} unclear designs <span>Confirm once; reuse safe matches across versions</span></summary>
<form method="post" action="{{route('admin.artwork-names.review')}}">
@csrf<input type="hidden" name="plan" value="{{$plan['token']}}">
<div class="naming-review-thumbnails"><a data-naming-original href="#" target="_blank" rel="noopener noreferrer" hidden><img data-naming-source-image alt="Drive artwork" hidden><span>Drive artwork ↗</span></a><div><img data-naming-product-image alt="Catalog product" hidden><span data-naming-detected>Choose a design below</span></div></div>
<div class="naming-review-fields">
<label>Design to check<select name="file_id" required data-naming-source><option value="">Choose an unclear design</option>

@foreach($reviewGroups as $group)

@php($entry=$group['entry'])

@php($folderName=trim(substr($entry['folder'],strrpos($entry['folder'],'/')+1)))

@php($source=$entry['source_attributes']??(new \App\Services\ArtworkMatcherService())->sourceAttributes($entry['source'],$folderName))
<option value="{{$entry['id']}}" data-series="{{$source['series']??''}}" data-side="{{$source['side']??''}}" data-color="{{$source['color']??''}}" data-thumbnail="https://drive.google.com/thumbnail?id={{rawurlencode($entry['id'])}}&amp;sz=w160" data-url="https://drive.google.com/file/d/{{rawurlencode($entry['id'])}}/view">{{$entry['source_design']??$entry['source']['name']}} · {{$group['count']}} files · {{$folderName}}</option>

@endforeach
</select></label>
<label>Matching product<select name="catalog_key" required data-naming-catalog><option value="">Choose the matching product</option>
@foreach($catalog as $row)<option value="{{$row['catalog_key']}}" data-series="{{$row['series']??''}}" data-color="{{$row['color']??''}}" data-type="{{$row['type']??''}}" data-image="{{str_starts_with($row['image']??'','https://')?($row['image']??''):''}}">{{$row['product']}} · {{$row['variant']}}</option>
@endforeach</select></label>
</div>
<details class="naming-review-advanced"><summary>Change detected side or color</summary><div class="naming-review-fields"><label>Print side<select name="side" data-naming-side required><option value="">Choose side</option><option value="single">Single</option><option value="front">Front</option><option value="back">Back</option></select></label>
<label>Color<input name="color" maxlength="40" data-naming-color autocomplete="off" placeholder="Blank if not named"></label>
</div></details>
<div class="naming-review-actions"><label><input type="checkbox" name="reuse" value="1" checked> Apply to matching versions and future files</label><button type="submit" class="chip">Use this design</button></div>
<p class="muted">Preview only · no files renamed.</p>
</form>
</details>

@endif
<div class="naming-table-tools naming-simple-tools">
<div class="naming-view-filters"><button type="button" class="chip active" data-naming-mode="ready">Ready {{$ready}}</button><button type="button" class="chip" data-naming-mode="review">Need a check {{$needsReview}}</button><button type="button" class="chip" data-naming-mode="all">All files</button></div>
<input type="search" name="artwork_filename_filter" id="artwork-filename-filter" data-naming-search autocomplete="off" data-lpignore="true" data-1p-ignore="true" placeholder="Filter filenames" aria-label="Filter filenames">
</div>
<form id="artwork-naming-apply" method="post" action="{{route('admin.artwork-names.begin')}}" data-step-url="{{route('admin.artwork-names.step')}}">

@csrf<input type="hidden" name="plan" value="{{$plan['token']}}"><input type="hidden" name="confirmation" value="RENAME"><input type="hidden" name="reviewed" value="1"><input type="hidden" name="username" autocomplete="username" value="{{auth()->user()?->email}}">
<div class="naming-table-wrap"><table><thead><tr><th>Status</th><th>Current file</th><th>New name</th></tr></thead><tbody>

@foreach($plan['entries'] as $entry)
<tr data-naming-row data-naming-status="{{$entry['status']}}"
@if($entry['status']!=='ready') hidden
@endif><td>
@if($preview&&$entry['status']==='ready')<input type="checkbox" name="ids[]" value="{{$entry['id']}}" @checked($preselected++<500) aria-label="Rename {{$entry['source']['name']}}">
@endif<span class="badge">{{ucfirst($entry['status'])}}</span></td>
<td><a href="https://drive.google.com/file/d/{{rawurlencode($entry['id'])}}/view" target="_blank" rel="noopener noreferrer">{{$entry['source']['name']}} ↗</a><small>{{$entry['folder']}}</small></td>
<td><code>{{$entry['proposal']['target']??'Confirm design first'}}</code>
@if($entry['reason']??null)<small>{{$entry['reason']}}</small>
@endif
@if($entry['artwork_duplicate']??false)<small>Duplicate artwork still needs review.</small>
@endif
<details class="naming-row-detail"><summary>Details</summary><small>{{$entry['proposal']['sku']??''}} · {{$entry['proposal']['product']??''}} · {{$entry['proposal']['variant']??''}} · {{$entry['proposal']['side']??''}}<br>{{$entry['basis']??''}}</small></details></td></tr>

@endforeach
</tbody></table><p class="naming-filter-empty" data-naming-empty hidden>No files in this view. <button type="button" data-naming-clear>Show all files</button></p></div>

@if($preview)
<div class="naming-simple-action"><button type="button" class="chip" data-naming-select hidden>Select all ready</button><span data-naming-selected>{{$ready>500?500:$ready}} ready files selected</span><button class="primary" type="button" data-naming-open @disabled(!$ready||!config('services.google_drive.rename_enabled'))>Rename ready files</button></div>
<dialog class="naming-confirm-dialog" data-naming-dialog>
<h3>Rename <span data-naming-confirm-count></span> ready files?</h3><p>Only filenames change. File extensions, contents and print batches stay protected.</p>
<label>Admin password<input name="password" type="password" required disabled autocomplete="current-password"></label>
<label class="naming-dialog-check"><input type="checkbox" name="refresh" value="1" checked> Refresh unbatched artwork matches afterwards</label>
<div><button type="button" class="chip" data-naming-cancel>Cancel</button><button class="primary" type="submit">Confirm rename</button></div>
<p data-naming-progress role="status" aria-live="polite"></p>
</dialog>

@endif
</form>

@if($plan['events'])<details class="naming-advanced"><summary>Rename audit · {{count($plan['events'])}} events</summary>
@foreach($plan['events'] as $event)<p><code>{{$event['old_name']}}</code> → <code>{{$event['new_name']}}</code> · {{$event['status']}} · {{$event['at']}}</p>
@endforeach</details>
@endif

@endif

@endif
<details class="naming-advanced"><summary>Naming rules and setup</summary><p>Example: <code>LB-PSE-DUC-RED-SINGLE-ID-8F23A19C4D72.jpg</code>. No year is added. Actual extensions are preserved. Existing files get stable ID suffixes; missing-file suggestions get one after upload.</p><p>Rename permission requires Editor access for the existing service account. Files in batches, mockups and nested folders are excluded. Uncertain network outcomes stop without automatic retries.</p>
@if(!config('services.google_drive.rename_enabled'))<p>Applying renames is disabled by server configuration.</p>
@endif</details>
<script src="{{asset('js/artwork-names.js')}}?v={{filemtime(public_path('js/artwork-names.js'))}}" defer></script>
