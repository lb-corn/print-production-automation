@php($progress=$factoryProgress??app(\App\Services\FactoryDispatchService::class)->progress($batch))
<section class="panel factory-progress" data-batch-progress data-progress-url="{{route('batch.progress',$batch,false)}}" data-state="{{$progress['severity']}}">
    <div class="panel-head"><div><h2>Factory progress</h2><p data-progress-freshness>Live status refreshes automatically</p></div></div>
    <div class="factory-progress-message" role="status" aria-live="polite"><h3 data-progress-title>{{$progress['title']}}</h3><p data-progress-message>{{$progress['message']}}</p></div>
    <progress aria-label="Artwork processing progress" hidden></progress><p class="muted" data-progress-count></p>
    <ol class="factory-stages" aria-label="Batch stages">
    @foreach(['QUEUED'=>'Queued','DOWNLOADING'=>'Preparing','PREPARED'=>'Handoff approval','DELIVERING'=>'Delivering','SENT_TO_RIP'=>'In Epson','PRINTED'=>'Print confirmed'] as $step=>$label)
        <li data-step="{{$step}}" class="{{$batch->status===$step?'is-current':''}}">{{$label}}</li>
    @endforeach
    </ol>
    @if(auth()->user()?->role!=='demo')
    <form data-progress-action="request" @if(!$progress['can_request']) hidden @endif method="post" action="{{route('batch.dispatch',$batch)}}" onsubmit="return confirm('Authorize the connected Factory PC to prepare this locked batch? This does not start physical printing.')">@csrf<input type="hidden" name="dispatch_confirmed" value="1"><button class="primary">Request automatic preparation</button></form>
    <button type="button" data-progress-action="handoff" @if(!$progress['can_handoff']) hidden @endif class="primary" data-print-confirm="HANDOFF" data-confirm-url="{{route('batch.edge-print',$batch)}}">Review &amp; sign handoff →</button>
    <button type="button" data-progress-action="confirm_printed" @if(!$progress['can_confirm_printed']) hidden @endif class="primary" data-print-confirm="PRINTED" data-confirm-url="{{route('batch.printed',$batch)}}">Confirm physical print completion →</button>
    @endif
</section>
