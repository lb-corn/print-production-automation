<x-layouts.app heading="Print Batches" subheading="Factory queue, signed handoffs and print history">
<section class="panel batch-list-panel">
    <div class="panel-head"><div><h2>Batch history</h2><p>{{$batches->count()}} batches · Open a batch to review progress and signed confirmations</p></div><a class="chip" href="{{route('queue')}}">Ready-to-print orders →</a></div>
    <div class="toolbar batch-stage-filters"><a class="chip {{!request('stage')?'active':''}}" href="{{route('batches')}}">All batches</a><a class="chip {{request('stage')==='active'?'active':''}}" href="{{route('batches',['stage'=>'active'])}}">In progress</a><a class="chip {{request('stage')==='completed'?'active':''}}" href="{{route('batches',['stage'=>'completed'])}}">Completed</a><a class="chip {{request('stage')==='failed'?'active':''}}" href="{{route('batches',['stage'=>'failed'])}}">Needs attention</a></div><div class="batch-list-heading" aria-hidden="true"><span>Batch</span><span>Stage</span><span>Items / Files</span><span>Created · Sri Lanka</span><span></span></div>
    <div class="batch-list">
    @forelse($batches as $batch)
        <article class="batch-list-item">
            <div class="batch-identity"><a href="{{route('batch.show',$batch)}}" title="{{$batch->batch_id}}"><strong>{{$batch->batch_id}}</strong></a><small>Production batch</small></div>
            <span class="batch-state state-{{strtolower($batch->status)}}"><i aria-hidden="true"></i>{{str_replace('_',' ',ucfirst(strtolower($batch->status)))}}</span>
            <div class="batch-counts"><strong>{{$batch->order_count}} <small>items</small></strong><strong>{{$batch->artwork_file_count}} <small>files</small></strong></div>
            <time datetime="{{$batch->created_at->toIso8601String()}}">{{$batch->created_at->timezone('Asia/Colombo')->format('d M Y')}}<small>{{$batch->created_at->timezone('Asia/Colombo')->format('g:i A')}} · IST</small></time>
            <a class="batch-open" href="{{route('batch.show',$batch)}}" aria-label="View batch {{$batch->batch_id}}">View batch <span aria-hidden="true">→</span></a>
        </article>
    @empty
        <div class="queue-empty"><strong>No print batches yet</strong><p>Approve artwork, then create a batch from Ready for Print.</p><a class="primary" href="{{route('queue')}}">View ready orders →</a></div>
    @endforelse
    </div>
</section>
</x-layouts.app>
