@php($syncArea=request('area',auth()->user()->allows('bags.overview.view')?'bags':'apparel'))
<section class="order-sync-card" aria-label="Shared order synchronization">
    <header class="order-sync-header">
        <div class="sync-title-group"><span class="sync-header-icon">@include('partials.nav-icon',['name'=>'sync'])</span><div class="order-sync-title"><h2>Order synchronization</h2><p>Bags &amp; apparel · {{\App\Services\PartnerContext::label()}}</p></div></div>
        <div class="sync-header-actions">
        @include('partials.partner-filter')
        @if(auth()->user()->allows('bags.sync.edit') || auth()->user()->allows('apparel.sync.edit'))
        <form class="overview-sync-action" method="post" action="{{route('orders.sync')}}" data-order-sync-form>
            @csrf<input type="hidden" name="area" value="{{$syncArea}}">
            <x-production-ui::button type="submit" data-order-sync-button>Sync orders</x-production-ui::button>
        </form>
        @endif
        </div>
    </header>
    @include('partials.order-sync-status')
</section>
