<div class="factory-agent-page" data-agent-monitor data-updater-url="{{route('admin.factory-agent.updater',[],false)}}" data-package-url="{{route('admin.factory-agent.package',[],false)}}" data-installer-url="{{route('admin.factory-agent.setup',[],false)}}" data-package-sha="{{$agentPackage['portal_zip_sha256']??''}}" data-package-version="{{$agentPackage['version']??''}}" data-handoff-url="{{route('admin.factory-agent.handoff-control',['agent'=>'AGENT_ID'],false)}}" data-csrf="{{csrf_token()}}" data-status-url="{{route('admin.factory-agent.status',[],false)}}">
<div class="panel-head agent-summary-heading"><div><h2>Factory connection</h2><p>Live connection and activity from the Printer PC.</p></div><button type="button" class="chip" data-agent-refresh>Refresh status</button></div>
<p class="agent-monitor-notice" data-agent-notice role="status" aria-live="polite">Checking connection…</p>
<details class="panel agent-update-help agent-troubleshoot">
        <summary>Troubleshoot connection — test and fix</summary>
        <p>Check <strong>Last report</strong> and expand the Printer PC’s <strong>Technical details</strong> below for the last failed operation and diagnostic results. Offline means no recent heartbeat; the last reported failure may be historical rather than the current cause. If no PC is listed, no heartbeat has been received.</p>
        <p>Download the read-only test beside the existing scripts in <code>C:\LuckyBagsFactory</code>, then run <code>.\test-connectivity.ps1</code> in PowerShell from that folder. It checks the local API address, authentication and monitoring database without starting production work.</p>
        <a class="agent-package-button" href="{{route('admin.factory-agent.connectivity-script')}}">Download connection test</a>
        <details class="agent-test-errors"><summary>Test results and fixes</summary>
        <dl class="agent-checks">
            <div><dt>HTTP 401</dt><dd>The PC key is missing or differs from the server. Have the administrator compare FACTORY_API_KEY privately, then refresh the server configuration cache.</dd></div>
            <div><dt>HTTP 503</dt><dd>Check the server key configuration and monitoring database migration.</dd></div>
            <div><dt>HTTP 404 / redirect</dt><dd>Check PRODUCTION_API on the PC: use the final HTTPS portal address ending in /api, and verify the Factory routes are deployed.</dd></div>
            <div><dt>Timeout / TLS / DNS</dt><dd>Check PC internet access, DNS, Windows time and firewall access to the portal over HTTPS. Keep certificate verification enabled.</dd></div>
            <div><dt>HTTP 429</dt><dd>Wait for the request limit to reset before testing again.</dd></div>
            <div><dt>Heartbeat HTTP 422 / 409 in local logs</dt><dd>For stale or future timestamps, synchronize the Printer PC clock. For duplicate reports, check for multiple listener instances using the same agent identity. Review the validation message before changing anything.</dd></div>
            <div><dt>Test passed, still offline</dt><dd>The test does not send recurring heartbeats. Check the installed listener and Windows Task Scheduler history. A stopped PC, stopped listener or old agent without heartbeats cannot keep the portal connected.</dd></div>
        </dl>
        </details>
        <p>Do not share API keys or .env files. Do not restart a worker during active work; inspect the current job first.</p>
</details>
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
