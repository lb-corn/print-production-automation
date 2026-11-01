@if($loadError??null)<p class="admin-notice" role="alert">{{$loadError}}</p>@endif
<section class="naming-simple-header">
<div><strong>Shopify artwork matching</strong><span>All Bags collection · Active Shopify products only.</span></div>
<form id="artwork-scan-form" method="post" action="{{route('admin.artwork-names.scan')}}">
@csrf<button class="primary" type="submit" data-naming-scan>Check files</button></form>
</section>

<div class="naming-status-row" aria-label="Artwork status">
@foreach(['products'=>'Products','images'=>'Linked files','catalog_matched'=>'Variants found','catalog_missing'=>'Missing','catalog_ambiguous'=>'Review','ready'=>'To rename'] as $key=>$label)
<span><b>{{$summary[$key]??'—'}}</b> {{$label}}</span>
@endforeach
<small>@if($summary)Last checked {{date('d M, H:i',$summary['checked_at'])}} · {{$summary['variants']}} variants @else Click Check files to load counts @endif</small>
</div>
<section class="naming-scope naming-scope-compact" aria-label="Artwork scan scope">
<div class="naming-scope-line"><strong>All Bags · Active only</strong><span class="naming-scope-roots">
@foreach($scope as $root=>$folders)<span>✓ {{$root}}</span>
@endforeach</span></div>
<details><summary>Scan scope · these folders only</summary><small>Direct series files only · no MOCKUPS or nested folders</small><div class="naming-scope-folders">

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
$counts=array_count_values(array_column($visibleEntries,'status'));
$outdated=($plan['naming_policy']??null)!==\App\Services\ArtworkNamingService::POLICY;
$preview=$plan['status']==='preview'&&!$outdated;
$ready=$counts['ready']??0;
$needsReview=($counts['review']??0)+($counts['conflict']??0);
$protected=($counts['locked']??0)+($counts['standard']??0);
$preselected=0;
@endphp

@if($outdated)
<p class="admin-notice">Naming rules were updated. Click <b>Check files</b> for a fresh All Bags / Active products preview. Nothing in this old preview will be renamed.</p>

@else
@if($plan['status']!=='preview')
<div class="admin-notice"><b>{{ucfirst($plan['status'])}} · {{$plan['renamed']??0}} renamed</b><p>{{$plan['warning']??'Names updated. Artwork still needs operator approval before printing.'}}</p>

@if($plan['match_counts']??null)<p>{{$plan['match_counts']['matched']}} matched · {{$plan['match_counts']['missing']}} missing · {{$plan['match_counts']['ambiguous']}} need review · {{$plan['match_counts']['skipped']}} protected.</p>
@endif

@if($plan['status']==='applying')<p>If this run was interrupted, check the audit and scan again. Do not blindly retry a rename.</p>
@endif
</div>

@endif


