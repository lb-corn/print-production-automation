<div data-agent-monitor data-status-url="{{route('admin.factory-agent.status',[],false)}}" data-control-url="{{route('admin.factory-agent.processing',['agent'=>'AGENT_ID'],false)}}" data-csrf="{{csrf_token()}}">
<div class="agent-monitor-heading"><p>Connection and information reported by the Factory PC.</p><button type="button" class="chip" data-agent-refresh>Refresh status</button></div>
<p class="agent-monitor-notice" data-agent-notice role="status" aria-live="polite">Loading connection status…</p>
<div data-agent-cards class="agent-monitor-cards"></div>
<details class="panel portal-setup"><summary>Repair background startup on an existing installation</summary>
<p>Use this once if the portal connects only while a terminal is open. This preserves the existing agent, .env, credentials, approvals and print journals.</p>
<ol><li>Pause new jobs above. Let any active operation finish; do not stop a worker during delivery.</li><li>Stop the old scheduled listener and close the manual listener with Ctrl+C. The repair refuses to proceed while agent processes are running.</li><li>Save the repair script into the existing C:\LuckyBagsFactory folder, then run it as the normal printer operator:</li></ol>
<a class="chip" href="{{route('admin.factory-agent.repair-startup',[],false)}}">Download startup repair</a>
<pre>cd C:\LuckyBagsFactory
.\repair-background-startup.ps1</pre>
<p>If Windows blocks downloaded scripts, have the operator or IT approve the reviewed file under the PC's execution policy. Do not disable organizational policy.</p>
<p>The task uses a headless console host, starts at operator sign-in and checks once a minute for a stopped listener. It does not launch duplicate running tasks or retry interrupted print phases. No terminal needs to remain open.</p>
<p><strong>Verify before printing:</strong> close all terminal windows and confirm Online with a fresh report for five minutes. Also verify after sign-out/sign-in. Enable portal jobs only after these checks pass. If Offline, inspect task status with <code>.\repair-background-startup.ps1 -StatusOnly</code> and the agent log.</p>
</details>
<details class="panel portal-setup"><summary>One-time PC setup for portal jobs</summary>
<p>A connection reporter cannot launch a worker. Install the background listener once on the existing Factory PC, then enable portal jobs above. After that, Queue &amp; prepare and signed handoff in this portal launch temporary workers automatically.</p>
<p>Keep the PC powered on, connected and the printer operator signed in. The portal cannot switch on a PC. Epson hot-folder registration and physical printing still require Epson setup; connection alone does not prove printer readiness.</p>
<ol><li>Stop the old reporter with Ctrl+C and close any running factory worker. If its scheduled task is running, stop it before updating.</li><li>Download both files below into the existing <code>C:\LuckyBagsFactory</code> folder. Keep the existing .env, credentials and production workspace.</li><li>Run this once in a standard, non-elevated operator PowerShell window:</li></ol>
<div class="confirmation-actions"><a class="chip" href="{{route('admin.factory-agent.package',[],false)}}">Download agent package</a><a class="chip" href="{{route('admin.factory-agent.setup',[],false)}}">Download setup script</a></div>
@if(!empty($portalPackage['portal_zip_sha256']))<pre>cd C:\LuckyBagsFactory
.\install-portal-control.ps1 -ZipPath .\LuckyBags-Factory-Agent.zip -ExpectedSha256 {{$portalPackage['portal_zip_sha256']}}</pre>@else<p class="admin-notice">The verified setup package is not available in this deployment yet.</p>@endif
<p>After setup, refresh this page and enable portal jobs for the connected listener. The listener starts at operator sign-in. Pause here blocks new phases; it does not interrupt a current copy or stop the printer.</p>
</details>
<script type="application/json" data-agent-initial>@json($monitoring)</script>
</div>
