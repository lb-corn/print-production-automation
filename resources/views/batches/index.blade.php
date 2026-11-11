<x-layouts.app heading="Print Batches" subheading="Factory queue, signed handoffs and print history">
<x-production-ui::panel class="batch-list-panel">
    <x-production-ui::panel-header title="Batch history" :description="$batches->count().' '.\Illuminate\Support\Str::plural('batch',$batches->count()).' · Production queue and print history'">
        <x-slot:actions>@if(auth()->user()->allows('bags.queue.view'))<a class="chip" href="{{route('queue',['partner'=>\App\Services\PartnerContext::current()])}}">Ready-to-print orders →</a>@endif</x-slot:actions>
    </x-production-ui::panel-header>
    <x-production-ui::toolbar class="batch-filter-toolbar" aria-label="Batch filters">
    @include('partials.partner-filter')
    <nav class="batch-stage-filters" aria-label="Filter batch stage"><a class="chip {{!request('stage')?'active':''}}" href="{{route('batches',['partner'=>\App\Services\PartnerContext::current()])}}" @if(!request('stage'))aria-current="page"@endif>All batches</a><a class="chip {{request('stage')==='active'?'active':''}}" href="{{route('batches',['stage'=>'active','partner'=>\App\Services\PartnerContext::current()])}}" @if(request('stage')==='active')aria-current="page"@endif>In progress</a><a class="chip {{request('stage')==='completed'?'active':''}}" href="{{route('batches',['stage'=>'completed','partner'=>\App\Services\PartnerContext::current()])}}" @if(request('stage')==='completed')aria-current="page"@endif>Completed</a><a class="chip {{request('stage')==='failed'?'active':''}}" href="{{route('batches',['stage'=>'failed','partner'=>\App\Services\PartnerContext::current()])}}" @if(request('stage')==='failed')aria-current="page"@endif>Needs attention</a></nav>
    </x-production-ui::toolbar>
    @if($batches->isNotEmpty())<div class="batch-list-heading" aria-hidden="true"><span>Batch</span><span>Stage</span><span>Items / Files</span><span>Created · Sri Lanka</span><span></span></div>@endif
    <div class="batch-list">
    @forelse($batches as $batch)
        <article class="batch-list-item">
            <div class="batch-identity"><a href="{{route('batch.show',$batch)}}" title="{{$batch->batch_id}}"><strong>{{$batch->batch_id}}</strong></a><small>{{$batch->status==='CANCELLED'?'History only · not queued':($batch->payload['material_group']??'Production batch')}}</small></div>
            <span class="batch-state state-{{strtolower($batch->status)}}"><i aria-hidden="true"></i>{{str_replace('_',' ',ucfirst(strtolower($batch->status)))}}</span>
            <div class="batch-counts"><strong>{{$batch->order_count}} <small>{{\Illuminate\Support\Str::plural('item',$batch->order_count)}}</small></strong><strong>{{$batch->artwork_file_count}} <small>{{\Illuminate\Support\Str::plural('file',$batch->artwork_file_count)}}</small></strong></div>
            <time datetime="{{$batch->created_at->toIso8601String()}}">{{$batch->created_at->timezone('Asia/Colombo')->format('d M Y')}}<small>{{$batch->created_at->timezone('Asia/Colombo')->format('g:i A')}} · UTC{{$batch->created_at->timezone('Asia/Colombo')->format('P')}}</small></time>
            <a class="batch-open" href="{{route('batch.show',$batch)}}" aria-label="View batch {{$batch->batch_id}}">View batch <span aria-hidden="true">→</span></a>
        </article>
    @empty
        <x-production-ui::empty-state title="No batches match these filters" description="Choose another company or stage, or prepare approved orders from Ready for Print." icon="batches">@if(auth()->user()->allows('bags.queue.view'))<a class="chip" href="{{route('queue',['partner'=>\App\Services\PartnerContext::current()])}}">View ready orders →</a>@endif</x-production-ui::empty-state>
    @endforelse
    </div>
</x-production-ui::panel>
</x-layouts.app>
