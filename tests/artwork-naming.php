<?php
// Full naming workflow tests: isolated SQLite, fake integrations and disposable private audit files only.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','logging.default'=>'naming_test','logging.channels.naming_test'=>['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class],'services.google_drive.rename_enabled'=>true]);
Illuminate\Support\Facades\DB::purge('sqlite');
foreach(glob(__DIR__.'/../database/migrations/*.php') as $file)(require $file)->up();
use App\Services\{ArtworkNamingService,ArtworkMatcherService,ArtworkRenameStore,GoogleDriveService,GoogleDriveRenameService,ShopifyService,ProductionService};
use App\Models\{ProductionItem,PrintBatch,VerificationDecision,User};
use GuzzleHttp\{Client,HandlerStack};use GuzzleHttp\Handler\MockHandler;use GuzzleHttp\Psr7\Response;
$checks=0;$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS: '.$message.PHP_EOL;};
$drive=new class extends GoogleDriveService{public array $index=[];public function __construct(){}public function artworkIndex():array{return $this->index;}};
$shopify=new class extends ShopifyService{public array $rows=[];public function __construct(){}public function getArtworkCatalog():array{return $this->rows;}};
$matcher=new ArtworkMatcherService($drive);
$folder=['kind'=>'series_folder','id'=>'pse','name'=>'PSE','path'=>'GROUP B (Carpet) / PSE'];
$source=fn($id,$name,$parent='pse')=>['kind'=>'artwork','id'=>$id,'name'=>$name,'parent_id'=>$parent,'path'=>($parent==='snp'?'(GROUP A) Non Carpet / Snipers / ':'GROUP B (Carpet) / PSE / ').$name,'mimeType'=>'image/jpeg','version'=>'1','modifiedTime'=>'t1','md5Checksum'=>'unchanged','canRename'=>false];
$row=['catalog_key'=>hash('sha256','red'),'sku'=>'2027-DUC-PSE-0001','product'=>'Lucky Bags 2027 Duck Camo','variant'=>'Pro Sniper Elite / Red'];
$shopify->rows=[$row];$drive->index=[$folder,$source('duck','PSN-ELT-DUCK CAMO-RED.jpg')];
$directory=storage_path('framework/cache/data/naming-tests-'.bin2hex(random_bytes(8)));
$store=new ArtworkRenameStore($directory);$service=new ArtworkNamingService($drive,$shopify,$matcher,$store);
$admin=User::create(['name'=>'Admin','email'=>'naming@example.test','password'=>'NamingTestPassword123','role'=>'admin']);
$operator=User::create(['name'=>'Operator','email'=>'operator@example.test','password'=>'unused','role'=>'operator']);
$plan=$service->scan($admin->id);$entry=$plan['entries'][0];$target=$entry['proposal']['target'];
$check($entry['status']==='ready' && str_starts_with($target,'LB-2027-PSE-DUC-RED-SINGLE-ID-') && str_ends_with($target,'.jpg'),'Catalog products with no orders generate extended canonical names with a stable ID');
$check($service->scan($admin->id)['entries'][0]['proposal']['target']===$target,'Repeated scans generate the same name for the same Drive file');
$check($matcher->match($row,[$folder,[...$drive->index[1],'name'=>$target]])['match_status']==='REVIEW','New unique-ID filename is accepted by the production matcher');
$check(!$matcher->match($row,[$folder,[...$drive->index[1],'id'=>'different-file','name'=>$target]])['matches'],'A unique-ID filename cannot impersonate a different Drive file');
$bare=$service->build([$folder,$source('bare','Duck Camo Red.jpg')],[$row],[],[]);
$check($bare[0]['status']==='ready','Exact bare full design and color can propose a reviewed rename using series folder context');
$unknown=$service->build([$folder,$source('unknown','some random image.jpg')],[$row],[],[]);
$check($unknown[0]['status']==='review','Arbitrary names are never guessed from folders');
$mapped=$service->build([$folder,$source('unknown','some random image.jpg')],[$row],['unknown'=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']],[]);
$check($mapped[0]['status']==='ready','Explicit admin mapping creates a new preview rather than an immediate rename');
$check($service->build([$folder,$source('mock','DUC-RED-MOCKUP.jpg')],[$row],['mock'=>['catalog_key'=>$row['catalog_key'],'side'=>'single','color'=>'RED']],[])[0]['status']==='review','Manual mappings cannot turn mockups into production artwork');
$check($service->build([$folder,$source('nested','PSN-ELT-DUCK CAMO-RED.jpg','nested')],[$row],[],[])===[],'Files without a direct approved series parent are excluded');
$wrong=$service->build([$folder,$source('wrong','PSN-DUCK CAMO-RED.jpg')],[$row],[],[]);
$check($wrong[0]['status']==='review','An explicit wrong series is not silently replaced from folder context');
$duplicates=$service->build([$folder,$source('dup1','PSN-ELT-DUCK CAMO-RED.jpg'),$source('dup2','PSE-DUC-RED.jpg')],[$row],[],[]);
$check($duplicates[0]['proposal']['target']!==$duplicates[1]['proposal']['target'],'Distinct Drive IDs prevent duplicate destination names');
$renamedDuplicates=[$folder];foreach($duplicates as $e)$renamedDuplicates[]=[...$e['source'],'name'=>$e['proposal']['target']];
$check($matcher->match($row,$renamedDuplicates)['match_status']==='AMBIGUOUS','Unique filenames never automatically resolve duplicate artwork candidates');
$double=[...$row,'catalog_key'=>hash('sha256','double'),'sku'=>'2027-CAN-BRL-SNP-0001','product'=>'Lucky Bags 2027 Brown Leather – ACL Cornhole Bags','variant'=>'Snipers'];
$doubleFolder=['kind'=>'series_folder','id'=>'snp','name'=>'Snipers','path'=>'(GROUP A) Non Carpet / Snipers'];
foreach(['front','back'] as $side){$e=$service->build([$doubleFolder,$source('leather-'.$side,'unknown-'.$side.'.jpeg','snp')],[$double],['leather-'.$side=>['catalog_key'=>$double['catalog_key'],'side'=>$side,'color'=>'']],[])[0];$check(str_contains($e['proposal']['target'],'-'.strtoupper($side).'-ID-')&&str_ends_with($e['proposal']['target'],'.jpeg'),'Double-side '.$side.' and actual extension are preserved');}
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
$item=ProductionItem::create(['line_item_id'=>'unbatched',...$row,...$matcher->match($row,$drive->index)]);
app(ProductionService::class)->verify($item,'CORRECT',$admin->id);
$beforeBatches=PrintBatch::all()->toJson();$service->begin($plan['token'],$admin->id,['duck'],false);$done=$service->step($plan['token'],$admin->id);
$check($done['status']==='complete' && $writer->writes===1 && $done['renamed']===1,'Confirmed plans apply metadata renames in bounded chunks');
$check(!$item->fresh('decision')->decision && !$item->fresh()->matches,'Affected unbatched selections and approvals are invalidated before writing');
$check(PrintBatch::all()->toJson()===$beforeBatches,'Applying names never creates a batch or requests a print handoff');
$check($done['events'][0]['old_name']==='PSN-ELT-DUCK CAMO-RED.jpg' && $done['events'][0]['status']==='renamed','Private audit records old/new names, stable file ID and outcome');
$check($service->scan($admin->id)['entries'][0]['status']==='standard','Running the tool tomorrow leaves existing standard files unchanged');
try{$service->begin($plan['token'],$admin->id,['duck'],false);throw new RuntimeException('Replay accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Consumed naming plans cannot be replayed');}
try{$store->load($plan['token'],$operator->id);throw new RuntimeException('Other actor admitted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Plans are bound to the reviewing admin');}
// A file batched after scan must stop the operation without changing orders or Drive.
$drive->index=[$folder,$source('locked','PSN-ELT-DUCK CAMO-RED.jpg')];$lockedPlan=$service->scan($admin->id);
$batch=PrintBatch::create(['batch_id'=>'protected','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>'unbatched','artworks'=>[['drive_file_id'=>'locked']]]]]]);
$lockedBefore=$batch->fresh()->toJson();
try{$service->begin($lockedPlan['token'],$admin->id,['locked'],false);throw new RuntimeException('Batched rename accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $batch->fresh()->toJson()===$lockedBefore && $writer->writes===1,'Batch membership changes invalidate preview without mutating a batch');}
foreach(['QUEUED','CLAIMED','PRINTED','FAILED'] as $status){$batch->update(['status'=>$status]);$check($service->scan($admin->id)['entries'][0]['status']==='locked','Files from '.$status.' batches remain protected');}
$drive->index=[$folder,$source('stale','PSN-ELT-DUCK CAMO-RED.jpg')];$stale=$service->scan($admin->id);$drive->index[1]['version']='9';
try{$service->begin($stale['token'],$admin->id,['stale'],false);throw new RuntimeException('Stale preview accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Changed Drive versions invalidate preview');}
$stale=$service->scan($admin->id);$shopify->rows[0]['product']='Changed catalog';
try{$service->begin($stale['token'],$admin->id,['stale'],false);throw new RuntimeException('Changed catalog accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Catalog changes invalidate preview');}
$shopify->rows=[$row];$drive->index=[$folder,$source('permission','PSN-ELT-DUCK CAMO-RED.jpg')];$permission=$service->scan($admin->id);$writer->permission=false;$service->begin($permission['token'],$admin->id,['permission'],false);$stopped=$service->step($permission['token'],$admin->id);
$check($stopped['status']==='stopped' && str_contains($stopped['warning'],'Editor access') && $writer->writes===1,'Missing Editor access stops before any rename');$writer->permission=true;
$drive->index=[$folder,$source('failure','PSN-ELT-DUCK CAMO-RED.jpg')];$failure=$service->scan($admin->id);$service->begin($failure['token'],$admin->id,['failure'],false);$writer->fail=true;$failed=$service->step($failure['token'],$admin->id);
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
$html=$controller->index($request,$store)->render();$check(str_contains($html,'Check filenames') && str_contains($html,'Rename selected files') && str_contains($html,'Type RENAME'),'Admin page renders scan, review, explicit confirmation and filename preview');
$request=Illuminate\Http\Request::create('/admin/artwork-names/begin','POST',['plan'=>$stale['token'],'ids'=>['stale']]);$request->setUserResolver(fn()=>$admin);
try{$controller->begin($request);throw new LogicException('Unconfirmed apply admitted');}catch(Illuminate\Validation\ValidationException $e){$check(isset($e->errors()['password'],$e->errors()['confirmation'],$e->errors()['reviewed']),'Apply requires admin password, typed RENAME and explicit review');}
// Test full catalog pagination without Shopify credentials or order history.
$catalogService=new class extends ShopifyService{
 public array $seen=[];public function __construct(){}
 public function graphql(string $query,array $variables=[]):array{$this->seen[]=$variables['after'];$next=count($this->seen)===1;return ['productVariants'=>['nodes'=>[['id'=>$next?'v1':'v2','sku'=>$next?'2027-DUC-PSE-0001':'2027-EAR-CLV-0001','title'=>'Clover','product'=>['title'=>'Lucky Bags 2027 Earth','productType'=>'ACL Pro Stamped 2027','status'=>'ACTIVE']]],'pageInfo'=>['hasNextPage'=>$next,'endCursor'=>$next?'second':null]]];}
};
$check(count($catalogService->getArtworkCatalog())===2 && $catalogService->seen===[null,'second'],'Catalog scan paginates variants even when no orders exist');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks admin artwork naming checks passed.\n";
// Scope is visible before a scan and exact folders are highlighted afterwards.
$request=Illuminate\Http\Request::create('/admin/artwork-names');$request->setUserResolver(fn()=>$admin);
$initial=$controller->index($request,$store)->render();
$check(str_contains($initial,'Scan scope · these folders only')&&str_contains($initial,'GROUP B (Carpet)')&&str_contains($initial,'MOCKUPS')&&str_contains($initial,'Snipers'),'Initial admin page clearly highlights approved roots, version folders and exclusions');
$rogueFolder=[...$folder,'path'=>'Outside manufacturing / PSE'];
$check($service->build([$rogueFolder,$source('rogue','PSE-DUC-RED.jpg')],[$row],[],[])===[],'Planner rejects folders outside the approved branches');
$nestedFolder=[...$folder,'path'=>'GROUP B (Carpet) / MOCKUPS / PSE'];
$check($service->build([$nestedFolder,$source('nested-parent','PSE-DUC-RED.jpg')],[$row],[],[])===[],'Planner rejects even recognizable versions nested under mockups');
// HTTP precondition failures are never retried.
$preconditionHistory=[];$stack=HandlerStack::create(new MockHandler([new Response(200,[],json_encode($raw)),new Response(412,[],'{}')]));$stack->push(GuzzleHttp\Middleware::history($preconditionHistory));
try{(new GoogleDriveRenameService(new Client(['handler'=>$stack])))->rename($expected,'new-name.jpg');throw new Exception('Precondition ignored');}catch(RuntimeException $e){$check(count($preconditionHistory)===2,'A changed ETag stops at HTTP 412 without retry');}
// No user can select a path that was not in their server-side preview.
$drive->index=[$folder,$source('only','PSN-ELT-DUCK CAMO-RED.jpg')];$untrusted=$service->scan($admin->id);
try{$service->begin($untrusted['token'],$admin->id,['injected-id'],false);throw new Exception('Injected file accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Tampered file selections are rejected before remote work');}
$expired=$store->create($admin->id,['expires_at'=>time()-1]);
try{$store->load($expired['token'],$admin->id);throw new Exception('Expired plan accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Expired previews cannot authorize renames');}
// Preserved file extensions also round-trip through all normal matching paths.
foreach(['png','jpeg','TIFF','psd'] as $extension){$f=$source('extension-'.$extension,'PSE-DUC-RED.'.$extension);$e=$service->build([$folder,$f],[$row],[],[])[0];$check(str_ends_with($e['proposal']['target'],'.'.$extension),'Original extension is preserved exactly: '.$extension);}
// Missing-file suggestions use the same readable convention, without inventing a Drive ID.
$suggestion=$matcher->match($row,[$folder])['artwork_review']['suggested_filenames'][0];
$check($suggestion==='LB-2027-PSE-DUC-RED-SINGLE.jpg' && $matcher->match($row,[$folder,$source('uploaded',$suggestion)])['match_status']==='REVIEW','Standard suggestions work before a file exists; the ID suffix is assigned once Drive has a real file');
foreach(glob($directory.'/*.json') as $file)unlink($file);rmdir($directory);
echo "$checks total admin artwork naming checks passed.\n";
// A bulk confirmation runs in bounded chunks and a later failure never conceals partial success.
$drive->index=[$folder];for($i=0;$i<6;$i++)$drive->index[]=$source('bulk-'.$i,'PSN-ELT-DUCK CAMO-RED.jpg');
$bulk=$service->scan($admin->id);$service->begin($bulk['token'],$admin->id,array_column($bulk['entries'],'id'),false);$beforeWrites=$writer->writes;
$chunk=$service->step($bulk['token'],$admin->id);$check($writer->writes===$beforeWrites+5 && count($chunk['pending'])===1 && $chunk['status']==='applying','Large operations process at most five files per request');
$chunk=$service->step($bulk['token'],$admin->id);$check($chunk['status']==='complete' && $chunk['renamed']===6,'The next confirmed chunk completes only the remaining files');
$drive->index=[$folder,$source('partial-1','PSN-ELT-DUCK CAMO-RED.jpg'),$source('partial-2','PSN-ELT-DUCK CAMO-RED.jpg')];
$partial=$service->scan($admin->id);$service->begin($partial['token'],$admin->id,['partial-1','partial-2'],false);$writer->failAfter=$writer->writes+1;
$partialResult=$service->step($partial['token'],$admin->id);
$check($partialResult['status']==='stopped' && $partialResult['renamed']===1 && $partialResult['events'][0]['status']==='renamed' && $partialResult['events'][1]['status']==='attempting','A partial remote failure records the completed rename and uncertain attempt separately');
$writer->failAfter=null;$reconciled=$service->scan($admin->id);$states=array_count_values(array_column($reconciled['entries'],'status'));
$check(($states['standard']??0)===1 && ($states['ready']??0)===1,'A new scan reconciles actual Drive names after a partial failure without blindly retrying');
$drive->index=[$folder,$source('refresh','PSN-ELT-DUCK CAMO-RED.jpg')];
$refreshItem=ProductionItem::create(['line_item_id'=>'refresh-after-rename',...$row,...$matcher->match($row,$drive->index)]);
app(ProductionService::class)->verify($refreshItem,'CORRECT',$admin->id);
$refreshPlan=$service->scan($admin->id);$service->begin($refreshPlan['token'],$admin->id,['refresh'],true);$batchesBefore=PrintBatch::all()->toJson();
$refreshed=$service->step($refreshPlan['token'],$admin->id);
$check($refreshed['status']==='complete' && $refreshed['match_counts']['matched']===1 && str_starts_with($refreshItem->fresh()->matches[0]['name'],'LB-2027-'),'Optional refresh populates the actual renamed filename on unbatched production items');
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
$check(str_contains($settingsHtml,'admin-settings-nav') && str_contains($settingsHtml,'Check filenames') && !str_contains($settingsHtml,'Type RESET PORTAL'),'Artwork is the default settings section and does not render the reset form');
$menu=view('partials.account-menu')->render();
$check(str_contains($menu,'Admin Settings') && !str_contains($menu,'Artwork filenames') && !str_contains($menu,'Reset portal data'),'Account menu groups the two tools under a single Admin Settings link');
$request=Illuminate\Http\Request::create('/admin/settings','GET',['section'=>'../../credentials']);$request->setUserResolver(fn()=>$admin);
try{$settings->index($request);throw new Exception('Unknown section accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===404,'Settings sections use an allowlist instead of arbitrary view paths');}
$request=Illuminate\Http\Request::create('/admin/settings');$request->setUserResolver(fn()=>$operator);
try{$settings->index($request);throw new Exception('Operator admitted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'The shared settings page remains admin-only');}
echo "$checks total admin artwork naming/settings checks passed.\n";
