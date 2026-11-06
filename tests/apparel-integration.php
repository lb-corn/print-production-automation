<?php
// Uses the same isolated in-memory application as the complete permission suite.
require __DIR__.'/role-permissions.php';
require __DIR__.'/fixtures/apparel-order.php';
require __DIR__.'/material-fixture.php';
use App\Models\JerseyOrder;
use App\Models\Role;
use App\Services\Shopify\JerseyOrderSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL: '.$e.PHP_EOL);exit(1);});
config(['services.shopify.store'=>'apparel-test.myshopify.com','services.shopify.client_id'=>'test-client','services.shopify.client_secret'=>'test-secret','services.shopify.api_version'=>'2026-07']);
Http::preventStrayRequests();
$sync=app(JerseyOrderSyncService::class);
$line=apparelOrderPayload()['lineItems']['nodes'][0];
check($sync->isJerseyLineItem($line),'Jersey title is included');
$hoodie=$line;data_set($hoodie,'variant.product.title','Team Hoodie');check($sync->isJerseyLineItem($hoodie),'Hoodie title is included');
$fee=$line;$fee['title']='Custom Jersey Shipping Fee';check(!$sync->isJerseyLineItem($fee),'Shipping fees excluded');
$bagLine=$line;data_set($bagLine,'variant.product.title','Cornhole Bags');data_set($bagLine,'variant.product.tags',[]);$bagLine['title']='Cornhole Bags';check(!$sync->isJerseyLineItem($bagLine),'Bag products excluded from apparel sync');
$details=$sync->normalizeDetails([['key'=>'Player Name','value'=>'  Test   Player  ']],[],'Short Sleeve Hoodie / 3X');
check($details['display_name']==='Test Player' && $details['style']==='Short Sleeve Hoodie' && $details['size']==='3X' && $details['color']===null,'Source name/style/size normalization preserved');
Http::fake(['*/admin/oauth/access_token'=>Http::response(['access_token'=>'test-access-token','expires_in'=>3600]),'*/graphql.json'=>Http::sequence()->push(apparelOrdersResponse([apparelOrderPayload()]))->push(apparelOrdersResponse([apparelOrderPayload(['name'=>'#1002','displayFulfillmentStatus'=>'PARTIALLY_FULFILLED','lineItems.nodes.0.quantity'=>4])]))->push(apparelOrdersResponse([apparelOrderPayload(['name'=>'#1002'])]))]);
$stats=$sync->sync(full:true,limit:1);
check($stats['inserted']===1 && JerseyOrder::count()===1,'Full sync imports a single local apparel line');
$order=JerseyOrder::firstOrFail();
Auth::setUser($manager);
function apparelRequest($user,array $data=[]):Request {global $app;$r=Request::create('/apparel/orders','PATCH',$data);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($app['session']->driver());return $r;}
$tracking=new App\Http\Controllers\JerseyOrderTrackingController;
$remarks=new App\Http\Controllers\JerseyOrderRemarkController;
$processed=new App\Http\Controllers\JerseyOrderRemarkProcessedController;
$response=$tracking->update(apparelRequest($manager,['tracking_number'=>' LOCAL-123 ','return_to'=>'/apparel/orders?search=1001']),$order);
check($order->fresh()->tracking_number==='LOCAL-123' && $order->fresh()->tracking_updated_by===$manager->id,'Local tracking stores actor and trimmed value');
check(str_ends_with($response->getTargetUrl(),'/apparel/orders?search=1001'),'Tracking preserves report filters');
$remarks->update(apparelRequest($manager,['remark'=>'Confirm artwork','return_to'=>'https://outside.invalid']),$order->fresh());
$processed->update(apparelRequest($manager,['processed'=>1]),$order->fresh());
$localFields=['tracking_number','tracking_updated_at','tracking_updated_by','remark','remark_updated_at','remark_updated_by','remark_processed_at','remark_processed_by'];
$before=json_encode($order->fresh()->only($localFields));
$sync->sync(full:true,limit:1);
check(json_encode($order->fresh()->only($localFields))===$before && JerseyOrder::count()===1,'Repeated sync preserves every local tracking and remark field');
check($order->fresh()->quantity===4 && $order->fresh()->shopify_order_number==='#1002','Resync refreshes remote quantities and status');
$sync->sync(limit:1,source:'scheduled');
check(Cache::get(JerseyOrderSyncService::LAST_SYNC_SOURCE_CACHE_KEY)==='scheduled','Incremental sync records scheduled source');
check(Cache::get(JerseyOrderSyncService::LAST_SUCCESSFUL_SYNC_CACHE_KEY_V2)!==null,'Sync health records success');
$lock=Cache::lock('apparel:orders:sync',900);$lock->get();
try{$sync->sync();throw new LogicException('Concurrent sync allowed');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'already running'),'Manual and scheduled sync share a lock');}finally{$lock->release();}
$remarks->update(apparelRequest($manager,['remark'=>'Changed instructions']),$order->fresh());
check($order->fresh()->remark_processed_at===null && $order->fresh()->remark_processed_by===null,'Editing remarks clears prior processing approval');
$approvalRole=Role::create(['slug'=>'apparel-test-approver','name'=>'Apparel approval only','permissions'=>['apparel.overview.view','apparel.orders.view','apparel.remarks.approve']]);
$approver=userFor($approvalRole->slug);
$processed->update(apparelRequest($approver,['processed'=>1]),$order->fresh());
check($order->fresh()->remark_processed_by===$approver->id,'Cross-role approver can process apparel remarks');
rejected(fn()=>$remarks->update(apparelRequest($approver,['remark'=>'Forbidden']),$order->fresh()),'Approval-only user cannot edit remarks');
rejected(fn()=>$tracking->update(apparelRequest($approver,['tracking_number'=>'Forbidden']),$order->fresh()),'Approval-only user cannot edit tracking');
foreach(['FULFILLED','CANCELLED'] as $state){$order->update(['shopify_fulfillment_status'=>$state==='FULFILLED'?'FULFILLED':'UNFULFILLED','shopify_cancelled_at'=>$state==='CANCELLED'?now():null]);try{$tracking->update(apparelRequest($manager,['tracking_number'=>'Blocked']),$order->fresh());throw new LogicException('Locked order changed');}catch(Illuminate\Validation\ValidationException $e){check(isset($e->errors()['tracking_number']),'Tracking locked for '.$state);}}
$order->update(['shopify_fulfillment_status'=>'UNFULFILLED','shopify_cancelled_at'=>null]);
try{$tracking->update(apparelRequest($manager,['tracking_number'=>'   ']),$order->fresh());throw new LogicException('Blank tracking accepted');}catch(Illuminate\Validation\ValidationException $e){check(true,'Blank tracking rejected');}
$response=$tracking->update(apparelRequest($manager,['tracking_number'=>'SAFE','return_to'=>'//outside.invalid/orders']),$order->fresh());check($response->getTargetUrl()===route('apparel.orders.index'),'Return URL cannot redirect off site');
$report=new App\Http\Controllers\JerseyOrderController;
for($i=1;$i<=28;$i++){$row=$sync->normalizeLineItem(apparelOrderPayload(['id'=>'gid://shopify/Order/'.($i+2000),'name'=>'#'.($i+2000)]),array_replace($line,['id'=>'gid://shopify/LineItem/'.($i+2000)]));JerseyOrder::create($row);}
$view=$report->index(Request::create('/apparel/orders?fulfillment_status=all&per_page=25'));
check($view->getData()['orders']->count()===25 && $view->getData()['orders']->first()->shopify_order_number==='#2028','Numeric order sorting and pagination preserved');
$filtered=$report->index(Request::create('/apparel/orders?search=SAFE&fulfillment_status=all'));
check($filtered->getData()['orders']->total()===1,'Search includes local tracking');
$filtered=$report->index(Request::create('/apparel/orders?product=Team%20Jersey&color=Green&size=Large'));
check($filtered->getData()['orders']->total()===29,'Product, color and size filters combine');
try{$report->index(Request::create('/apparel/orders?per_page=999'));throw new LogicException('Invalid page size accepted');}catch(Illuminate\Validation\ValidationException $e){check(isset($e->errors()['per_page']),'Page-size whitelist retained');}
Auth::setUser($manager);view()->share('errors',new Illuminate\Support\ViewErrorBag);
$html=$view->render();
foreach(['order-modal','data-filter-form','data-sync-form','data-preview-product','First Name','Last Name','pagination-controls'] as $hook)check(str_contains($html,$hook),'Shared-theme report retains '.$hook);
check(str_contains($report->index(Request::create('/apparel/orders?search=SAFE'))->render(),'remark-processed-row'),'Processed remarks retain their report highlight');
check(!str_contains($html,'Private technical value'),'Internal personalization does not leak into report rows');
$payload=$report->show($order->fresh())->getData(true);
check($payload['order']['shopify_order_gid'] && $payload['order']['custom_fields'][3]['key']==='_internal','Detail viewer includes source references and technical fields');
Auth::setUser($approver);$html=$report->index(Request::create('/apparel/orders?fulfillment_status=all&per_page=100'))->render();
check(str_contains($html,'Remark processed') && !str_contains($html,'Save Remark') && !str_contains($html,'data-sync-form'),'Approver view exposes processing without editing or sync');
$overview=app(App\Http\Controllers\ApparelOverviewController::class)->index();check($overview->getData()['summary']['total']===29,'Apparel overview uses real local order totals');
$event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($e)=>str_contains($e->command ?? '','shopify:sync-jerseys'));
check($event && $event->expression==='*/10 * * * *' && $event->withoutOverlapping && !str_contains($event->command,'--full'),'Ten-minute incremental schedule retained');
$roleRequest=apparelRequest($admin,['name'=>'Granular remark approver','permissions'=>['apparel.remarks.approve']]);$roles->store($roleRequest);$new=Role::where('name','Granular remark approver')->firstOrFail();
check(in_array('apparel.orders.view',$new->permissions,true) && !in_array('apparel.tracking.edit',$new->permissions,true),'Approval grant includes order view without tracking edit');
check(App\Models\ProductionItem::count()===0 && App\Models\PrintBatch::count()===0,'Apparel integration leaves bag production records untouched');
$legacy=Role::create(['slug'=>'legacy-apparel-test','name'=>'Legacy combined permissions','permissions'=>['bags.artwork.view','apparel.orders.edit','apparel.orders.approve']]);
(require __DIR__.'/../database/migrations/2026_11_06_000013_expand_apparel_permissions.php')->up();
$expanded=$legacy->fresh()->permissions;
check(in_array('bags.artwork.view',$expanded,true) && in_array('apparel.tracking.edit',$expanded,true) && in_array('apparel.remarks.edit',$expanded,true) && in_array('apparel.remarks.approve',$expanded,true) && !in_array('apparel.orders.edit',$expanded,true),'Migration expands existing combined permissions without changing bag access');
Illuminate\Support\Carbon::setTestNow(now()->addMinute()->startOfSecond());$sync->recordLastSuccessfulSync('manual');
$sync->recordSyncFailed('manual',new RuntimeException('test-secret access_token=shpat_test'));$health=$report->index(Request::create('/apparel/orders'))->getData()['syncHealth'];
check($health['state']==='error' && !str_contains($health['error'],'test-secret') && !str_contains($health['error'],'shpat_test'),'Sync failure health redacts secrets');
$sync->recordLastSuccessfulSync('manual');check($report->index(Request::create('/apparel/orders'))->getData()['syncHealth']['state']==='active','Successful sync clears prior error health');
Illuminate\Support\Carbon::setTestNow();
$manualSync=new class extends JerseyOrderSyncService {
    public array $calls=[];
    public function __construct(){}
    public function sync(bool $full=false,?string $since=null,?int $limit=null,string $source='command'):array {
        $this->calls[]=[$full,$since,$limit,$source];
        return ['pages_fetched'=>0,'shopify_orders_scanned'=>0,'jersey_line_items_matched'=>0,'inserted'=>0,'updated'=>0,'skipped'=>0];
    }
};
$webSync=new App\Http\Controllers\ShopifySyncController;
$webSync->store($manualSync);
check($manualSync->calls===[[false,null,null,'manual']],'Manual report sync is incremental');
$manualLock=Cache::lock('shopify:sync-jerseys:web',600);$manualLock->get();
try{$webSync->store($manualSync);check(count($manualSync->calls)===1,'Overlapping manual sync does not start another import');}finally{$manualLock->release();}
$app->instance(JerseyOrderSyncService::class,$manualSync);
$exit=Illuminate\Support\Facades\Artisan::call('shopify:sync-jerseys',['--source'=>'scheduled']);
check($exit===0 && end($manualSync->calls)===[false,null,null,'scheduled'],'Imported CLI command passes scheduled incremental options');
Auth::setUser($manager);
$shortcutHtml=$report->index(Request::create('/apparel/orders?search=SAFE&per_page=50'))->render();
preg_match_all('/<a class="summary-card summary-filter"[^>]*href="([^"]+)"[^>]*>/', $shortcutHtml, $shortcutLinks);
check(count($shortcutLinks[1])===4,'All four fulfillment summary cards are actionable links');
foreach(['UNFULFILLED','PARTIALLY_FULFILLED','FULFILLED','all'] as $index=>$status) {
    parse_str(parse_url(html_entity_decode($shortcutLinks[1][$index]),PHP_URL_QUERY),$query);
    check(($query['fulfillment_status'] ?? null)===$status && ($query['per_page'] ?? null)==='50' && !isset($query['search'],$query['page']),'Summary shortcut selects global status and retains page size: '.$status);
}
check(substr_count(implode('',$shortcutLinks[0]),'aria-current="page"')===1,'Only the selected status shortcut is highlighted');
$baseWaiting=$report->index(Request::create('/apparel/orders'))->getData()['orders']->total();
$historyCases=[
 ['cancelled', ['shopify_cancelled_at'=>now()]],
 ['refunded', ['shopify_financial_status'=>'REFUNDED']],
 ['null-financial', ['shopify_financial_status'=>null]],
 ['partial-refund', ['shopify_financial_status'=>'PARTIALLY_REFUNDED']],
 ['fulfilled-refund', ['shopify_financial_status'=>'REFUNDED','shopify_fulfillment_status'=>'FULFILLED']],
];
$historyIds=[];
foreach($historyCases as [$label,$extra]) {
 $row=$sync->normalizeLineItem(apparelOrderPayload(['id'=>'gid://shopify/Order/history-'.$label,'name'=>'#H-'.$label]),array_replace($line,['id'=>'gid://shopify/LineItem/history-'.$label]));
 $historyIds[$label]=JerseyOrder::create(array_merge($row,$extra))->id;
}
$waiting=$report->index(Request::create('/apparel/orders?per_page=100'))->getData();$waitingIds=$waiting['orders']->pluck('id')->all();
check(!in_array($historyIds['cancelled'],$waitingIds,true) && !in_array($historyIds['refunded'],$waitingIds,true),'Unfulfilled excludes cancelled and fully refunded orders');
check(in_array($historyIds['null-financial'],$waitingIds,true) && in_array($historyIds['partial-refund'],$waitingIds,true),'Unfulfilled retains null financial states and partially refunded work');
check($waiting['orders']->total()===$baseWaiting+2 && $waiting['summary']['unfulfilled']===$baseWaiting+2,'Clickable Unfulfilled count matches eligible global orders');
$allIds=$report->index(Request::create('/apparel/orders?fulfillment_status=all&per_page=100'))->getData()['orders']->pluck('id')->all();
check(!array_diff(array_values($historyIds),$allIds),'All statuses retains historical cancelled and refunded orders');
$fulfilledIds=$report->index(Request::create('/apparel/orders?fulfillment_status=FULFILLED&per_page=100'))->getData()['orders']->pluck('id')->all();
check(!in_array($historyIds['fulfilled-refund'],$fulfilledIds,true),'Other production status lists also exclude fully refunded orders');
$paidHtml=$report->index(Request::create('/apparel/orders?fulfillment_status=all&per_page=100'))->render();
check(!preg_match('/<span class="badge badge-neutral">\s*PAID\s*<\/span>/',$paidHtml) && str_contains($paidHtml,'REFUNDED'),'List omits Paid badges while preserving relevant archive warnings');
echo "SUCCESS $checks total permission and apparel checks; no live services used.\n";
