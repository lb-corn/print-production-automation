<?php
// Isolated DB/cache and fake integrations: no Shopify, Drive or printer access.
require __DIR__.'/role-permissions.php';
require __DIR__.'/material-fixture.php';
require __DIR__.'/fixtures/apparel-order.php';
use App\Services\{OrderSyncService,BacklogSyncService};
use App\Services\Shopify\JerseyOrderSyncService;
use Illuminate\Support\Facades\{Cache,Auth,Http,Artisan};
use Illuminate\Support\Carbon;
Http::preventStrayRequests();
$bags=new class extends BacklogSyncService {
    public int $calls=0; public bool $fail=false;
    public function __construct() {}
    public function sync():array { $this->calls++; if($this->fail)throw new RuntimeException('private test failure'); return ['shopify_items'=>16,'added'=>2,'updated'=>3,'matched'=>10,'custom_items'=>2,'processed_skipped'=>4,'artwork_schema_ready'=>true]; }
};
$apparelSync=new class extends JerseyOrderSyncService {
    public array $calls=[]; public bool $fail=false;
    public function __construct() {}
    public function sync(bool $full=false,?string $since=null,?int $limit=null,string $source='command'):array { $this->calls[]=[$full,$source]; if($this->fail)throw new RuntimeException('private test failure'); return ['jersey_line_items_matched'=>27,'inserted'=>1,'updated'=>4,'shopify_orders_scanned'=>12]; }
};
$combined=new OrderSyncService($bags,$apparelSync);$app->instance(OrderSyncService::class,$combined);
Carbon::setTestNow(Carbon::parse('2026-11-10 08:11:00','America/Chicago'));
check($combined->status()['schedule_health']==='unconfirmed','No scheduled run is claimed before it is observed');
$run=$combined->sync('scheduled');
check($bags->calls===1 && $apparelSync->calls===[[false,'scheduled']] && $run['state']==='success','Scheduled run syncs bags and incremental apparel together');
check($combined->status()['schedule_health']==='recent' && Carbon::parse($combined->status()['next_expected_at'])->format('H:i')==='08:20','Next expected run follows ten-minute boundaries');
$lastSuccess=$run['areas']['bags']['last_success_at'];$bags->fail=true;$run=$combined->sync('manual');
check($run['state']==='partial' && $run['areas']['apparel']['state']==='success','An integration failure does not prevent the other area syncing');
check($run['areas']['bags']['last_success_at']===$lastSuccess && !str_contains(json_encode($run),'private test failure'),'Failure preserves last success and does not expose integration errors');
$bags->fail=false;$apparelSync->fail=true;check($combined->sync()['state']==='partial','Apparel failures are also reported separately');$apparelSync->fail=false;
$lock=Cache::lock(OrderSyncService::LOCK_KEY,1800);$lock->get();$calls=$bags->calls;
try{$combined->sync();throw new LogicException('Overlap allowed');}catch(RuntimeException $e){check($bags->calls===$calls && str_contains($e->getMessage(),'already running'),'Concurrent sync cannot start an import');}finally{$lock->release();}
$controller=new App\Http\Controllers\OrderSyncController;
$req=requestFor($bag,'orders.sync','POST',['area'=>'bags']);$before=count($apparelSync->calls);$controller->store($req,$combined);
check(count($apparelSync->calls)===$before,'Bag-only user cannot sync apparel through common action');
$req=requestFor($apparel,'orders.sync','POST',['area'=>'apparel']);$before=$bags->calls;$controller->store($req,$combined);
check($bags->calls===$before,'Apparel-only user cannot sync bags through common action');
rejected(fn()=>$gate->handle(requestFor($demo,'orders.sync','POST'),fn()=>'allowed'),'View-only users cannot manually sync');
check($gate->handle(requestFor($apparel,'orders.sync-status'),fn()=>'allowed')==='allowed','Apparel users can read shared status without bag permission');
$event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($e)=>str_contains($e->command??'','shopify:sync-orders'));
check($event && $event->expression==='*/10 * * * *' && $event->withoutOverlapping,'Combined schedule runs every ten minutes with overlap protection');
$app->instance(OrderSyncService::class,$combined);$exit=Artisan::call('shopify:sync-orders',['--source'=>'scheduled']);check($exit===0 && end($apparelSync->calls)===[false,'scheduled'],'Combined CLI invokes both integrations');
$bags->fail=true;check(Artisan::call('shopify:sync-orders')===1,'CLI exits unsuccessfully on partial failure');$bags->fail=false;
$combined->sync('manual');Carbon::setTestNow(now()->addMinutes(21));check($combined->status()['schedule_health']==='overdue','Missing automatic runs display overdue health');
Cache::forever(OrderSyncService::STATUS_KEY,['state'=>'running','started_at'=>now()->subMinutes(31)->toIso8601String(),'areas'=>['bags'=>['state'=>'running']]]);
check($combined->status()['state']==='interrupted' && $combined->status()['areas']['bags']['state']==='interrupted','Abandoned run does not display as syncing forever');
$payload=apparelOrderPayload();$normalizer=$apparelSync;
foreach ([['1001','UNFULFILLED','2026-10-01T10:00:00Z',2],['1002','PARTIALLY_FULFILLED','2026-11-01T10:00:00Z',3],['1003','FULFILLED','2026-11-02T10:00:00Z',1]] as [$id,$state,$date,$qty]) {
    $record=$normalizer->normalizeLineItem(apparelOrderPayload(['id'=>'gid://shopify/Order/'.$id,'name'=>'#'.$id,'createdAt'=>$date,'displayFulfillmentStatus'=>$state]),$payload['lineItems']['nodes'][0]);
    $record['shopify_line_item_gid']='gid://shopify/LineItem/'.$id;$record['quantity']=$qty;
    $record['product_title']='Lucky Bags No Matter What, We Play Performance Jersey with a Long Product Name';
    if($id==='1002')$record['remark']='Please confirm the player name and sleeve style before production.';
    App\Models\JerseyOrder::create($record);
}
$overview=app(App\Http\Controllers\ApparelOverviewController::class)->index()->getData();
check($overview['summary']['open_units']===5 && $overview['summary']['pending_remarks']===1 && $overview['summary']['missing_tracking']===1,'Apparel overview counts open units, pending remarks and missing tracking');
check($overview['attentionOrders']->pluck('shopify_order_number')->all()===['#1001','#1002'],'Work queue lists oldest open orders and excludes fulfilled lines');
$report=app(App\Http\Controllers\JerseyOrderController::class);
foreach(['open'=>2,'pending_remarks'=>1,'missing_tracking'=>1] as $attention=>$count) {
    $response=$report->index(Illuminate\Http\Request::create('/apparel/orders?fulfillment_status=all&attention='.$attention));
    check($response->getData()['orders']->total()===$count,'Overview action opens the matching report filter: '.$attention);
}
$combined->sync('scheduled');Auth::setUser($manager);view()->share('errors',new Illuminate\Support\ViewErrorBag());
foreach(['bags','apparel'] as $area){
    $request=Illuminate\Http\Request::create('/dashboard?area='.$area);$app->instance('request',$request);
    $view=$area==='bags'?app(App\Http\Controllers\ProductionController::class)->dashboard():app(App\Http\Controllers\ApparelOverviewController::class)->index();
    $html=$view->render();
    check(substr_count($html,'data-order-sync-form>')===1 && strpos($html,'<section class="order-sync-card"')<strpos($html,'<section class="overview-workspace"'),'One standalone sync card appears before '.$area.' tabs');
    check(str_contains($html,'<b>16</b><span>Current lines') && str_contains($html,'<b>27</b><span>Lines checked'),'Both integration counts appear above '.$area.' panel');
    if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/order-sync-'.$area.'.html',$html);
}
Auth::setUser($apparel);$html=view('partials.order-sync-card')->render();check(!str_contains($html,'<strong>Bags</strong>') && str_contains($html,'<strong>Apparel</strong>'),'Area-specific status preserves visibility permissions');
Carbon::setTestNow();echo "Combined sync checks passed.\n";
