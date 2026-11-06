<div class="factory-agent-page" data-agent-monitor data-updater-url="{{route('admin.factory-agent.updater',[],false)}}" data-package-url="{{route('admin.factory-agent.package',[],false)}}" data-installer-url="{{route('admin.factory-agent.setup',[],false)}}" data-package-sha="{{$agentPackage['portal_zip_sha256']??''}}" data-package-version="{{$agentPackage['version']??''}}" data-handoff-url="{{route('admin.factory-agent.handoff-control',['agent'=>'AGENT_ID'],false)}}" data-csrf="{{csrf_token()}}" data-status-url="{{route('admin.factory-agent.status',[],false)}}">
<div class="panel-head agent-summary-heading"><div><h2>Factory connection</h2><p>Live connection and activity from the Printer PC.</p></div><button type="button" class="chip" data-agent-refresh>Refresh status</button></div>
<p class="agent-monitor-notice" data-agent-notice role="status" aria-live="polite">Checking connection…</p>
<div data-agent-cards class="agent-monitor-cards"></div>
<section class="panel agent-releases" aria-labelledby="agent-releases-title">
    <div class="agent-releases-heading"><div><h3 id="agent-releases-title">Agent releases</h3><p>Latest five versions · newest first</p></div><span class="badge">Latest v{{$agentPackage['version']??'—'}}</span></div>
    <div class="agent-release-list">
    @forelse($agentReleases??[] as $release)
        <div class="agent-release-row">
            <div class="agent-release-version"><strong>v{{$release['version']}}</strong>@if($release['version']===($agentPackage['version']??null))<span>Latest</span>@endif</div>
            <div class="agent-release-description"><p>{{$release['summary']}}</p><small>{{\Illuminate\Support\Carbon::parse($release['published_at'])->format('d M Y')}} · {{number_format($release['bytes']/1024)}} KB</small></div>
            <div class="agent-release-downloads">@if($release['version']===($agentPackage['version']??null))<a class="agent-update-button" href="{{route('admin.factory-agent.updater')}}">Download updater</a>@endif<a class="agent-package-button" href="{{route('admin.factory-agent.package',['version'=>$release['version']])}}" aria-label="Download agent v{{$release['version']}} ZIP">ZIP ↓</a></div>
        </div>
    @empty
        <div class="agent-release-row"><div class="agent-release-version"><strong>v{{$agentPackage['version']??'—'}}</strong></div><p class="agent-release-description">Current agent update</p><a class="agent-update-button" href="{{route('admin.factory-agent.updater')}}">Download updater</a></div>
    @endforelse
    </div>
    <details class="agent-update-help"><summary>How to update the existing Print PC</summary><p>Finish active work, then download the latest updater on the Print PC and open it from Downloads. It updates <code>C:\LuckyBagsFactory</code> and restarts the listener. Your .env, credentials, identity and workspaces are preserved.</p><p>No new .env settings are required. Explicit version or source-hash pins may need the exact manual change shown by the updater. Downloaded updater links expire after 48 hours. Older ZIPs are for reference or a supervised recovery; use the latest updater for routine updates.</p></details>
</section>
<p class="agent-monitor-footnote">Authorize preparation from Ready for Print. Review and sign Handoff before artwork is delivered to Epson. Connection status does not confirm physical printer readiness.</p>
<script type="application/json" data-agent-initial>@json($monitoring)</script>
</div>