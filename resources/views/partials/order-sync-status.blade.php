@php
    $sync=\App\Services\OrderSyncService::status();
    $syncLabels=['never'=>'Not synced yet','running'=>'Syncing orders','success'=>'Sync complete','partial'=>'Partially synced','error'=>'Sync failed','interrupted'=>'Sync interrupted'];
    $formatSyncTime=fn($value)=>$value?\Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, g:i A T'):'Not yet';
@endphp
<section class="order-sync-status" id="order-sync-status" aria-label="Common order sync status" aria-live="polite" data-status-url="{{route('orders.sync-status')}}" data-order-sync-state="{{$sync['state']}}">
    @php
        $syncPartners=\App\Services\PartnerContext::allowed(auth()->user());
    @endphp
    @if(count($syncPartners)>1)<div class="partner-sync-results">@foreach($syncPartners as $syncPartner)
        @php
            $shopSync=\Illuminate\Support\Facades\Cache::get($syncPartner==='lucky'?\App\Services\OrderSyncService::STATUS_KEY:\App\Services\OrderSyncService::STATUS_KEY.'.'.$syncPartner,[]);
        @endphp
        <span><strong>{{\App\Services\PartnerContext::label($syncPartner)}}</strong><small>{{['success'=>'Synced','partial'=>'Needs review','error'=>'Needs retry','running'=>'Syncing…'][$shopSync['state']??'']??'Not synced yet'}}</small></span>
    @endforeach</div>@endif
    <div class="sync-status-top"><span class="order-sync-badge {{$sync['state']}}">{{$syncLabels[$sync['state']]??'Not synced yet'}}</span><span class="sync-frequency">Every 10 minutes</span></div>
    <div class="sync-timing-grid">
        <div>@include('partials.nav-icon',['name'=>'clock'])<div><span>Last update</span><strong>{{$formatSyncTime($sync['finished_at']??$sync['started_at']??null)}}</strong></div></div>
        <div>@include('partials.nav-icon',['name'=>'sync'])<div><span>Next planned sync</span><strong>{{$formatSyncTime($sync['next_expected_at'])}}</strong></div></div>
    </div>
    @if($sync['schedule_health']==='overdue')<p class="sync-delay-notice">Automatic sync is delayed. You can sync orders manually.</p>@endif
    <div class="order-sync-results">
    @foreach(['bags'=>'Bags','apparel'=>'Apparel'] as $area=>$label)
    @if(auth()->user()->allows($area.'.overview.view') || auth()->user()->allows($area.'.sync.edit'))
    @php
        $result=$sync['areas'][$area]??[];$done=($result['state']??'')==='success';$stats=$result['stats']??[];
        $checked=$done?($area==='bags'?($stats['shopify_items']??0):($stats['jersey_line_items_matched']??0)):null;
        $added=$done?($area==='bags'?($stats['added']??0):($stats['inserted']??0)):null;
    @endphp
    <div class="order-sync-result" data-sync-area="{{$area}}">
        <div class="sync-result-heading"><span class="sync-area-icon">@include('partials.nav-icon',['name'=>$area==='bags'?'bag-production':'apparel-production'])</span><strong>{{$label}}</strong><span class="sync-area-state">{{['success'=>'Synced','error'=>'Needs retry','running'=>'Syncing…','pending'=>'Waiting','interrupted'=>'Needs retry'][$result['state']??'']??'Not synced yet'}}</span></div>
        <div class="sync-result-counts"><div><b>{{$checked??'—'}}</b><span>{{$area==='bags'?'Current lines':'Lines checked'}}</span></div><div><b>{{$added??'—'}}</b><span>Added</span></div><div><b>{{$done?($stats['updated']??0):'—'}}</b><span>Updated</span></div></div>
        @if(($result['state']??'')==='error')<p class="order-sync-notice">Could not sync {{$label}}. Try again.</p>@endif
        @if(isset($result['warning']))<p class="order-sync-notice">{{$result['warning']}}</p>@endif
        @if(!empty($result['last_success_at']))<small class="sync-result-footer">Last success: {{$formatSyncTime($result['last_success_at'])}}</small>@endif
    </div>
    @endif
    @endforeach
    </div>
</section>