<section class="artwork-worklist" aria-label="Shopify artwork matching">
<div class="artwork-worklist-tools"><input type="search" data-catalog-search placeholder="Find a Shopify product or SKU…" aria-label="Search Shopify products" autocomplete="off"><select data-catalog-status aria-label="Product artwork status"><option value="attention">Needs artwork or review</option><option value="all">All products</option><option value="matched">Artwork found</option></select></div>
<div class="naming-table-wrap"><table><thead><tr><th>Shopify product</th><th>Artwork</th><th></th></tr></thead><tbody>
@foreach($catalogStatus as $row)
<tr data-catalog-row data-artwork-status="{{$row['status']}}" @if($row['status']==='matched') hidden @endif>
<td><div class="artwork-product-cell">@if(str_starts_with($row['image']??'','https://'))<img src="{{$row['image']}}" alt="" loading="lazy">@endif<div><strong>{{$row['product']}}</strong><small>{{$row['variant']}} · {{$row['sku']}}</small></div></div></td>
<td>{{$row['status']==='matched'?'Found':($row['status']==='ambiguous'?'Review duplicates':'Missing artwork')}}</td><td>@if($preview)<button class="chip" type="button" data-match-product="{{$row['catalog_key']}}">{{$row['status']==='matched'?'View / change':'Match artwork'}}</button>@endif</td></tr>
@endforeach
</tbody></table><div class="artwork-worklist-empty" data-catalog-empty hidden><span data-catalog-empty-message>No products in this view.</span><button type="button" class="chip" data-catalog-clear>Clear search</button></div></div>
</section>
@if($preview&&count($catalog))
<dialog class="artwork-match-dialog" data-artwork-match-dialog aria-labelledby="artwork-match-heading">
<form method="post" action="{{route('admin.artwork-names.review')}}" data-artwork-match-form>
<div class="artwork-match-top">
@csrf<input type="hidden" name="plan" value="{{$plan['token']}}">
<div class="artwork-dialog-heading"><strong id="artwork-match-heading">Match artwork</strong><button type="button" class="chip" data-match-close>Close</button></div>
<select name="catalog_key" required data-naming-catalog hidden aria-label="Selected Shopify variant"><option value="">Choose product</option>
@foreach($catalogStatus as $row)<option value="{{$row['catalog_key']}}" data-series="{{$row['series']??''}}" data-color="{{$row['color']??''}}" data-type="{{$row['type']??''}}" data-image="{{str_starts_with($row['image']??'','https://')?($row['image']??''):''}}" data-scope-ready="{{$row['folder_url']?'1':'0'}}" data-folder-id="{{$row['folder_url']?rawurldecode(basename($row['folder_url'])):''}}" data-sku-conflict="{{$row['sku_conflict']?'1':'0'}}" data-folder="{{$row['expected_folder']}}" data-folder-url="{{$row['folder_url']??''}}">{{$row['product']}} · {{$row['variant']}} · {{$row['sku']}}</option>@endforeach
</select>
<div class="artwork-match-product"><img data-naming-product-image alt="Shopify product" hidden><div><strong data-match-title></strong><small data-match-folder></small><a data-match-folder-link target="_blank" rel="noopener noreferrer" hidden>Open Drive folder ↗</a></div></div>
<p class="admin-notice" data-match-sku-warning hidden>This SKU is shared by different designs. Choose by image. Your selection is saved for this product and variant only.</p>
<div class="artwork-pair-slots" data-match-pair hidden>
<button type="button" class="chip" data-match-side-target="front" aria-pressed="true"><b>1 · Front</b><span data-match-front-name>Choose front artwork</span></button>
<button type="button" class="chip" data-match-side-target="back" aria-pressed="false"><b>2 · Back</b><span data-match-back-name>Choose back artwork</span></button>
</div>
<p class="muted" data-match-pair-error role="alert" hidden></p>
<input type="hidden" name="pair_mode" value="1" data-match-pair-mode disabled><input type="hidden" name="front_file_id" data-match-front-input disabled><input type="hidden" name="back_file_id" data-match-back-input disabled>
<div class="artwork-candidate-heading"><label>Choose the same design from Drive<input type="search" data-naming-source-search placeholder="Search filenames in this series…" autocomplete="off"></label><small data-match-candidate-count></small></div>
</div><div class="artwork-match-scroll">
<select name="file_id" required data-naming-source hidden aria-label="Selected Drive artwork"><option value="">Choose artwork</option>
@foreach($reviewGroups as $group)
@php($entry=$group['entry'])
@php($source=$entry['source_attributes'])
<option value="{{$entry['id']}}" data-parent-id="{{$entry['source']['parent_id']}}" data-series="{{$source['series']??''}}" data-side="{{$source['side']??''}}" data-color="{{$source['color']??''}}" data-thumbnail="https://drive.google.com/thumbnail?id={{rawurlencode($entry['id'])}}&amp;sz=w160" data-url="https://drive.google.com/file/d/{{rawurlencode($entry['id'])}}/view" data-protected="{{$entry['status']==='locked'?'1':'0'}}" data-linked="{{$entry['proposal']['product']??''}}">{{$entry['source']['name']}}</option>
@endforeach
</select>
<div class="artwork-candidate-grid" data-artwork-candidates role="group" aria-label="Drive artwork choices"></div>
<p class="muted" data-artwork-candidate-empty hidden>No eligible artwork found here. Upload the production file to the folder above, then Check files.</p>
<details class="artwork-find-help"><summary>Can’t find it? <span data-match-excluded-count></span></summary>
<p>Check the selected series and color above. For a different variant, close this window and choose that Shopify variant.</p>
<div data-artwork-excluded class="artwork-candidate-grid"></div>
<p>If a color label is incorrect, inspect the full image and choose Use & correct color. Batch-protected files remain unavailable. No file is renamed until you review and apply the filename preview.</p>
<button type="submit" class="chip" form="artwork-scan-form">Refresh files from Drive</button>
</details>
</div><div class="artwork-match-bottom">
<div class="artwork-selected-file" data-match-selection hidden><img data-naming-source-image alt="Selected Drive artwork" hidden><div><strong data-match-selection-name></strong><a data-naming-original target="_blank" rel="noopener noreferrer" hidden>Open full image ↗</a></div></div>
<div data-naming-side-needed hidden><label>This filename has no FRONT/BACK. Choose its side<select name="side" data-naming-side required><option value="">Choose side</option><option value="single">Single</option><option value="front">Front</option><option value="back">Back</option></select></label></div>
<input type="hidden" name="color" data-naming-color>
<label class="artwork-color-confirm" data-match-color-confirm hidden><input type="checkbox" name="color_correction_confirmed" value="1" data-match-color-checkbox disabled> I checked the artwork: its actual color matches Shopify; the filename color label is wrong.</label>
<div class="artwork-match-footer"><label><input type="checkbox" name="reuse" value="1" checked> Remember for matching versions</label><button type="submit" class="primary" data-match-confirm disabled>Confirm this artwork</button></div>
<small class="muted">Confirm only when the images show the same design. This saves your choice and refreshes unbatched matches. Print approval is still required.</small>
</div></form></dialog>
@endif
<details class="naming-advanced"><summary>Filename changes · {{$ready}} ready to rename</summary>
<div class="naming-table-tools naming-simple-tools">
<div class="naming-view-filters"><button type="button" class="chip active" data-naming-mode="ready">Ready {{$ready}}</button><button type="button" class="chip" data-naming-mode="review">Linked files to review</button><button type="button" class="chip" data-naming-mode="all">Linked files</button></div>
<input type="search" name="artwork_filename_filter" id="artwork-filename-filter" data-naming-search autocomplete="off" data-lpignore="true" data-1p-ignore="true" placeholder="Filter filenames" aria-label="Filter filenames">
</div>
<form id="artwork-naming-apply" method="post" action="{{route('admin.artwork-names.begin')}}" data-step-url="{{route('admin.artwork-names.step')}}">

