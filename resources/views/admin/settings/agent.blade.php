<div data-agent-monitor data-status-url="{{route('admin.factory-agent.status',[],false)}}">
<div class="agent-monitor-heading"><p>Connection and information reported by the Factory PC.</p><button type="button" class="chip" data-agent-refresh>Refresh status</button></div>
<p class="agent-monitor-notice" data-agent-notice role="status" aria-live="polite">Loading connection status…</p>
<div data-agent-cards class="agent-monitor-cards"></div>
<div class="agent-connect-action"><div><strong>Connect this installation</strong><p>Run the connection-only reporter on the PC. It reports status without processing orders or printing.</p></div><a class="chip" href="{{route('admin.factory-agent.connection-reporter',[],false)}}">Download connection reporter</a></div>
<p class="agent-monitor-footnote">Copy the reporter beside the existing agent scripts, run .\report-connection.ps1 and keep its window open for live status. Connection status does not verify printer readiness.</p>
<script type="application/json" data-agent-initial>@json($monitoring)</script>
</div>