<?php
namespace App\Http\Controllers;
use App\Models\JerseyOrder;
use App\Services\Shopify\JerseyOrderSyncService;
use Illuminate\Support\Facades\Cache;
class ApparelOverviewController extends Controller {
    public function index() {
        $summary=['unfulfilled'=>JerseyOrder::where('shopify_fulfillment_status','UNFULFILLED')->count(),'partially_fulfilled'=>JerseyOrder::where('shopify_fulfillment_status','PARTIALLY_FULFILLED')->count(),'fulfilled'=>JerseyOrder::where('shopify_fulfillment_status','FULFILLED')->count(),'total'=>JerseyOrder::count(),'pending_remarks'=>JerseyOrder::whereNotNull('remark')->whereNull('remark_processed_at')->count()];
        $lastSync=Cache::get(JerseyOrderSyncService::LAST_SUCCESSFUL_SYNC_CACHE_KEY_V2) ?? JerseyOrder::max('last_synced_at');
        try{$lastSync=$lastSync ? \Illuminate\Support\Carbon::parse($lastSync) : null;}catch(\Throwable){$lastSync=null;}
        $numberSort=JerseyOrder::query()->getConnection()->getDriverName()==='mysql' ? "CAST(REPLACE(shopify_order_number, '#', '') AS UNSIGNED) DESC" : "CAST(REPLACE(shopify_order_number, '#', '') AS INTEGER) DESC";
        return view('dashboard.apparel',['summary'=>$summary,'recentOrders'=>JerseyOrder::orderByDesc('ordered_at')->orderByRaw($numberSort)->orderByDesc('id')->limit(8)->get(),'lastSync'=>$lastSync]);
    }
}
