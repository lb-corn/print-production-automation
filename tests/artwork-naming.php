<?php
// Full naming workflow tests: isolated SQLite, fake integrations and disposable private audit files only.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
config(['services.google_drive.reviewed_selections_enabled'=>false,'database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','logging.default'=>'naming_test','logging.channels.naming_test'=>['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class],'services.google_drive.rename_enabled'=>true]);
Illuminate\Support\Facades\DB::purge('sqlite');
foreach(glob(__DIR__.'/../database/migrations/*.php') as $file)(require $file)->up();
use App\Services\{ArtworkNamingService,ArtworkMatcherService,ArtworkRenameStore,GoogleDriveService,GoogleDriveRenameService,ShopifyService,ProductionService};
use App\Models\{ProductionItem,PrintBatch,VerificationDecision,User};
use GuzzleHttp\{Client,HandlerStack};use GuzzleHttp\Handler\MockHandler;use GuzzleHttp\Psr7\Response;
$checks=0;$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS: '.$message.PHP_EOL;};
$drive=new class extends GoogleDriveService{public array $index=[];public int $reads=0;public function __construct(){}public function artworkIndex():array{$this->reads++;return $this->index;}};
$shopify=new class extends ShopifyService{public array $rows=[];public function __construct(){}public function getArtworkCatalog():array{return $this->rows;}};
$matcher=new ArtworkMatcherService($drive);
$folder=['kind'=>'series_folder','id'=>'pse','name'=>'PSE','path'=>'GROUP B (Carpet) / PSE'];
$source=fn($id,$name,$parent='pse')=>['kind'=>'artwork','id'=>$id,'name'=>$name,'parent_id'=>$parent,'path'=>($parent==='snp'?'(GROUP A) Non Carpet / Snipers / ':'GROUP B (Carpet) / PSE / ').$name,'mimeType'=>'image/jpeg','version'=>'1','modifiedTime'=>'t1','md5Checksum'=>'unchanged','canRename'=>false];
$row=['catalog_key'=>hash('sha256','red'),'sku'=>'2027-DUC-PSE-0001','product'=>'Lucky Bags 2027 Duck Camo','variant'=>'Pro Sniper Elite / Red'];
$shopify->rows=[$row];$drive->index=[$folder,$source('duck','Duck Camo Red.jpg')];
$directory=storage_path('framework/cache/data/naming-tests-'.bin2hex(random_bytes(8)));
$store=new ArtworkRenameStore($directory);$service=new ArtworkNamingService($drive,$shopify,$matcher,$store);
$admin=User::create(['name'=>'Admin','email'=>'naming@example.test','password'=>'NamingTestPassword123','role'=>'admin']);
$operator=User::create(['name'=>'Operator','email'=>'operator@example.test','password'=>'unused','role'=>'operator']);
$plan=$service->scan($admin->id);$entry=$plan['entries'][0];$target=$entry['proposal']['target'];
$check($entry['status']==='ready' && $target==='LB-PSE-DUC-RED-SINGLE.jpg' && str_ends_with($target,'.jpg'),'Nonstandard names get portable canonical names without ID suffixes');
$check($service->scan($admin->id)['entries'][0]['proposal']['target']===$target,'Repeated scans generate the same name for the same Drive file');
$check($matcher->match($row,[$folder,[...$drive->index[1],'name'=>$target]])['match_status']==='REVIEW','Canonical filename is accepted by the production matcher');
$check($matcher->match($row,[$folder,[...$drive->index[1],'id'=>'different-file','name'=>$target]])['match_status']==='REVIEW','Portable standard filenames match the same catalog across deployments');
$bare=$service->build([$folder,$source('bare','Duck Camo Red.jpg')],[$row],[],[]);
$check($bare[0]['status']==='ready','Exact bare full design and color can propose a reviewed rename using series folder context');
$unknown=$service->build([$folder,$source('unknown','some random image.jpg')],[$row],[],[]);
$check($unknown[0]['status']==='review','Arbitrary names are never guessed from folders');
$mapped=$service->build([$folder,$source('unknown','some random image.jpg')],[$row],['unknown'=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']],[]);
$check($mapped[0]['status']==='ready','Explicit admin mapping creates a new preview rather than an immediate rename');
$check($service->build([$folder,$source('mock','DUC-RED-MOCKUP.jpg')],[$row],['mock'=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']],[])[0]['status']==='review','Manual mappings cannot turn mockups into production artwork');
$check($service->build([$folder,$source('nested','Duck Camo Red.jpg','nested')],[$row],[],[])===[],'Files without a direct approved series parent are excluded');
$wrong=$service->build([$folder,$source('wrong','PSN-DUCK CAMO-RED.jpg')],[$row],[],[]);
$check($wrong[0]['status']==='review','An explicit wrong series is not silently replaced from folder context');
$duplicates=$service->build([$folder,$source('dup1','Duck Camo Red.jpg'),$source('dup2','PSE-DUC-RED.jpg')],[$row],[],[]);
$check($duplicates[0]['status']==='conflict' && $duplicates[1]['status']==='standard','Duplicate destination names require review rather than automatic ID suffixes');
$renamedDuplicates=[$folder];foreach($duplicates as $e)$renamedDuplicates[]=[...$e['source'],'name'=>$e['proposal']['target']];
$check($matcher->match($row,$renamedDuplicates)['match_status']==='AMBIGUOUS','Unique filenames never automatically resolve duplicate artwork candidates');
$double=[...$row,'catalog_key'=>hash('sha256','double'),'sku'=>'2027-CAN-BRL-SNP-0001','product'=>'Lucky Bags 2027 Brown Leather – ACL Cornhole Bags','variant'=>'Snipers'];
$doubleFolder=['kind'=>'series_folder','id'=>'snp','name'=>'Snipers','path'=>'(GROUP A) Non Carpet / Snipers'];
foreach(['front','back'] as $side){$e=$service->build([$doubleFolder,$source('leather-'.$side,'unknown-'.$side.'.jpeg','snp')],[$double],['leather-'.$side=>['catalog_key'=>$double['catalog_key'],'side'=>$side,'color'=>'']],[])[0];$check(str_contains($e['proposal']['target'],'-'.strtoupper($side).'.')&&str_ends_with($e['proposal']['target'],'.jpeg'),'Double-side '.$side.' and actual extension are preserved');}
$noSide=$service->build([$doubleFolder,$source('no-side','SNP-BROWN LEATHER.jpg','snp')],[$double],[],[]);
$check($noSide[0]['status']==='review','Double-sided filenames without a side require mapping');
// Apply through a fake writer. No real Drive calls exist in this test.
$writer=new class($drive) extends GoogleDriveRenameService{
 public int $writes=0;public bool $fail=false;public ?int $failAfter=null;public bool $permission=true;
 public function __construct(private $drive){}
 public function verify(array $expected):array{if(!$this->permission)throw new RuntimeException('The service account needs Editor access to this approved Drive folder before files can be renamed.');return $expected;}
 public function rename(array $expected,string $target):array{if($this->fail || ($this->failAfter!==null && $this->writes>=$this->failAfter))throw new RuntimeException('Uncertain remote result');$this->writes++;foreach($this->drive->index as &$file)if(($file['id']??null)===$expected['id']){$file['name']=$target;$file['path']='GROUP B (Carpet) / PSE / '.$target;$file['version']='2';$file['modifiedTime']='t2';}unset($file);return ['id'=>$expected['id'],'name'=>$target,'version'=>'2','md5Checksum'=>$expected['md5Checksum']];}
};
$app->instance(GoogleDriveRenameService::class,$writer);$app->instance(GoogleDriveService::class,$drive);$app->instance(ShopifyService::class,$shopify);$app->instance(ArtworkMatcherService::class,$matcher);$app->instance(ArtworkRenameStore::class,$store);$app->instance(ArtworkNamingService::class,$service);
$item=ProductionItem::create(['line_item_id'=>'unbatched',...$row,...$matcher->match($row,[$folder,[...$drive->index[1],'name'=>'PSE-DUC-RED.jpg']])]);
app(ProductionService::class)->verify($item,'CORRECT',$admin->id);
$beforeBatches=PrintBatch::all()->toJson();$service->begin($plan['token'],$admin->id,['duck'],false);$done=$service->step($plan['token'],$admin->id);
$check($done['status']==='complete' && $writer->writes===1 && $done['renamed']===1,'Confirmed plans apply metadata renames in bounded chunks');
$check(!$item->fresh('decision')->decision && !$item->fresh()->matches,'Affected unbatched selections and approvals are invalidated before writing');
$check(PrintBatch::all()->toJson()===$beforeBatches,'Applying names never creates a batch or requests a print handoff');
$check($done['events'][0]['old_name']==='Duck Camo Red.jpg' && $done['events'][0]['status']==='renamed','Private audit records old/new names, stable file ID and outcome');
$check($service->scan($admin->id)['entries'][0]['status']==='standard','Running the tool tomorrow leaves existing standard files unchanged');
try{$service->begin($plan['token'],$admin->id,['duck'],false);throw new RuntimeException('Replay accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Consumed naming plans cannot be replayed');}
try{$store->load($plan['token'],$operator->id);throw new RuntimeException('Other actor admitted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Plans are bound to the reviewing admin');}
// A file batched after scan must stop the operation without changing orders or Drive.
$drive->index=[$folder,$source('locked','Duck Camo Red.jpg')];$lockedPlan=$service->scan($admin->id);
$batch=PrintBatch::create(['batch_id'=>'protected','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>'unbatched','artworks'=>[['drive_file_id'=>'locked']]]]]]);
$lockedBefore=$batch->fresh()->toJson();
try{$service->begin($lockedPlan['token'],$admin->id,['locked'],false);throw new RuntimeException('Batched rename accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $batch->fresh()->toJson()===$lockedBefore && $writer->writes===1,'Batch membership changes invalidate preview without mutating a batch');}
foreach(['QUEUED','CLAIMED','PRINTED','FAILED'] as $status){$batch->update(['status'=>$status]);$check($service->scan($admin->id)['entries'][0]['status']==='locked','Files from '.$status.' batches remain protected');}
$drive->index=[$folder,$source('stale','Duck Camo Red.jpg')];$stale=$service->scan($admin->id);$drive->index[1]['version']='9';
try{$service->begin($stale['token'],$admin->id,['stale'],false);throw new RuntimeException('Stale preview accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Changed Drive versions invalidate preview');}
$stale=$service->scan($admin->id);$shopify->rows[0]['product']='Changed catalog';
try{$service->begin($stale['token'],$admin->id,['stale'],false);throw new RuntimeException('Changed catalog accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Catalog changes invalidate preview');}
$shopify->rows=[$row];$drive->index=[$folder,$source('permission','Duck Camo Red.jpg')];$permission=$service->scan($admin->id);$writer->permission=false;$service->begin($permission['token'],$admin->id,['permission'],false);$stopped=$service->step($permission['token'],$admin->id);
$check($stopped['status']==='stopped' && str_contains($stopped['warning'],'Editor access') && $writer->writes===1,'Missing Editor access stops before any rename');$writer->permission=true;
$drive->index=[$folder,$source('failure','Duck Camo Red.jpg')];$failure=$service->scan($admin->id);$service->begin($failure['token'],$admin->id,['failure'],false);$writer->fail=true;$failed=$service->step($failure['token'],$admin->id);
$check($failed['status']==='stopped' && $failed['events'][0]['status']==='attempting','Uncertain remote outcomes remain in the audit and never retry automatically');$writer->fail=false;
// Real writer contract with an in-memory HTTP handler: only title metadata is ever sent.
$expected=$source('http','PSE-DUC-RED.jpg');
$raw=['id'=>'http','title'=>$expected['name'],'parents'=>[['id'=>'pse']],'mimeType'=>'image/jpeg','modifiedDate'=>'t1','md5Checksum'=>'unchanged','version'=>'1','etag'=>'"v1"','capabilities'=>['canRename'=>true]];
$httpHistory=[];$stack=HandlerStack::create(new MockHandler([new Response(200,[],json_encode($raw)),new Response(200,[],json_encode([...$raw,'title'=>'new-name.jpg','version'=>'2']))]));$stack->push(GuzzleHttp\Middleware::history($httpHistory));
(new GoogleDriveRenameService(new Client(['handler'=>$stack])))->rename($expected,'new-name.jpg');
$check(count($httpHistory)===2 && $httpHistory[1]['request']->getMethod()==='PATCH' && json_decode((string)$httpHistory[1]['request']->getBody(),true)===['title'=>'new-name.jpg'] && $httpHistory[1]['request']->getHeaderLine('If-Match')==='"v1"','Writer sends a conditional title-only PATCH with no content, parent or permission mutations');
foreach([['version'=>'2'],['etag'=>null],['capabilities'=>['canRename'=>false]],['parents'=>[['id'=>'elsewhere']]]] as $change){$history=[];$stack=HandlerStack::create(new MockHandler([new Response(200,[],json_encode([...$raw,...$change]))]));$stack->push(GuzzleHttp\Middleware::history($history));try{(new GoogleDriveRenameService(new Client(['handler'=>$stack])))->rename($expected,'new-name.jpg');throw new Exception('Unsafe write accepted');}catch(RuntimeException $e){$check(count($history)===1,'Stale metadata, missing validator, missing permission or changed parent prevents PATCH');}}
try{(new GoogleDriveRenameService(new Client(['handler'=>HandlerStack::create(new MockHandler([]))])))->rename($expected,'new-name.png');throw new Exception('Extension changed');}catch(RuntimeException $e){$check(true,'Rename never changes the actual file extension');}
// Admin-only routing and direct controller guards.
foreach(app('router')->getRoutes() as $route)if(str_starts_with($route->getName()??'','admin.artwork-names'))$check(in_array(App\Http\Middleware\RequireAdmin::class,$route->gatherMiddleware()) && in_array(App\Http\Middleware\PortalWriteLock::class,$route->gatherMiddleware()),'Naming route requires admin and serialized writes: '.$route->getName());
$controller=new App\Http\Controllers\ArtworkNamingController();$request=Illuminate\Http\Request::create('/admin/artwork-names');$request->setUserResolver(fn()=>$operator);
try{$controller->index($request,$store);throw new LogicException('Operator admitted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Operators cannot invoke the admin naming controller');}
Illuminate\Support\Facades\Auth::login($admin);view()->share('errors',new Illuminate\Support\ViewErrorBag);
$request=Illuminate\Http\Request::create('/admin/artwork-names','GET',['plan'=>$stale['token']]);$request->setUserResolver(fn()=>$admin);
$html=$controller->index($request,$store)->render();$check(str_contains($html,'Check files') && str_contains($html,'Rename ready files') && str_contains($html,'Confirm rename'),'Admin page renders scan, review, explicit confirmation and filename preview');
$request=Illuminate\Http\Request::create('/admin/artwork-names/begin','POST',['plan'=>$stale['token'],'ids'=>['stale']]);$request->setUserResolver(fn()=>$admin);
try{$controller->begin($request);throw new LogicException('Unconfirmed apply admitted');}catch(Illuminate\Validation\ValidationException $e){$check(isset($e->errors()['password'],$e->errors()['confirmation'],$e->errors()['reviewed']),'Apply requires admin password, typed RENAME and explicit review');}
// Test full catalog pagination without Shopify credentials or order history.
$catalogService=new class extends ShopifyService{
 public array $seen=[];public string $mode='normal';public function __construct(){}
 public function graphql(string $query,array $variables=[]):array {
  $this->seen[]=[$query,$variables];$end=['hasNextPage'=>false,'endCursor'=>null];
  if(str_contains($query,'ArtworkCollections')) {
   $nodes=$this->mode==='missing'?[]:[['id'=>'bags','title'=>'All Bags'],['id'=>'other','title'=>'Other Bags']];
   if($this->mode==='ambiguous')$nodes[]=['id'=>'duplicate','title'=>'All Bags'];
   return ['collections'=>['nodes'=>$nodes,'pageInfo'=>$end]];
  }
  if(str_contains($query,'ArtworkProductVariants'))return ['product'=>['id'=>'p1','status'=>'ACTIVE','variants'=>['nodes'=>[['id'=>'v2','sku'=>'2027-DUC-PSE-0001','title'=>'Pro Sniper Elite / Red']],'pageInfo'=>$end]]];
  $variant=['nodes'=>[['id'=>'v1','sku'=>'2027-EAR-CLV-0001','title'=>'Clover']],'pageInfo'=>['hasNextPage'=>true,'endCursor'=>'variants-2']];
  $product=['id'=>'p1','title'=>'Lucky Bags 2027 Earth','productType'=>'ACL Pro Stamped 2027','status'=>'ACTIVE','variants'=>$variant];
  if(($variables['after']??null)===null) {
   $products=[$product,[...$product,'id'=>'draft','status'=>'DRAFT'],[...$product,'id'=>'archived','status'=>'ARCHIVED']];
   $info=['hasNextPage'=>true,'endCursor'=>'products-2'];
  } else {$products=[];$info=$end;}
  if($this->mode==='loop')$info=['hasNextPage'=>true,'endCursor'=>'products-2'];
  return ['collection'=>['id'=>'bags','title'=>'All Bags','products'=>['nodes'=>$products,'pageInfo'=>$info]]];
 }
};
$catalogRows=$catalogService->getArtworkCatalog();
$check(count($catalogRows)===2 && count($catalogService->seen)===4,'All Bags catalog paginates collection products and product variants without orders');
$check(array_unique(array_column($catalogRows,'product_id'))===['p1'],'Draft and archived products are excluded; no other collection is queried');
foreach(['missing','ambiguous','loop'] as $mode) {
 $catalogService->mode=$mode;
 try{$catalogService->getArtworkCatalog();throw new LogicException('Unsafe catalog scope accepted');}catch(RuntimeException $e){$check(true,'Missing/ambiguous collection or repeated cursor fails closed: '.$mode);}
}
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks admin artwork naming checks passed.\n";
// Scope is visible before a scan and exact folders are highlighted afterwards.
$request=Illuminate\Http\Request::create('/admin/artwork-names');$request->setUserResolver(fn()=>$admin);
$initial=$controller->index($request,$store)->render();
$check(str_contains($initial,'Scan scope · these folders only')&&str_contains($initial,'GROUP B (Carpet)')&&str_contains($initial,'MOCKUPS')&&str_contains($initial,'PSE'),'Initial admin page clearly highlights approved roots, version folders and exclusions');
$rogueFolder=[...$folder,'path'=>'Outside manufacturing / PSE'];
$check($service->build([$rogueFolder,$source('rogue','PSE-DUC-RED.jpg')],[$row],[],[])===[],'Planner rejects folders outside the approved branches');
$nestedFolder=[...$folder,'path'=>'GROUP B (Carpet) / MOCKUPS / PSE'];
$check($service->build([$nestedFolder,$source('nested-parent','PSE-DUC-RED.jpg')],[$row],[],[])===[],'Planner rejects even recognizable versions nested under mockups');
// HTTP precondition failures are never retried.
$preconditionHistory=[];$stack=HandlerStack::create(new MockHandler([new Response(200,[],json_encode($raw)),new Response(412,[],'{}')]));$stack->push(GuzzleHttp\Middleware::history($preconditionHistory));
try{(new GoogleDriveRenameService(new Client(['handler'=>$stack])))->rename($expected,'new-name.jpg');throw new Exception('Precondition ignored');}catch(RuntimeException $e){$check(count($preconditionHistory)===2,'A changed ETag stops at HTTP 412 without retry');}
// No user can select a path that was not in their server-side preview.
$drive->index=[$folder,$source('only','Duck Camo Red.jpg')];$untrusted=$service->scan($admin->id);
try{$service->begin($untrusted['token'],$admin->id,['injected-id'],false);throw new Exception('Injected file accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Tampered file selections are rejected before remote work');}
$expired=$store->create($admin->id,['expires_at'=>time()-1]);
try{$store->load($expired['token'],$admin->id);throw new Exception('Expired plan accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Expired previews cannot authorize renames');}
// Preserved file extensions also round-trip through all normal matching paths.
foreach(['png','jpeg','TIFF','psd'] as $extension){$f=$source('extension-'.$extension,'PSE-DUC-RED.'.$extension);$e=$service->build([$folder,$f],[$row],[],[])[0];$check(str_ends_with($e['proposal']['target'],'.'.$extension),'Original extension is preserved exactly: '.$extension);}
// Missing-file suggestions use the same readable convention, without inventing a Drive ID.
$suggestion=$matcher->match($row,[$folder])['artwork_review']['suggested_filenames'][0];
$check($suggestion==='LB-PSE-DUC-RED-SINGLE.jpg' && $matcher->match($row,[$folder,$source('uploaded',$suggestion)])['match_status']==='REVIEW','Missing suggestions and new filenames use the same portable convention');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin artwork naming checks passed.\n";
// A bulk confirmation runs in bounded chunks and a later failure never conceals partial success.
$drive->index=[$folder];$bulkRows=[];
for($i=0;$i<6;$i++){$bulkRows[]=[...$row,'catalog_key'=>hash('sha256','bulk-'.$i),'sku'=>'2027-BULK'.$i.'-PSE-0001','product'=>'Lucky Bags Duck Camo '.$i];$drive->index[]=$source('bulk-'.$i,'Duck Camo '.$i.' Red.jpg');}$shopify->rows=$bulkRows;
$bulk=$service->scan($admin->id);$service->begin($bulk['token'],$admin->id,array_column($bulk['entries'],'id'),false);$beforeWrites=$writer->writes;
$chunk=$service->step($bulk['token'],$admin->id);$check($writer->writes===$beforeWrites+5 && count($chunk['pending'])===1 && $chunk['status']==='applying','Large operations process at most five files per request');
$chunk=$service->step($bulk['token'],$admin->id);$check($chunk['status']==='complete' && $chunk['renamed']===6,'The next confirmed chunk completes only the remaining files');
$shopify->rows=[$bulkRows[0],$bulkRows[1]];$drive->index=[$folder,$source('partial-1','Duck Camo 0 Red.jpg'),$source('partial-2','Duck Camo 1 Red.jpg')];
$partial=$service->scan($admin->id);$service->begin($partial['token'],$admin->id,['partial-1','partial-2'],false);$writer->failAfter=$writer->writes+1;
$partialResult=$service->step($partial['token'],$admin->id);
$check($partialResult['status']==='stopped' && $partialResult['renamed']===1 && $partialResult['events'][0]['status']==='renamed' && $partialResult['events'][1]['status']==='attempting','A partial remote failure records the completed rename and uncertain attempt separately');
$writer->failAfter=null;$reconciled=$service->scan($admin->id);$states=array_count_values(array_column($reconciled['entries'],'status'));
$check(($states['standard']??0)===1 && ($states['ready']??0)===1,'A new scan reconciles actual Drive names after a partial failure without blindly retrying');
$shopify->rows=[$row];$drive->index=[$folder,$source('refresh','Duck Camo Red.jpg')];
$refreshItem=ProductionItem::create(['line_item_id'=>'refresh-after-rename',...$row,...$matcher->match($row,[$folder,[...$drive->index[1],'name'=>'PSE-DUC-RED.jpg']])]);
app(ProductionService::class)->verify($refreshItem,'CORRECT',$admin->id);
$refreshPlan=$service->scan($admin->id);$service->begin($refreshPlan['token'],$admin->id,['refresh'],true);$batchesBefore=PrintBatch::all()->toJson();
$refreshed=$service->step($refreshPlan['token'],$admin->id);
$check($refreshed['status']==='complete' && $refreshed['match_counts']['matched']===1 && str_starts_with($refreshItem->fresh()->matches[0]['name'],'LB-'),'Optional refresh populates the actual renamed filename on unbatched production items');
$check(!$refreshItem->fresh('decision')->decision && PrintBatch::all()->toJson()===$batchesBefore,'Automatic metadata refresh neither restores approval nor changes any print batch');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin artwork naming checks passed.\n";
// One extensible settings page, with read-only GET navigation between isolated admin tools.
$settings=new App\Http\Controllers\AdminSettingsController();
$request=Illuminate\Http\Request::create('/admin/settings','GET',['section'=>'reset']);$request->setUserResolver(fn()=>$admin);
$settingsHtml=$settings->index($request)->render();
$check(substr_count($settingsHtml,'<main>')===1 && str_contains($settingsHtml,'Admin Settings') && str_contains($settingsHtml,'DANGER ZONE') && str_contains($settingsHtml,'RESET PORTAL') && !str_contains($settingsHtml,'id="artwork-naming-apply"'),'Reset is a separate section inside one settings page, retaining its destructive-action confirmations');
$request=Illuminate\Http\Request::create('/admin/settings','GET',['section'=>'artwork']);$request->setUserResolver(fn()=>$admin);
$settingsHtml=$settings->index($request)->render();
$check(str_contains($settingsHtml,'admin-settings-nav') && str_contains($settingsHtml,'Check files') && !str_contains($settingsHtml,'Type RESET PORTAL'),'Artwork is the default settings section and does not render the reset form');
$menu=view('partials.account-menu')->render();
$check(str_contains($menu,'Admin Settings') && !str_contains($menu,'Artwork filenames') && !str_contains($menu,'Reset portal data'),'Account menu groups the two tools under a single Admin Settings link');
$request=Illuminate\Http\Request::create('/admin/settings','GET',['section'=>'../../credentials']);$request->setUserResolver(fn()=>$admin);
try{$settings->index($request);throw new Exception('Unknown section accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===404,'Settings sections use an allowlist instead of arbitrary view paths');}
$request=Illuminate\Http\Request::create('/admin/settings');$request->setUserResolver(fn()=>$operator);
try{$settings->index($request);throw new Exception('Operator admitted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'The shared settings page remains admin-only');}
echo "$checks total admin artwork naming/settings checks passed.\n";
// One explicit design confirmation safely resolves siblings and future files without per-image code.
$bubbleRow=[...$row,'catalog_key'=>hash('sha256','bubble-pse'),'sku'=>'2027-BUB-PSE-0001','product'=>'Lucky Bags 2027 Bubble Fusion','variant'=>'Pro Sniper Elite'];
$bubbleDouble=[...$bubbleRow,'catalog_key'=>hash('sha256','bubble-snp'),'sku'=>'2027-BUB-SNP-0001','variant'=>'Snipers'];
$shopify->rows=[$bubbleRow,$bubbleDouble];$drive->index=[$folder,$doubleFolder,$source('bubble-single','PSE-BUBBLES.jpg'),$source('bubble-front','SNP-BUBBLES-FRONT.jpg','snp'),$source('bubble-back','SNP-BUBBLES-BACK.jpg','snp')];
$groupPlan=$service->scan($admin->id);$check(count(array_filter($groupPlan['entries'],fn($e)=>$e['status']==='review'))===3,'Unconfirmed design synonyms remain review cases');
$mapping=['bubble-single'=>['catalog_key'=>$bubbleRow['catalog_key'],'side'=>'single','color'=>'']];
$perFile=$service->revise($groupPlan['token'],$admin->id,$mapping,false);$check($store->rules()===[],'A file-only confirmation does not silently create a reusable rule');
$grouped=$service->revise($groupPlan['token'],$admin->id,$mapping,true);
$check(count(array_filter($grouped['entries'],fn($e)=>$e['status']==='standard'))===3,'One reusable design confirmation resolves matching SINGLE, FRONT and BACK files across versions');
$future=$service->scan($admin->id);$check(count(array_filter($future['entries'],fn($e)=>$e['status']==='standard'))===3,'Fresh scans reuse the saved design confirmation without remapping each file');
$whiteOnly=[...$bubbleRow,'catalog_key'=>hash('sha256','white-only'),'variant'=>'Pro Sniper Elite / White'];
$wrongColor=$service->build([$folder,$source('wrong-rule-color','PSE-BUBBLES-RED.jpg')],[$whiteOnly],[],[],$store->rules());
$check($wrongColor[0]['status']==='review','Remembered designs never invent a missing color or choose the wrong catalog variant');
$unknownSide=$service->build([$doubleFolder,$source('unknown-rule-side','SNP-BUBBLES.jpg','snp')],[$bubbleDouble],[],[],$store->rules());
$check($unknownSide[0]['status']==='review','Remembered designs never infer a missing FRONT/BACK side');
$conflictingProduct=[...$bubbleRow,'catalog_key'=>hash('sha256','different-bubbles'),'sku'=>'2027-XYZ-PSE-0001','product'=>'Lucky Bags 2027 Bubbles'];
$ruleConflict=$service->build([$folder,$source('rule-conflict','PSE-BUBBLES.jpg')],[$conflictingProduct],[],[],$store->rules());
$check($ruleConflict[0]['status']==='review','A remembered label conflicting with current exact catalog evidence requires review');
// Years are not in generated names, and catalog year fields do not expand human work.
$yearAlias=[...$row,'catalog_key'=>hash('sha256','old-sku-alias'),'sku'=>'2026-DUC-PSN-ELT-0001'];
$yearIgnored=$service->build([$folder,$source('old-year','2027-PSN-ELT-DUCK CAMO-RED.jpg')],[$yearAlias],[],[]);
$check($yearIgnored[0]['status']==='standard' && $yearIgnored[0]['proposal']['target']===$yearIgnored[0]['source']['name'],'Legacy SKU aliases and year differences do not put a year in the new filename');
$equivalent=$service->build([$folder,$source('equivalent','PSE-DUCK CAMO-RED.jpg')],[$row,$yearAlias],[],[]);
$check($equivalent[0]['status']==='standard' && count($equivalent[0]['proposal']['catalog_keys'])===2,'Equivalent catalog rows with different years or series aliases produce one unambiguous destination');
$clashing=[...$row,'catalog_key'=>hash('sha256','different-design'),'product'=>'Lucky Bags 2027 Different Design'];
$notEquivalent=$service->build([$folder,$source('not-equivalent','PSE-DUC-RED.jpg')],[$row,$clashing],[],[]);
$check($notEquivalent[0]['status']==='review','Different product designs sharing a SKU design code are never merged');
$shopify->rows=[$row];$drive->index=[$folder,$source('rule-stale','PSE-DUC-RED.jpg')];$ruleStale=$service->scan($admin->id);$store->remember('ANOTHER-DESIGN','ANOTHER-PRODUCT',$admin->id);
try{$service->begin($ruleStale['token'],$admin->id,['rule-stale'],false);throw new Exception('Changed rules accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Changed reusable mappings invalidate an older rename preview');}
$oldPolicy=$store->create($admin->id,['status'=>'preview','entries'=>[],'naming_policy'=>'old-year-based-policy']);
try{$service->begin($oldPolicy['token'],$admin->id,['anything'],false);throw new Exception('Old policy accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && str_contains($e->getMessage(),'Check files'),'Old year-based previews must be rebuilt before applying names');}
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Reviewed labels are accepted production names, not mandatory spelling corrections.
$reviewedRow=[...$row,'product'=>'Lucky Bags 2027 Reverse Camo','sku'=>'2027-REV-PSE-0001'];
$reviewedFile=$source('reviewed-legacy','PSN-ELT-REVERSE CLOVER CAMO-RED.jpg');
$reviewedEntry=$service->build([$folder,$reviewedFile],[$reviewedRow],['reviewed-legacy'=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']],[])[0];
$check($reviewedEntry['status']==='standard' && $reviewedEntry['proposal']['target']===$reviewedFile['name'],'A reviewed legacy design label is preserved instead of shortened to the SKU code');
$sharedRows=[$reviewedRow,[...$reviewedRow,'catalog_key'=>'shared-other','product'=>'Lucky Bags 2027 Another Design']];
$oldSharedId='existing-shared';$oldSharedName='LB-PSE-REV-RED-SINGLE-ID-'.strtoupper(substr(hash('sha256',$oldSharedId),0,12)).'.jpg';
$oldShared=$source($oldSharedId,$oldSharedName);
$oldSharedEntry=$service->build([$folder,$oldShared],$sharedRows,[$oldSharedId=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']],[])[0];
$check($oldSharedEntry['status']==='standard' && $oldSharedEntry['proposal']['target']===$oldSharedName,'A shared SKU code does not force an existing ID-suffixed reviewed file to be renamed');
$check($service->build([$folder,$oldShared],$sharedRows,[],[])[0]['status']==='review','Without an explicit choice shared design codes still require review rather than an arbitrary selection');
$reviewedSide=[...$row,'sku'=>'2027-SLO-PSN-0001','product'=>'Lucky Bags 2027 Beach Animals','variant'=>'Pro Snipers / Black'];
$sideFolder=['kind'=>'series_folder','id'=>'psn','name'=>'Pro Snipers','path'=>'(GROUP A) Non Carpet / Pro Snipers'];
$sideFile=[...$source('reviewed-side','PSN-SLOTH-BLACK-BACK.jpg'),'parent_id'=>'psn','path'=>'(GROUP A) Non Carpet / Pro Snipers / PSN-SLOTH-BLACK-BACK.jpg'];
$sideEntry=$service->build([$sideFolder,$sideFile],[$reviewedSide],['reviewed-side'=>['catalog_key'=>$row['catalog_key'],'side'=>'back','color'=>'BLACK']],[])[0];
$check($sideEntry['status']==='standard' && $sideEntry['proposal']['target']===$sideFile['name'],'Reviewed FRONT/BACK legacy labels stay unchanged');
$missingSide=[...$sideFile,'name'=>'PSN-SLOTH-BLACK.jpg','path'=>'(GROUP A) Non Carpet / Pro Snipers / PSN-SLOTH-BLACK.jpg'];
$check($service->build([$sideFolder,$missingSide],[$reviewedSide],['reviewed-side'=>['catalog_key'=>$row['catalog_key'],'side'=>'back','color'=>'BLACK']],[])[0]['status']==='ready','A genuinely missing required side still needs a filename correction');
// Explicit future catalog variant labels need no hardcoded color list.
$midnight=[...$row,'catalog_key'=>hash('sha256','midnight'),'variant'=>'Pro Sniper Elite / Midnight'];
$midnightFile=$source('midnight','PSE-DUCK CAMO-MIDNIGHT.jpg');
$midnightPlan=$service->build([$folder,$midnightFile],[$midnight],[],[]);
$check($midnightPlan[0]['status']==='standard' && $midnightPlan[0]['proposal']['target']===$midnightFile['name'],'Exact new catalog color labels are automated without per-color code changes');
$check($matcher->match($midnight,[$folder,[...$midnightFile,'name'=>$midnightPlan[0]['proposal']['target']]])['match_status']==='REVIEW','The same explicit variant label is recognized throughout production matching');
$wrongMidnight=$service->build([$folder,$source('wrong-midnight','PSE-DUCK CAMO-RED.jpg')],[$midnight],[],[]);
$check($wrongMidnight[0]['status']==='review','A new color label never permits a wrong-color file');
// The simplified page avoids the browser's username/password autofill heuristic on its filter field.
$shopify->rows=[$row];$drive->index=[$folder,$source('simple-ui','Duck Camo Red.jpg')];$simple=$service->scan($admin->id);
$request=Illuminate\Http\Request::create('/admin/settings','GET',['plan'=>$simple['token'],'section'=>'artwork']);$request->setUserResolver(fn()=>$admin);
$simpleHtml=$controller->index($request,$store)->render();$dom=new DOMDocument();@$dom->loadHTML($simpleHtml,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//input[@data-naming-search]/ancestor::form')->length===0,'Filename search is outside the password form so it cannot become the username field');
$check($xpath->query('//dialog[@data-naming-dialog]//input[@type="password" and @disabled]')->length===1 && !str_contains($simpleHtml,'Type RENAME'),'Confirmation is one short dialog, without an always-visible password block or typing a phrase');
$check($xpath->query('//input[@name="ids[]" and @checked]')->length===1,'Safe ready files are preselected automatically');
$check(!str_contains($simpleHtml,'Check and standardize Drive names') && !str_contains($simpleHtml,'Destructive production reset'),'Settings navigation has no subtitles or explanatory filler');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Page-load counts use the latest private snapshot, without reviving a rename plan.
$summaryPlan=$store->create($admin->id,['naming_policy'=>ArtworkNamingService::POLICY,'created_at'=>100,'expires_at'=>101,'catalog'=>[$row,$yearAlias],
    'entries'=>[['status'=>'standard'],['status'=>'ready'],['status'=>'review'],['status'=>'conflict'],['status'=>'locked'],['status'=>'renamed']]]);
$store->create($operator->id,['naming_policy'=>ArtworkNamingService::POLICY,'created_at'=>200,'catalog'=>[],'entries'=>[]]);
$store->create($admin->id,['naming_policy'=>'obsolete','created_at'=>300,'catalog'=>[],'entries'=>[]]);
$summary=$store->latestSummary($admin->id);
$check($summary['images']===0 && $summary['products']===1 && $summary['variants']===2 && $summary['catalog_missing']===2 && $summary['catalog_matched']===0 && $summary['review']===0,'Shopify coverage counts missing catalog variants and excludes unrelated Drive entries');
$check($summary['checked_at']===100 && $store->latestSummary(99999)===null,'Status snapshots remain actor-scoped and exclude obsolete naming rules');
$request=Illuminate\Http\Request::create('/admin/settings','GET');$request->setUserResolver(fn()=>$admin);
$page=$controller->index($request,$store);$check($page->getData()['plan']['token']!==$summaryPlan['token'] && $page->getData()['plan']['expires_at']>time() && $page->getData()['summary']['checked_at']>100,'Default settings loads a fresh preview without restoring an expired actionable plan');
$check(str_contains($page->render(),'Artwork status') && str_contains($page->render(),'Last checked'),'A compact status row and snapshot time render before a new scan');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Shopify is the monitored inventory, including products with no Drive files or orders.
$gta=[...$row,'catalog_key'=>hash('sha256','gta-luc'),'sku'=>'2027-GTA-LUC-0001','product'=>'Lucky Bags 2027 GTA','variant'=>'Luciano'];
$luc=['kind'=>'series_folder','id'=>'luc','name'=>'Luciano','path'=>'GROUP B (Carpet) / Luciano'];
$gtaFile=[...$source('gta','LUC-GTA-BLACK.jpg'),'parent_id'=>'luc','path'=>'GROUP B (Carpet) / Luciano / LUC-GTA-BLACK.jpg'];
$extra=[...$gtaFile,'id'=>'unrelated','name'=>'UNRELATED-DESIGN.jpg','path'=>'GROUP B (Carpet) / Luciano / UNRELATED-DESIGN.jpg'];
$shopify->rows=[$gta,$row];$drive->index=[$luc,$folder,$gtaFile,$extra];$catalogPlan=$service->scan($admin->id);
$coverage=ArtworkRenameStore::summary($catalogPlan);
$check($coverage['images']===1 && $coverage['catalog_matched']===1 && $coverage['catalog_missing']===1,'Only Shopify-linked images count; catalog products without artwork remain monitored');
$request=Illuminate\Http\Request::create('/admin/settings','GET',['plan'=>$catalogPlan['token']]);$request->setUserResolver(fn()=>$admin);
$catalogPage=$controller->index($request,$store);$data=$catalogPage->getData();
$check(count($data['catalogStatus'])===2 && count($data['visibleEntries'])===1 && $data['catalogStatus'][0]['status']==='matched' && $data['catalogStatus'][1]['status']==='missing','Coverage begins with all Shopify variants, while unrelated Drive files stay outside the monitored table');
$html=$catalogPage->render();$check(str_contains($html,'data-match-product=') && str_contains($html,'data-artwork-candidates') && !str_contains($html,'data-naming-picker'),'The worklist opens a visual artwork chooser instead of two disconnected dropdowns');
$check($matcher->namingCandidate($gta,$gtaFile,'Luciano',['side'=>'single','color'=>'BLACK'])!==null,'A Shopify series-only variant can map verified BLACK-labeled GTA artwork');
$drive->index=[$luc,$folder,$gtaFile,[...$gtaFile,'id'=>'gta-duplicate']];$duplicates=$service->scan($admin->id);
$check(ArtworkRenameStore::summary($duplicates)['catalog_ambiguous']===1,'Multiple Drive candidates remain ambiguous in Shopify coverage');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Correctly named artwork stays available when visually reviewing a Shopify product.
$shopify->rows=[$row];$drive->index=[$folder,$source('standard-chooser','PSE-DUC-RED.jpg')];$candidate=$service->build($drive->index,[$row],[],[])[0];
$standardSource=$source('standard-chooser',$candidate['proposal']['target']);$drive->index=[$folder,$standardSource];$chooserPlan=$service->scan($admin->id);
$request=Illuminate\Http\Request::create('/admin/settings','GET',['plan'=>$chooserPlan['token']]);$request->setUserResolver(fn()=>$admin);
$chooser=$controller->index($request,$store);$check(isset($chooser->getData()['reviewGroups']['standard-chooser']),'The visual chooser includes correctly named files, not only review/conflict files');
$chooserHtml=$chooser->render();$dom=new DOMDocument();@$dom->loadHTML($chooserHtml,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//dialog[@data-artwork-match-dialog]//select[@data-naming-catalog and @hidden]')->length===1 && $xpath->query('//button[@data-match-product]')->length===1,'A single product worklist opens one focused visual comparison without visible mapping dropdowns');
$check($xpath->query('//details[summary[contains(text(),"Optional filename changes")]]//form[@id="artwork-naming-apply"]')->length===1,'Advanced filename controls are collapsed instead of competing with product matching');
$blueDouble=[...$bubbleDouble,'catalog_key'=>hash('sha256','blue-double'),'variant'=>'Snipers / Blue'];$shopify->rows=[$blueDouble];$drive->index=[$doubleFolder,$source('side-guard','SNP-BUBBLES-BLUE-FRONT.jpg','snp')];$guard=$service->scan($admin->id);
try{$service->revise($guard['token'],$admin->id,['side-guard'=>['catalog_key'=>$blueDouble['catalog_key'],'side'=>'back','color'=>'BLUE']],false);throw new LogicException('Wrong side mapping accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Server rejects tampered mapping that contradicts explicit FRONT/BACK');}
$redDouble=[...$blueDouble,'catalog_key'=>hash('sha256','red-double'),'variant'=>'Snipers / Red'];$shopify->rows=[$redDouble];$guard=$service->scan($admin->id);
try{$service->revise($guard['token'],$admin->id,['side-guard'=>['catalog_key'=>$redDouble['catalog_key'],'side'=>'front','color'=>'RED']],false);throw new LogicException('Wrong color mapping accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Server rejects tampered mapping that contradicts the explicit filename color');}
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Explain exclusions without weakening color checks or protected batch rules.
$shopify->rows=[$gta,[...$gta,'catalog_key'=>hash('sha256','collision'),'product'=>'Lucky Bags 2027 Different Design']];$drive->index=[$luc,$gtaFile];$explanation=$service->scan($admin->id);$explanation['entries'][0]['status']='locked';$store->save($explanation);
$request=Illuminate\Http\Request::create('/admin/settings','GET',['plan'=>$explanation['token']]);$request->setUserResolver(fn()=>$admin);$page=$controller->index($request,$store);$data=$page->getData();
$check($data['catalogStatus'][0]['sku_conflict'] && isset($data['reviewGroups']['gta']) && $data['reviewGroups']['gta']['entry']['status']==='locked','Duplicate Shopify SKU conflicts and protected files are available for explanation, without authorization to rename');
$html=$page->render();$check(str_contains($html,'data-artwork-excluded') && str_contains($html,'data-protected="1"') && str_contains($html,'form="artwork-scan-form"'),'Cannot-find help exposes excluded file reasons and an explicit Drive refresh action');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Double-sided confirmation stores both files in one preview, with no live mutation.
$shopify->rows=[$bubbleDouble];$drive->index=[$doubleFolder,$source('pair-front','SNP-BUBBLES-FRONT.jpg','snp'),$source('pair-back','SNP-BUBBLES-BACK.jpg','snp')];$pairPlan=$service->scan($admin->id);$writesBefore=$writer->writes;$batchCount=PrintBatch::count();
$pair=$service->revisePair($pairPlan['token'],$admin->id,$bubbleDouble['catalog_key'],'pair-front','pair-back',false);
$check(count($pair['mappings'])===2 && count(array_filter($pair['entries'],fn($e)=>$e['status']==='standard'))===2,'One confirmation prepares FRONT and BACK together');
$check($pair['mappings']['pair-front']['side']==='front' && $pair['mappings']['pair-back']['side']==='back' && $writer->writes===$writesBefore && PrintBatch::count()===$batchCount,'Pair preview retains separate sides without renaming, batching or print handoff');
foreach([['pair-front','pair-front'],['pair-back','pair-front'],['pair-front','missing-back']] as [$front,$back]) {
 try{$service->revisePair($pairPlan['token'],$admin->id,$bubbleDouble['catalog_key'],$front,$back,false);throw new LogicException('Invalid pair accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Incomplete, duplicate or swapped FRONT/BACK pair is rejected');}
}
$protectedPair=$store->load($pairPlan['token'],$admin->id);$protectedPair['entries'][1]['status']='locked';$store->save($protectedPair);
try{$service->revisePair($pairPlan['token'],$admin->id,$bubbleDouble['catalog_key'],'pair-front','pair-back',false);throw new LogicException('Protected pair accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'A batch-protected side cannot join a new mapping pair');}
$drive->index=[$doubleFolder,$source('mixed-front','SNP-BUBBLES-BLUE-FRONT.jpg','snp'),$source('mixed-back','SNP-BUBBLES-RED-BACK.jpg','snp')];$mixed=$service->scan($admin->id);
try{$service->revisePair($mixed['token'],$admin->id,$bubbleDouble['catalog_key'],'mixed-front','mixed-back',false);throw new LogicException('Mixed colors accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $store->load($mixed['token'],$admin->id)['mappings']===[],'Conflicting pair colors are rejected before saving either mapping');}
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

$shopify->rows=[$bubbleDouble];$drive->index=[$doubleFolder,$source('endpoint-front','SNP-BUBBLES-FRONT.jpg','snp'),$source('endpoint-back','SNP-BUBBLES-BACK.jpg','snp')];$endpointPlan=$service->scan($admin->id);
$request=Illuminate\Http\Request::create('/admin/artwork-names/review','POST',['plan'=>$endpointPlan['token'],'catalog_key'=>$bubbleDouble['catalog_key'],'pair_mode'=>'1','front_file_id'=>'endpoint-front','back_file_id'=>'endpoint-back']);$request->setUserResolver(fn()=>$admin);$response=$controller->revise($request);
$check($response->getStatusCode()===302 && count($store->load($endpointPlan['token'],$admin->id)['mappings'])===2,'Double-sided form endpoint accepts the two slots without a legacy single-file field');
$cancelledOnly=PrintBatch::create(['batch_id'=>'cancelled-lock-test','status'=>'CANCELLED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>'cancelled-lock-item','artworks'=>[['drive_file_id'=>'cancelled-only-file']]]]]]);
$check(!isset($service->lockedFiles()['cancelled-only-file']),'Cancelled queue snapshots no longer lock artwork while other batch statuses stay protected');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// A color-label correction is explicit, file-specific and audited, never an alias.
$shopify->rows=[$row];$wrongLabel=$source('reviewed-label','PSE-DUC-NAVY.JPEG');$drive->index=[$folder,$wrongLabel];$correction=$service->scan($admin->id);$rulesBefore=$store->rules();$writeCount=$writer->writes;
$mapping=['reviewed-label'=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']];
try{$service->revise($correction['token'],$admin->id,$mapping,true);throw new LogicException('Unconfirmed correction accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $store->load($correction['token'],$admin->id)['mappings']===[],'Wrong-color files remain blocked without explicit color-label confirmation');}
$mapping['reviewed-label']['color_correction_confirmed']=true;$corrected=$service->revise($correction['token'],$admin->id,$mapping,true);
$check($corrected['entries'][0]['status']==='ready' && str_contains($corrected['entries'][0]['proposal']['target'],'-RED-SINGLE.') && str_ends_with($corrected['entries'][0]['proposal']['target'],'.JPEG'),'Reviewed color-label correction proposes the exact Shopify color and preserves the actual extension');
$review=$corrected['color_correction_reviews'][0];$check($review['from']==='NAVY' && $review['to']==='RED' && $review['actor']===$admin->id && $review['original_name']===$wrongLabel['name'],'Color correction records original filename, old/new labels, actor and timestamp');
$check($store->rules()===$rulesBefore && $writer->writes===$writeCount && $matcher->match($row,[$folder,$wrongLabel])['match_status']==='MISSING','A reviewed exception creates no color alias, performs no rename and does not weaken automatic wrong-color matching');
$request=Illuminate\Http\Request::create('/admin/artwork-names/review','POST',['plan'=>$correction['token'],'catalog_key'=>$row['catalog_key'],'file_id'=>'reviewed-label','side'=>'single','color'=>'RED','color_correction_confirmed'=>'0']);$request->setUserResolver(fn()=>$admin);
try{$controller->revise($request);throw new LogicException('Unchecked correction admitted');}catch(Illuminate\Validation\ValidationException $e){$check(isset($e->errors()['color_correction_confirmed']),'The endpoint requires an accepted explicit correction checkbox');}
$render=$controller->index(Illuminate\Http\Request::create('/admin/settings','GET',['plan'=>$corrected['token']])->setUserResolver(fn()=>$admin),$store)->render();
$dom=new DOMDocument();@$dom->loadHTML($render,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//dialog[@data-artwork-match-dialog]/form/div[@class="artwork-match-top"]')->length===1 && $xpath->query('//dialog[@data-artwork-match-dialog]/form/div[@class="artwork-match-scroll"]')->length===1 && $xpath->query('//dialog[@data-artwork-match-dialog]/form/div[@class="artwork-match-bottom"]//button[@data-match-confirm]')->length===1,'The modal separates a fixed comparison header, one scrolling list and visible confirmation controls');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// The same explicit label review works for a pair without changing global color rules.
$shopify->rows=[$blueDouble];$drive->index=[$doubleFolder,$source('color-pair-front','SNP-BUBBLES-BLUE-FRONT.jpg','snp'),$source('color-pair-back','SNP-BUBBLES-NAVY-BACK.jpg','snp')];$colorPair=$service->scan($admin->id);$rulesBefore=$store->rules();
$reviewedPair=$service->revisePair($colorPair['token'],$admin->id,$blueDouble['catalog_key'],'color-pair-front','color-pair-back',false,true);
$check(count($reviewedPair['mappings'])===2 && count($reviewedPair['color_correction_reviews'])===1 && $reviewedPair['color_correction_reviews'][0]['file_id']==='color-pair-back' && $reviewedPair['color_correction_reviews'][0]['to']==='BLUE','Explicit pair correction audits only the mislabeled side and keeps both target colors equal to Shopify');
$check($store->rules()===$rulesBefore,'A pair color correction does not create a global color equivalence');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Expected operator corrections return to the page rather than invoking the debug renderer.
$shopify->rows=[$row];$drive->index=[$folder,$source('save-error','PSE-DUC-NAVY.jpg')];$savePlan=$service->scan($admin->id);
$request=Illuminate\Http\Request::create('/admin/artwork-names/review','POST',['plan'=>$savePlan['token'],'catalog_key'=>$row['catalog_key'],'file_id'=>'save-error','side'=>'single','color'=>'RED']);$request->setUserResolver(fn()=>$admin);$response=$controller->revise($request);
$check($response->getStatusCode()===302 && str_contains(session('error'),'Explicitly confirm') && $store->load($savePlan['token'],$admin->id)['mappings']===[],'An unconfirmed correction redirects with a clear error and saves no mapping instead of rendering an exception page');
$compiler=new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(),storage_path('framework/views'));
$markdown=$compiler->compileString(file_get_contents(base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/exceptions/renderer/markdown.blade.php')));token_get_all($markdown,TOKEN_PARSE);
$check(!str_contains($markdown,'$__empty_-'),'Fresh framework exception-template compilation produces valid PHP without negative loop counters');
$ambiguous=$matcher->match($row,[$folder,$source('ui-duplicate-a','PSE-DUC-RED.jpg'),$source('ui-duplicate-b','PSE-DUC-RED.jpeg')]);
$check($ambiguous['match_status']==='AMBIGUOUS' && $ambiguous['artwork_review']['suggested_filenames']===[],'Ambiguous matches produce no misleading filename suggestion');
auth()->setUser($admin);$ambiguous['artwork_review']['suggested_filenames']=['legacy-misleading.jpg'];$viewItem=(object)[...$row,...$ambiguous,'production_batch'=>null];$html=view('partials.artwork-review',['item'=>$viewItem])->render();$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//button[@data-copy-filename]')->length===0 && str_contains($html,'PSE-DUC-RED.jpeg'),'Ambiguous-artwork UI hides even stale copy suggestions and shows conflicting filenames');
$missing=$matcher->match($row,[$folder]);$viewItem=(object)[...$row,...$missing,'production_batch'=>null];$html=view('partials.artwork-review',['item'=>$viewItem])->render();$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET);$check((new DOMXPath($dom))->query('//button[@data-copy-filename]')->length===0 && !str_contains($html,'LB-PSE-DUC-RED-SINGLE.jpg') && str_contains($html,'Choose artwork for this product'),'Missing artwork directs Admin to product matching without filename suggestions or copy controls');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Explicit Admin choice resolves duplicate files and survives later scans, without print approval.
config(['services.google_drive.reviewed_selections_enabled'=>true]);$matcher->refreshReviewedSelections();$shopify->rows=[$row];
$first=$source('review-choice-a','PSE-DUC-RED.jpg');$second=$source('review-choice-b','PSE-DUC-RED.jpeg');$drive->index=[$folder,$first];
$choiceItem=ProductionItem::create(['line_item_id'=>'review-choice-order',...$row,...$matcher->match($row,$drive->index)]);app(ProductionService::class)->verify($choiceItem,'CORRECT',$admin->id);
$drive->index=[$folder,$first,$second];$choicePlan=$service->scan($admin->id);$choicePlan=$service->revise($choicePlan['token'],$admin->id,['review-choice-b'=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']],false);
$service->saveReviewedChoice($choicePlan,$admin->id,$row['catalog_key'],['review-choice-b']);
$check(!$choiceItem->fresh('decision')->decision && $choiceItem->fresh()->matches===[],'Changing the reviewed choice invalidates stale selection/approval before any refresh can fail');
$chosen=$matcher->match($row,$drive->index);$check($chosen['match_status']==='REVIEW' && $chosen['matches'][0]['id']==='review-choice-b' && $chosen['artwork_review']['selection_source']==='admin','An explicit Admin choice resolves duplicates for that exact product/variant/side');
app(App\Services\ArtworkRematchService::class)->rematch();$check($choiceItem->fresh()->matches[0]['id']==='review-choice-b' && !$choiceItem->fresh('decision')->decision,'Order rematch uses the chosen artwork but never approves it');
$otherDesign=[...$row,'product'=>'Lucky Bags 2027 Different Design'];$check($matcher->match($otherDesign,$drive->index)['match_status']==='AMBIGUOUS','A different product sharing the same SKU cannot inherit another design’s choice');
$otherColor=[...$row,'variant'=>'Pro Sniper Elite / Blue'];$check($matcher->match($otherColor,$drive->index)['match_status']==='MISSING','A reviewed choice never crosses Shopify color variants');
$freshPlan=$service->scan($admin->id);$mapped=collect($freshPlan['entries'])->firstWhere('id','review-choice-b');$check($mapped['proposal']['catalog_key']===$row['catalog_key'] && ArtworkRenameStore::summary($freshPlan)['catalog_matched']===1,'Fresh Admin scans preserve the reviewed choice and show artwork found');
$changed=[...$second,'version'=>'changed'];$check($matcher->match($row,[$folder,$first,$changed])['match_status']==='MISSING','Changed chosen file invalidates the pin instead of silently selecting another duplicate');
$check($matcher->match($row,[$folder,$first])['match_status']==='MISSING','A missing chosen file does not fall back to an arbitrary duplicate');
$renamed=[...$second,'name'=>$mapped['proposal']['target'],'version'=>'2','modifiedTime'=>'t2'];$store->refreshRenamedSelection($second,$renamed);$matcher->refreshReviewedSelections();$check($matcher->match($row,[$folder,$first,$renamed])['matches'][0]['id']==='review-choice-b','A verified app rename preserves the reviewed identity while updating its metadata fingerprint');
config(['services.google_drive.reviewed_selections_enabled'=>false]);$matcher->refreshReviewedSelections();foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Paired manual choices persist by exact variant and require fresh metadata on both sides.
config(['services.google_drive.reviewed_selections_enabled'=>true]);$matcher->refreshReviewedSelections();$shopify->rows=[$bubbleDouble];
$pairFront=$source('pin-front','SNP-BUBBLES-FRONT.jpg','snp');$pairBack=$source('pin-back','SNP-BUBBLES-BACK.jpg','snp');$drive->index=[$doubleFolder,$pairFront,$pairBack];$pinPair=$service->scan($admin->id);
$pinPair=$service->revisePair($pinPair['token'],$admin->id,$bubbleDouble['catalog_key'],'pin-front','pin-back',false);$service->saveReviewedChoice($pinPair,$admin->id,$bubbleDouble['catalog_key'],['pin-front','pin-back']);
$pinMatch=$matcher->match($bubbleDouble,$drive->index);$check($pinMatch['match_status']==='REVIEW' && array_column($pinMatch['matches'],'id')===['pin-front','pin-back'],'Reviewed FRONT/BACK choices persist even when legacy labels need standardization');
$changedBack=[...$pairBack,'modifiedTime'=>'changed'];$check($matcher->match($bubbleDouble,[$doubleFolder,$pairFront,$changedBack])['matches']===[],'A changed side invalidates the whole reviewed pair, never a partial printable selection');
$beforeLoadBatches=PrintBatch::all()->toJson();$beforeLoadWrites=$writer->writes;$request=Illuminate\Http\Request::create('/admin/settings','GET');$request->setUserResolver(fn()=>$admin);$loaded=$controller->index($request,$store);
$check($loaded->getData()['plan']!==null && count($loaded->getData()['catalogStatus'])>0 && PrintBatch::all()->toJson()===$beforeLoadBatches && $writer->writes===$beforeLoadWrites,'Default Settings loads the catalog automatically without creating a batch or renaming a file');
$check($store->latestPreview($operator->id)===null,'Default preview reuse remains bound to the signed-in admin');
config(['services.google_drive.reviewed_selections_enabled'=>false]);$matcher->refreshReviewedSelections();foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// Reviewed choices remain private-file data even before the optional review column is deployed.
config(['services.google_drive.reviewed_selections_enabled'=>true]);$matcher->refreshReviewedSelections();$shopify->rows=[$row];$drive->index=[$folder,$source('pre-schema-choice','PSE-DUC-RED.jpg')];$preSchema=$service->scan($admin->id);
$unreviewed=ProductionItem::create(['line_item_id'=>'pre-schema-choice-order',...$row,...$matcher->match($row,$drive->index)]);$unreviewed->update(['matches'=>[['id'=>'stale-before-schema','side'=>'single']]]);
Illuminate\Support\Facades\Schema::table('production_items',fn($table)=>$table->dropColumn('artwork_review'));
$request=Illuminate\Http\Request::create('/admin/artwork-names/review','POST',['plan'=>$preSchema['token'],'catalog_key'=>$row['catalog_key'],'file_id'=>'pre-schema-choice','side'=>'single','color'=>'RED']);$request->setUserResolver(fn()=>$admin);$response=$controller->revise($request);
$check($response->getStatusCode()===302 && $store->selections()!==[] && $unreviewed->fresh()->matches===[] && str_contains(session('warning'),'artwork_review migration'),'Admin can save reviewed JSON choices and invalidate stale selections before the review-column migration, with a clear printing setup notice');
(require __DIR__.'/../database/migrations/2026_11_01_000005_add_artwork_review.php')->up();config(['services.google_drive.reviewed_selections_enabled'=>false]);$matcher->refreshReviewedSelections();foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

// The same Drive filenames are already standard in every app environment.
$shopify->rows=[$row];$names=['PSE-DUC-RED.jpg','PSN-ELT-DUCK CAMO-RED.jpeg','2027-PSN-ELT-DUCK CAMO-RED.JPG','LB-PSE-DUC-RED-SINGLE.png'];
foreach($names as $i=>$name){$f=$source('portable-'.$i,$name);$entry=$service->build([$folder,$f],[$row],[],[])[0];$check($entry['status']==='standard' && $entry['proposal']['target']===$name,'Already accepted name is unchanged: '.$name);}
$id='old-standard';$oldName='LB-PSE-DUC-RED-SINGLE-ID-'.strtoupper(substr(hash('sha256',$id),0,12)).'.jpg';$old=$source($id,$oldName);$check($service->build([$folder,$old],[$row],[],[])[0]['status']==='standard','Previously generated valid ID-suffixed names are not rewritten');
$invalid=$source('once-only','Duck Camo Red.jpg');$first=$service->build([$folder,$invalid],[$row],[],[])[0];$normalized=[...$invalid,'name'=>$first['proposal']['target'],'path'=>'GROUP B (Carpet) / PSE / '.$first['proposal']['target']];$otherEnvironment=new ArtworkNamingService($drive,$shopify,new ArtworkMatcherService($drive),$store);
$check($first['status']==='ready' && !str_contains($first['proposal']['target'],'-ID-') && $otherEnvironment->build([$folder,$normalized],[$row],[],[])[0]['status']==='standard','One correction is enough: an independent environment reads the standardized name without another rename');
$sharedA=[...$row,'product'=>'Lucky Bags Broken Glass','sku'=>'2027-BAC-PSE-0001'];$sharedB=[...$sharedA,'catalog_key'=>hash('sha256','other-shared'),'product'=>'Lucky Bags Big Ass Clover'];$sharedFile=$source('shared-code','unknown.jpg');
$shared=$service->build([$folder,$sharedFile],[$sharedA,$sharedB],['shared-code'=>['catalog_key'=>$sharedA['catalog_key'],'side'=>'single','color'=>'RED']],[])[0];
$check($shared['proposal']['target']==='LB-PSE-BROKEN-GLASS-RED-SINGLE.jpg','Reviewed shared-code correction uses a portable full design name instead of an ID');
$portable=[...$sharedFile,'name'=>$shared['proposal']['target'],'path'=>'GROUP B (Carpet) / PSE / '.$shared['proposal']['target']];$check($matcher->match($sharedA,[$folder,$portable])['match_status']==='REVIEW' && $matcher->match($sharedB,[$folder,$portable])['match_status']==='MISSING' && $service->build([$folder,$portable],[$sharedA,$sharedB],[],[])[0]['status']==='standard','The full-name correction matches only its intended product and is not renamed on the next scan');
$collision=$service->build([$folder,$source('collision-a','Duck Camo Red.jpg'),$source('collision-b','Duck Camo Red.jpg')],[$row],[],[]);$check(array_column($collision,'status')===['conflict','conflict'],'Real destination collisions require operator review rather than automatic suffixes');
foreach(glob($directory.'/*.json') as $file)unlink($file);if(is_dir($directory))rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

$shopify->rows=[$row];$drive->index=[$folder,$source('cross-environment','Duck Camo Red.jpg')];$beforeOtherEnvironment=$service->scan($admin->id);$drive->index[1]['name']=$beforeOtherEnvironment['entries'][0]['proposal']['target'];$drive->index[1]['path']='GROUP B (Carpet) / PSE / '.$drive->index[1]['name'];$drive->index[1]['version']='2';$writesBefore=$writer->writes;
try{$service->begin($beforeOtherEnvironment['token'],$admin->id,['cross-environment'],false);throw new LogicException('Stale rename accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $writer->writes===$writesBefore,'If another environment already renamed the file, a stale preview cannot rename it again');}
$afterOtherEnvironment=$service->scan($admin->id);$check($afterOtherEnvironment['entries'][0]['status']==='standard','Refresh after another environment’s rename imports the existing accepted filename');
try{$service->begin($afterOtherEnvironment['token'],$admin->id,['cross-environment'],false);throw new LogicException('Standard file submitted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $writer->writes===$writesBefore,'Server rejects attempts to submit already-standard files for renaming');}
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";

config(['services.google_drive.reviewed_selections_enabled'=>true]);
$store->remember('REVERSE-CLOVER-CAMO',$matcher->designIdentity($reviewedRow['product']),$admin->id);
$matcher->refreshReviewedSelections();$shopify->rows=[$reviewedRow];$drive->index=[$folder,$reviewedFile];
$rememberedPlan=$service->scan($admin->id);$writesBefore=$writer->writes;
$check($rememberedPlan['entries'][0]['status']==='standard' && $matcher->match($reviewedRow,$drive->index)['match_status']==='REVIEW','Fresh scans and production matching reuse the reviewed label without a rename');
$request=Illuminate\Http\Request::create('/admin/artwork-names/review','POST',['plan'=>$rememberedPlan['token'],'catalog_key'=>$reviewedRow['catalog_key'],'file_id'=>$reviewedFile['id'],'side'=>'single','color'=>'RED']);$request->setUserResolver(fn()=>$admin);
$response=$controller->revise($request);$reloaded=$service->scan($admin->id);
$check($response->getStatusCode()===302 && $reloaded['entries'][0]['status']==='standard' && $matcher->match($reviewedRow,$drive->index)['artwork_review']['selection_source']==='admin' && $writer->writes===$writesBefore,'Confirm artwork, reload Admin and rematch production all preserve the same file without invoking Drive rename');
$wrongReviewed=[...$reviewedRow,'variant'=>'Pro Sniper Elite / Blue'];
$check($matcher->match($wrongReviewed,$drive->index)['match_status']==='MISSING','Remembered labels never override the Shopify color');
config(['services.google_drive.reviewed_selections_enabled'=>false]);$matcher->refreshReviewedSelections();
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";
// A reviewed legacy pair may have descriptive wording but no recognized color token.
$midnightRow=[...$row,'catalog_key'=>hash('sha256','midnight-pair'),'sku'=>'2027-SOG-SRF-0001','product'=>'Lucky Bags 2027 Surefire OG','variant'=>'Surefire / Black'];
$midnightFolder=['kind'=>'series_folder','id'=>'srf','name'=>'Surefire','path'=>'(GROUP A) Non Carpet / Surefire'];
$midnightPair=[];
foreach(['front','back'] as $side) {
    $name='SRF-0G 2027-MIDNIGHT-'.strtoupper($side).'.jpg';
    $midnightPair[]=[...$source('midnight-'.$side,$name),'parent_id'=>'srf','path'=>$midnightFolder['path'].' / '.$name];
}
$shopify->rows=[$midnightRow];$drive->index=[$midnightFolder,...$midnightPair];
config(['services.google_drive.reviewed_selections_enabled'=>true]);$matcher->refreshReviewedSelections();
$midnightPlan=$service->scan($admin->id);
$check(count(array_filter($midnightPlan['entries'],fn($e)=>$e['status']==='review'))===2 && $matcher->match($midnightRow,$drive->index)['match_status']==='MISSING','MIDNIGHT is not automatically guessed to mean BLACK');
$midnightPlan=$service->revisePair($midnightPlan['token'],$admin->id,$midnightRow['catalog_key'],'midnight-front','midnight-back',false);
$check(array_column($midnightPlan['entries'],'status')===['standard','standard'] && array_column(array_column($midnightPlan['entries'],'proposal'),'target')===array_column($midnightPair,'name'),'Reviewed MIDNIGHT FRONT/BACK pair retains actual names instead of requiring BLACK renames');
config(['services.google_drive.rename_enabled'=>false]);
$writesBefore=$writer->writes;$service->saveReviewedChoice($midnightPlan,$admin->id,$midnightRow['catalog_key'],['midnight-front','midnight-back']);
$midnightReload=$service->scan($admin->id);$midnightMatch=$matcher->match($midnightRow,$drive->index);
$check(array_column($midnightReload['entries'],'status')===['standard','standard'] && $midnightMatch['match_status']==='REVIEW' && count($midnightMatch['matches'])===2 && $midnightMatch['artwork_review']['selection_source']==='admin' && $writer->writes===$writesBefore,'Saved pair survives scan and production rematch with zero rename writes and no print approval');
config(['services.google_drive.rename_enabled'=>true]);
try{$service->begin($midnightReload['token'],$admin->id,['midnight-front','midnight-back'],false);throw new LogicException('Reviewed standard pair submitted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $writer->writes===$writesBefore,'Already accepted pair cannot be submitted for another rename');}
$changedPair=$drive->index;$changedPair[1]['version']='2';
$check($matcher->match($midnightRow,$changedPair)['match_status']==='MISSING','Changing the reviewed artwork still invalidates the saved pair');
$redPair=$midnightPair;$redPair[0]['name']='SRF-SUREFIRE OG-RED-FRONT.jpg';$redPair[0]['path']=$midnightFolder['path'].' / '.$redPair[0]['name'];
$redProposal=$service->build([$midnightFolder,$redPair[0]],[$midnightRow],['midnight-front'=>['catalog_key'=>$midnightRow['catalog_key'],'side'=>'front','color'=>'BLACK']],[])[0];
$check($redProposal['status']==='ready' && $redProposal['proposal']['target']!==$redPair[0]['name'],'An explicit wrong color token is not silently accepted by the absent-color exception');
config(['services.google_drive.reviewed_selections_enabled'=>false]);$matcher->refreshReviewedSelections();
// A fresh Admin check must also populate orders after files were renamed elsewhere.
config(['services.google_drive.rename_enabled'=>false]);
$shopify->rows=[$midnightRow];$drive->index=[$midnightFolder];
foreach(['front','back'] as $side)$drive->index[]=[...$source('fresh-black-'.$side,'LB-SRF-SOG-BLACK-'.strtoupper($side).'.jpg'),'parent_id'=>'srf','path'=>$midnightFolder['path'].' / LB-SRF-SOG-BLACK-'.strtoupper($side).'.jpg'];
$refreshItem=ProductionItem::create(['line_item_id'=>'fresh-black-order',...array_intersect_key($midnightRow,array_flip(['sku','product','variant'])),'quantity'=>1,'match_status'=>'MISSING','matches'=>[],'is_custom'=>false]);
$request=Illuminate\Http\Request::create('/admin/artwork-names/scan','POST',['artwork_search'=>$midnightRow['product']]);$request->setUserResolver(fn()=>$admin);
$readsBefore=$drive->reads;$writesBefore=$writer->writes;$response=$controller->scan($request);
$refreshItem=$refreshItem->fresh('decision');
$check($refreshItem->match_status==='REVIEW' && count($refreshItem->matches)===2 && !$refreshItem->decision,'Check files populates an empty Black order from current canonical Drive names, without print approval');
$check($drive->reads===$readsBefore+1 && $writer->writes===$writesBefore && str_contains(session('ok'),'orders refreshed'),'Admin refresh reuses one fresh Drive scan and succeeds with renaming disabled');
parse_str(parse_url($response->getTargetUrl(),PHP_URL_QUERY),$query);
$check(($query['artwork_search']??null)===$midnightRow['product'],'Refresh keeps the blocked product search in the destination');
$request=Illuminate\Http\Request::create('/admin/settings','GET',$query);$request->setUserResolver(fn()=>$admin);
$html=$controller->index($request,$store)->render();
$check(str_contains($html,'value="'.$midnightRow['product'].'"') && str_contains($html,'Optional filename changes') && !str_contains($html,'data-naming-open') && !str_contains($html,'name="ids[]"'),'Read-only filename preview has no selected files or dead rename action, and retains product search');
config(['services.google_drive.rename_enabled'=>true]);
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin naming/settings checks passed.\n";
