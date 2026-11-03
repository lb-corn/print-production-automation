<div data-agent-monitor data-handoff-url="{{route('admin.factory-agent.handoff-control',['agent'=>'AGENT_ID'],false)}}" data-csrf="{{csrf_token()}}" data-status-url="{{route('admin.factory-agent.status',[],false)}}">
<div class="panel-head agent-summary-heading"><div><h2>Factory connection</h2><p>Live connection and activity from the Printer PC.</p></div><button type="button" class="chip" data-agent-refresh>Refresh status</button></div>
<p class="agent-monitor-notice" data-agent-notice role="status" aria-live="polite">Checking connection…</p>
<div data-agent-cards class="agent-monitor-cards"></div>
<p class="agent-monitor-footnote">Authorize preparation from Ready for Print. Review and sign Handoff before artwork is delivered to Epson. Connection status does not confirm physical printer readiness.</p>
<script type="application/json" data-agent-initial>@json($monitoring)</script>
</div>