<div class="workspace-stat-tiles" data-workspace-stats data-status-url="{{route('workspace.status')}}">
    <a href="{{route('queue')}}" class="workspace-stat-ready"><strong data-workspace-count="ready">{{$workspaceStats['ready']}}</strong><span>Ready</span></a>
    <a href="{{route('batches',['stage'=>'active'])}}" class="workspace-stat-print"><strong data-workspace-count="in_print">{{$workspaceStats['in_print']}}</strong><span>In print</span></a>
    <a href="{{route('batches',['stage'=>'completed'])}}" class="workspace-stat-done"><strong data-workspace-count="completed">{{$workspaceStats['completed']}}</strong><span>Completed</span></a>
</div>
<a class="workspace-guide-link" href="{{route('docs')}}">Quick guide →</a>