@csrf<input type="hidden" name="plan" value="{{$plan['token']}}"><input type="hidden" name="confirmation" value="RENAME"><input type="hidden" name="reviewed" value="1"><input type="hidden" name="username" autocomplete="username" value="{{auth()->user()?->email}}">
<div class="naming-table-wrap"><table><thead><tr><th>Status</th><th>Current file</th><th>New name</th></tr></thead><tbody>

@foreach($visibleEntries as $entry)
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
@if(!config('services.google_drive.rename_enabled'))<p class="admin-notice">Renaming is disabled on this server. Ask the administrator to enable GOOGLE_DRIVE_RENAME_ENABLED and refresh the Laravel configuration cache. Checking and matching artwork remain available.</p>@endif
<div class="naming-simple-action"><button type="button" class="chip" data-naming-select hidden>Select all ready</button><span data-naming-selected>{{$ready>500?500:$ready}} ready files selected</span><button class="primary" type="button" data-naming-open data-naming-enabled="{{config('services.google_drive.rename_enabled')?'1':'0'}}" @disabled(!$ready||!config('services.google_drive.rename_enabled'))>Rename ready files</button></div>
<dialog class="naming-confirm-dialog" data-naming-dialog>
<h3>Rename <span data-naming-confirm-count></span> ready files?</h3><p>Only filenames change. File extensions, contents and print batches stay protected.</p>
<label>Admin password<input name="password" type="password" required disabled autocomplete="current-password"></label>
<label class="naming-dialog-check"><input type="checkbox" name="refresh" value="1" checked> Refresh unbatched artwork matches afterwards</label>
<div><button type="button" class="chip" data-naming-cancel>Cancel</button><button class="primary" type="submit">Confirm rename</button></div>
<p data-naming-progress role="status" aria-live="polite"></p>
</dialog>

@endif
</form></details>

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
