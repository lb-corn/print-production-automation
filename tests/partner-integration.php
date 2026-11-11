<?php
// In-memory DB/cache and fake remote services. No live orders, files or credentials changed.
require __DIR__.'/role-permissions.php';
require __DIR__.'/fixtures/apparel-order.php';
use App\Services\{PartnerContext as Partner,MaterialCatalog,ProductionService,PrintLabelService,ArtworkMatcherService,ShopifyService};
use App\Models\{ProductionItem,JerseyOrder,PrintBatch,Role};
use Illuminate\Support\Facades\{Cache,Http,Auth};
Http::preventStrayRequests();Cache::flush();
config(['services.partners.vibe.enabled'=>true,'services.backing_colors.enabled'=>false,'services.shopify.store'=>'lucky-test.myshopify.com','services.shopify.client_id'=>'lucky-client','services.shopify.client_secret'=>'lucky-secret',
    'services.partners.vibe.shopify.store'=>'vibe-test.myshopify.com','services.partners.vibe.shopify.client_id'=>'vibe-client','services.partners.vibe.shopify.client_secret'=>'vibe-secret',
    'services.partners.vibe.drive_root_id'=>'vibe-root','services.materials.partner_tabs.vibe'=>'Vibe']);
$catalog=new class extends MaterialCatalog {
    public function rows(bool $fresh=false):array {return Partner::current()==='vibe'?['BEL'=>['name'=>'Believe','front'=>'Rave8','back'=>'Macarena','type'=>'single','template_size'=>'Small']]:['PSE'=>['name'=>'Pro Sniper Elite','front'=>'Rave9','back'=>'Viewmont','type'=>'single','template_size'=>'Normal']];}
};$app->instance(MaterialCatalog::class,$catalog);
$make=function($line,$partner){return Partner::run($partner,fn()=>ProductionItem::create(['line_item_id'=>$line,'order_number'=>'#42','product'=>'Test artwork','variant'=>'Charcoal','sku'=>$partner==='vibe'?'VB-BEL-CM-001':'2027-CM-PSE-0001','series_code'=>$partner==='vibe'?'BEL':'PSE','design_type'=>'single','quantity'=>1,'shopify_active'=>true,'match_status'=>'REVIEW','artwork_review'=>['policy'=>ArtworkMatcherService::POLICY],'matches'=>[['id'=>'drive-'.$partner,'side'=>'single','name'=>'approved.jpg']]]));};
$lucky=$make('gid://shopify/LineItem/shared','lucky');$vibe=$make('vibe:gid://shopify/LineItem/shared','vibe');
check($lucky->partner==='lucky' && $vibe->partner==='vibe','Existing and new bag orders have explicit shop ownership');
check(Partner::run('lucky',fn()=>ProductionItem::count())===1 && Partner::run('vibe',fn()=>ProductionItem::count())===1,'Selected partner scopes bag records');
check(Partner::run('vibe',fn()=>ProductionItem::find($lucky->id))===null,'Direct bag IDs cannot cross the selected partner');
check(Partner::run('vibe',fn()=>PrintLabelService::label($vibe,'Rave8'))==='RAVE8 VB42 MACARENA','Vibe print labels use VB and Vibe materials');
check(Partner::run('lucky',fn()=>PrintLabelService::label($lucky,'Rave9'))==='RAVE9 LB42 VIEWMONT','Lucky labels retain LB and Lucky materials');
$file='vibe-art';$filename='VB-BEL-CM-CHARCOAL-SINGLE-ID-'.strtoupper(substr(hash('sha256',$file),0,12)).'.jpg';
$match=Partner::run('vibe',fn()=>(new ArtworkMatcherService)->match(['sku'=>'VB-BEL-CM-001','product'=>'Believe Color Match','variant'=>'Charcoal'],[['kind'=>'series_folder','id'=>'bel-folder','name'=>'Believe','path'=>'(GROUP A) Non Carpet / Believe'],['kind'=>'artwork','id'=>$file,'name'=>$filename,'parent_id'=>'bel-folder','path'=>'(GROUP A) Non Carpet / Believe / '.$filename]]));
check($match['series_code']==='BEL' && $match['design_type']==='single' && count($match['matches'])===1,'Vibe SKU grammar and sheet-defined folders match Vibe artwork');
check(Partner::run('vibe',fn()=>(new ArtworkMatcherService)->isStandardFilename(['id'=>$file,'name'=>$filename])),'Admin naming recognizes VB standardized filenames');
$fakeShopify=new class extends ShopifyService {
    public function __construct(){}
    public function graphql(string $query,array $variables=[]):array{return ['orders'=>['nodes'=>[['id'=>'order-1','name'=>'#42','createdAt'=>'2026-11-01','cancelledAt'=>null,'displayFinancialStatus'=>'PAID','displayFulfillmentStatus'=>'UNFULFILLED','lineItems'=>['pageInfo'=>['hasNextPage'=>false],'nodes'=>[['id'=>'gid://shopify/LineItem/99','sku'=>'VB-BEL-CM-001','quantity'=>1,'unfulfilledQuantity'=>1,'variantTitle'=>'Charcoal','product'=>['id'=>'product-1','title'=>'Believe Color Match','productType'=>'Vibe Bags','featuredImage'=>['url'=>'https://cdn.shopify.com/test.png']],'variant'=>['id'=>'variant-1','sku'=>'VB-BEL-CM-001','title'=>'Charcoal','product'=>['id'=>'product-1']]]]]]],'pageInfo'=>['hasNextPage'=>false]]];}
};
$backlog=Partner::run('vibe',fn()=>$fakeShopify->getProductionBacklog());check(count($backlog['items'])===1 && $backlog['items'][0]['line_item_id']==='vibe:gid://shopify/LineItem/99','Vibe product type is imported with a namespaced production line ID');
$jerseys=new class extends App\Services\Shopify\JerseyOrderSyncService {public function __construct(){} };
$payload=apparelOrderPayload();$line=$payload['lineItems']['nodes'][0];
foreach(['lucky','vibe'] as $partner)Partner::run($partner,function()use($jerseys,$payload,$line){JerseyOrder::create($jerseys->normalizeLineItem($payload,$line));});
check(Partner::run('lucky',fn()=>JerseyOrder::count())===1 && Partner::run('vibe',fn()=>JerseyOrder::count())===1,'Identical apparel Shopify line IDs coexist without overwriting another shop');
Partner::run('vibe',fn()=>$jerseys->recordLastSuccessfulSync('scheduled'));
check(Cache::get(Partner::key(App\Services\Shopify\JerseyOrderSyncService::LAST_SUCCESSFUL_SYNC_CACHE_KEY))===null,'Vibe sync does not overwrite Lucky health/cursor keys');
Http::fake(['*/admin/oauth/access_token'=>function($request){return Http::response(['access_token'=>str_contains($request->url(),'vibe-test')?'vibe-token':'lucky-token','expires_in'=>3600]);}]);
$tokens=new App\Services\Shopify\ShopifyTokenService;
check(Partner::run('lucky',fn()=>$tokens->getAccessToken())==='lucky-token' && Partner::run('vibe',fn()=>$tokens->getAccessToken())==='vibe-token','Authentication uses separate stores and token caches');
$svc=app(ProductionService::class);
$luckyBatch=Partner::run('lucky',function()use($svc,$lucky,$manager){$svc->verify($lucky,'CORRECT',$manager->id);return $svc->batchByMaterial($manager->id,[$lucky->id])[0];});
$vibeBatch=Partner::run('vibe',function()use($svc,$vibe,$manager){$svc->verify($vibe,'CORRECT',$manager->id);return $svc->batchByMaterial($manager->id,[$vibe->id])[0];});
check($vibeBatch->partner==='vibe' && str_starts_with($vibeBatch->batch_id,'VB-BATCH-') && $vibeBatch->payload['partner']==='vibe','Vibe batches lock the partner and have distinct identifiers');
check(Partner::run('vibe',fn()=>PrintBatch::find($luckyBatch->id))===null,'A Vibe view cannot access a Lucky batch');
$role=Role::create(['slug'=>'vibe-only-test','name'=>'Vibe only','permissions'=>['partners.vibe.view','bags.overview.view','bags.artwork.view']]);$onlyVibe=userFor($role->slug);
check(Partner::allowed($onlyVibe)===['vibe'],'Role permissions can restrict an account to Vibe only');
$middleware=new App\Http\Middleware\PartnerScope;
$req=requestFor($onlyVibe,'production','GET',['partner'=>'lucky']);
rejected(fn()=>$middleware->handle($req,fn()=>'allowed'),'Vibe-only role cannot select Lucky through query parameters');
$req=requestFor($onlyVibe,'production','GET',['partner'=>'vibe']);
check($middleware->handle($req,fn()=>ProductionItem::whereKey($lucky->id)->exists())===false,'Partner middleware scopes queries before controller work');
$accountRole=Role::create(['slug'=>'account-only-test','name'=>'Account administration','permissions'=>['admin.users.view']]);$accountUser=userFor($accountRole->slug);
$req=requestFor($accountUser,'admin.users');
check($middleware->handle($req,fn()=>ProductionItem::count())===0,'Account administration works without exposing production records to a role without partner access');
rejected(fn()=>$middleware->handle(requestFor($accountUser,'production'),fn()=>'allowed'),'Production requires an assigned partner');
check(!Partner::get()->scoped && !Partner::get()->denied,'Request partner scope is restored after middleware');
$shopCalls=[];
$syncFactory=function()use(&$shopCalls){
    $bags=new class($shopCalls) extends App\Services\BacklogSyncService {
        private array $calls;private string $shop;
        public function __construct(array &$calls){$this->calls=&$calls;$this->shop=Partner::current();}
        public function sync():array{$this->calls[]=['client'=>$this->shop,'scope'=>Partner::current()];return [];}
    };
    $apparel=new class extends App\Services\Shopify\JerseyOrderSyncService {public function __construct(){}public function sync(bool $full=false,?string $since=null,?int $limit=null,string $source='command'):array{return [];}};
    return new App\Services\OrderSyncService($bags,$apparel);
};
$app->bind(App\Services\OrderSyncService::class,$syncFactory);
Partner::run('vibe',fn()=>$syncFactory()->syncPartners('manual',['bags'],['lucky','vibe']));
check($shopCalls===[['client'=>'lucky','scope'=>'lucky'],['client'=>'vibe','scope'=>'vibe']],'Sync initiated from Vibe uses the correct Shopify client for both shops');
Partner::get()->scoped=false;Partner::get()->partner='lucky';Auth::setUser($manager);
if(in_array('--preview',$argv,true))foreach(['lucky','vibe'] as $previewPartner)Partner::run($previewPartner,function()use($app,$previewPartner){
    $app->instance('request',Illuminate\Http\Request::create('/dashboard?partner='.$previewPartner));
    file_put_contents(__DIR__.'/../tmp/partner-'.$previewPartner.'.html',app(App\Http\Controllers\ProductionController::class)->dashboard()->render());
});
$factoryDrive=new class extends App\Services\GoogleDriveService {
    public array $children=[['id'=>'lucky-child','name'=>'LuckyBags','mimeType'=>'application/vnd.google-apps.folder'],['id'=>'vibe-child','name'=>'Vibe','mimeType'=>'application/vnd.google-apps.folder']];
    public function __construct(){}
    public function listChildren(string $folderId):array{if($folderId!=='factory-test')throw new LogicException('Unexpected parent');return $this->children;}
};
$app->instance(App\Services\GoogleDriveService::class,$factoryDrive);
config(['services.google_drive.factory_root_id'=>'factory-test']);
check(Partner::run('lucky',fn()=>Partner::root())==='lucky-child' && Partner::run('vibe',fn()=>Partner::root())==='vibe-child','One factory root resolves separate Lucky and Vibe folders');
Cache::flush();$factoryDrive->children=[$factoryDrive->children[0]];
try{Partner::run('vibe',fn()=>Partner::root());throw new LogicException('Missing partner folder accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'missing or ambiguous'),'Missing Vibe folder never falls back to Lucky');}
Cache::flush();$factoryDrive->children[]=$factoryDrive->children[0];
try{Partner::run('lucky',fn()=>Partner::root());throw new LogicException('Duplicate partner folder accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'missing or ambiguous'),'Duplicate partner folders require correction');}
echo "Partner integration checks passed.\n";
