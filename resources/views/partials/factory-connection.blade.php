@if(auth()->user()?->allows('bags.batches.view'))
<div class="factory-connection" data-factory-connection data-status-url="{{route('workspace.factory-status',[],false)}}" data-state="checking" role="status" aria-live="polite" aria-label="Factory connection"><span class="factory-connection-icon" aria-hidden="true">@include('partials.nav-icon',['name'=>'queue'])</span><span class="factory-connection-label"><small>Factory connection</small><b>Checking…</b></span></div>
@endif
