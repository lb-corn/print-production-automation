@php($syncArea=request('area',auth()->user()->allows('bags.overview.view')?'bags':'apparel'))
<section class="order-sync-card" aria-label="Shared order synchronization">
    <header class="order-sync-header">
        <div class="sync-title-group"><span class="sync-header-icon">@include('partials.nav-icon',['name'=>'sync'])</span><div class="order-sync-title"><span class="order-sync-eyebrow">SHOPIFY &middot; PRODUCTION</span><h2>Order synchronization</h2><p>Bag and apparel orders · {{\App\Services\PartnerContext::label()}}</p></div></div>
        @if(auth()->user()->allows('bags.sync.edit') || auth()->user()->allows('apparel.sync.edit'))
        <form class="overview-sync-action" method="post" action="{{route('orders.sync')}}" data-order-sync-form>
            @csrf<input type="hidden" name="area" value="{{$syncArea}}">
            <button type="submit" class="primary" data-order-sync-button>Sync orders</button>
            <span>Syncs {{auth()->user()->allows('bags.sync.edit') && auth()->user()->allows('apparel.sync.edit') ? 'bags & apparel' : (auth()->user()->allows('bags.sync.edit') ? 'bags' : 'apparel')}}</span>
        </form>
        @endif
        @include('partials.partner-filter')
    </header>
    @include('partials.order-sync-status')
</section>
