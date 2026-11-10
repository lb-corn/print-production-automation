@php
    $sync = \App\Services\OrderSyncService::status();
    $syncLabels = ['never'=>'Not synced yet','running'=>'Syncing orders','success'=>'Sync complete','partial'=>'Partially synced','error'=>'Sync needs attention','interrupted'=>'Sync interrupted'];
    $formatSyncTime = fn($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, g:i A T') : 'Not yet';
@endphp
<section class="order-sync-status" id="order-sync-status" aria-label="Common order sync status" aria-live="polite" data-status-url="{{route('orders.sync-status')}}" data-order-sync-state="{{$sync['state']}}">
    <div class="order-sync-heading">
        <div><strong>Latest sync</strong><span class="order-sync-badge {{$sync['state']}}">{{$syncLabels[$sync['state']] ?? 'Unknown'}}</span></div>
        <p>Automatic sync every 10 minutes &middot; Next expected: <strong>{{$formatSyncTime($sync['next_expected_at'])}}</strong></p>
    </div>
    <div class="order-sync-meta">
        <span>Last run: {{$formatSyncTime($sync['finished_at'] ?? $sync['started_at'] ?? null)}}@if(isset($sync['source'])) &middot; {{ucfirst($sync['source'])}}@endif</span>
        @if($sync['schedule_health']==='unconfirmed')<span class="order-sync-notice">Automatic scheduler not confirmed yet.</span>
        @elseif($sync['schedule_health']==='overdue')<span class="order-sync-notice">Automatic sync is overdue. Check the server scheduler.</span>
        @else<span>Last automatic run: {{$formatSyncTime($sync['last_scheduled_at'])}}</span>@endif
    </div>
    <div class="order-sync-results">
        @foreach(['bags'=>'Bags','apparel'=>'Apparel'] as $area=>$label)
        @if(auth()->user()->allows($area.'.overview.view') || auth()->user()->allows($area.'.sync.edit'))
        @php($result=$sync['areas'][$area] ?? [])
        <div class="order-sync-result">
            <div><strong>{{$label}}</strong><span>{{['success'=>'Synced','error'=>'Failed','running'=>'Syncing…','pending'=>'Waiting','interrupted'=>'Interrupted'][$result['state'] ?? ''] ?? 'Not synced yet'}}</span></div>
            @if(($result['state'] ?? '')==='success')
                @php($stats=$result['stats'])
                @if($area==='bags')<p><b>{{$stats['shopify_items']}}</b> current order lines &middot; {{$stats['added']}} added &middot; {{$stats['updated']}} updated</p>
                <small>{{$stats['matched']}} artwork matched &middot; {{$stats['custom_items'] ?? 0}} custom &middot; {{$stats['processed_skipped']}} already in production</small>
                @else<p><b>{{$stats['jersey_line_items_matched']}}</b> order lines checked &middot; {{$stats['inserted']}} added &middot; {{$stats['updated']}} updated</p>
                <small>{{$stats['shopify_orders_scanned']}} Shopify orders scanned &middot; Incremental sync</small>@endif
            @elseif(isset($result['message']))<p class="order-sync-notice">{{$result['message']}}</p>
            @else<p>Orders and production data sync together from Overview.</p>@endif
            @if(isset($result['warning']))<p class="order-sync-notice">{{$result['warning']}}</p>@endif
            <small>Last success: {{$formatSyncTime($result['last_success_at'] ?? null)}}</small>
        </div>
        @endif
        @endforeach
    </div>
</section>
