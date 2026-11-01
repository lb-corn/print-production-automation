<?php
// Reviewed regression harness. All persistence is SQLite :memory:, all integrations are fakes.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','logging.default'=>'stderr']);
Illuminate\Support\Facades\DB::purge('sqlite');
foreach (glob(__DIR__.'/../database/migrations/*.php') as $migration) (require $migration)->up();
use App\Services\{GoogleDriveService,ArtworkMatcherService,ArtworkRematchService,ProductionService,BacklogSyncService,ShopifyService};
use App\Models\{ProductionItem,PrintBatch,VerificationDecision,User};
$checks = 0;
$check = function($condition,$message) use (&$checks) { if (!$condition) throw new RuntimeException($message); $checks++; echo "PASS: $message\n"; };
$drive = new class extends GoogleDriveService {
    public array $tree = []; public array $visited = []; public bool $fail = false;
    public function __construct() {}
    public function manufacturingRoots(): array { return [['id'=>'group-b','name'=>'GROUP B (Carpet)']]; }
    public function listChildren(string $id): array { if ($this->fail) throw new RuntimeException('Drive unavailable'); $this->visited[]=$id; return $this->tree[$id] ?? []; }
};
$folder = fn($id,$name) => ['id'=>$id,'name'=>$name,'mimeType'=>'application/vnd.google-apps.folder'];
$file = fn($id,$name) => ['id'=>$id,'name'=>$name,'mimeType'=>'image/png','modifiedTime'=>'v1','md5Checksum'=>'a','webViewLink'=>'https://drive.google.com/file/d/'.$id.'/view'];
$drive->tree = ['group-b'=>[$folder('pse','PSE')], 'pse'=>[
    $file('correct','PSE-DUC-RED.png'),$file('wrong-color','PSE-DUC-BROWN.png'),
    $file('mockup','PSE-DUC-RED-MOCKUP.png'),$folder('mockups','MOCKUPS'),$folder('nested','OTHER'),
], 'mockups'=>[$file('mockup-child','PSE-DUC-RED.png')], 'nested'=>[$file('nested-child','PSE-DUC-RED.png')]];
$matcher = new ArtworkMatcherService($drive);
$row = ['sku'=>'2027-DUC-PSE-0001','product'=>'Pro Sniper Elite','variant'=>'Pro Sniper Elite / Red'];
$match = $matcher->match($row);
$check(array_column($match['matches'],'id')===['correct'],'Correct direct parent artwork selected; mockups excluded');
$check(!in_array('nested',$drive->visited) && !in_array('mockups',$drive->visited),'No nested folders are visited');
$check($match['match_status']==='REVIEW' && $match['confidence']===null,'Exact match still requires operator approval');
$brown=$matcher->match([...$row,'variant'=>'Brown']);
$check(array_column($brown['matches'],'id')===['wrong-color'],'Brown and Red are distinct supported colors');
foreach (['PSE-DUC-BROWN.png','SUP-DUC-RED.png','PSE-DUCK-RED.png','PSE-DUC-RED-MOCKUP.png','PSE-DUC-RED-BACK.png','PSE-DUC-RED-BLUE.png'] as $name) {
    $drive->tree['pse']=[$file('invalid',$name)];
    $bad=$matcher->match($row);
    $check(!$bad['matches'] && $bad['match_status']==='MISSING','Reject mismatched filename '.$name);
}
$drive->tree['pse']=[$file('invalid','PSE-DUC-BROWN.TIFF')];
$missing=$matcher->match($row);
$check($missing['artwork_review']['suggested_filenames']===['PSE-DUC-RED.jpg'],'Missing artwork supplies conventional filename');
$check($missing['artwork_review']['corrections'][0]['suggested']==='PSE-DUC-RED.TIFF','Corrections preserve actual extension');
$check($missing['artwork_review']['expected_folder']==='GROUP B (Carpet) / PSE' && str_ends_with($missing['artwork_review']['folder_url'],'/pse'),'Expected folder and direct link supplied');
$drive->tree['pse']=[$file('one','PSE-DUC-RED.png'),$file('two','pse_duc_red.tiff')];
$ambiguous=$matcher->match($row);
$check($ambiguous['match_status']==='AMBIGUOUS' && !$ambiguous['matches'],'Duplicate plausible files cannot select or approve a winner');
$drive->tree['group-b'][]=$folder('duplicate','PSN-ELT');
$check($matcher->match($row)['match_status']==='AMBIGUOUS','Duplicate alias series folders are ambiguous');
$drive->tree['group-b']=[];
$check($matcher->match($row)['matches']===[],'Missing series folder never searches another location');
$drive->tree['group-b']=[$folder('srf','SRF')];
$drive->tree['srf']=[$file('front','SRF-DUC-RED-FRONT.png')];
$double=[...$row,'sku'=>'2027-DUC-SRF-0001'];
$check(!$matcher->match($double)['matches'],'Incomplete double-sided artwork selects no partial set');
$drive->tree['srf'][]=$file('back','SRF-DUC-RED-BACK.png');
$check(count($matcher->match($double)['matches'])===2,'Double-sided artwork requires exact front and back');
$drive->tree['srf'][]=$file('back2','SRF-DUC-RED-BACK.tif');
$check($matcher->match($double)['match_status']==='AMBIGUOUS','Duplicate back side makes complete double-sided set ambiguous');
$check(!$matcher->match([...$row,'variant'=>'Red / Brown'])['matches'],'Ambiguous variant colors fail closed');
$drive->tree['group-b']=[$folder('pse','PSE')];
$drive->tree['pse']=[$file('correct','PSE-DUC-RED.png')];
$app->instance(GoogleDriveService::class,$drive);
$app->instance(ArtworkMatcherService::class,$matcher);
$svc=$app->make(ProductionService::class);
$user=User::create(['name'=>'Operator','email'=>'artwork@example.test','password'=>'unused','role'=>'operator']);
Illuminate\Support\Facades\Auth::login($user);
$make = function($id) use($row,$matcher) { return ProductionItem::create(['line_item_id'=>$id,...$row,...$matcher->match($row)]); };
$item=$make('unbatched');
$svc->verify($item,'CORRECT',$user->id);
$rematch=new ArtworkRematchService($drive,$matcher);
$counts=$rematch->rematch();
$check($counts===['matched'=>1,'missing'=>0,'ambiguous'=>0,'skipped'=>0] && $item->fresh('decision')->decision!==null,'Unchanged identity retains explicit operator approval');
$drive->tree['pse'][0]['modifiedTime']='v2';
$rematch->rematch();
$check($item->fresh('decision')->decision===null,'Same Drive ID with changed content revision invalidates approval');
$svc->verify($item->fresh(),'CORRECT',$user->id);
$drive->tree['pse']=[$file('replacement','PSE-DUC-RED.png')];
$rematch->rematch();
$check($item->fresh('decision')->decision===null,'Changed artwork identity invalidates approval');
$svc->verify($item->fresh(),'CORRECT',$user->id);
$drive->tree['pse']=[];
$counts=$rematch->rematch();
$check($counts['missing']===1 && !$item->fresh()->matches && !$item->fresh('decision')->decision,'Deleted artwork clears stale matches and approvals');
try { $svc->verify($item->fresh(),'CORRECT',$user->id); throw new RuntimeException('Missing approved'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode()===422,'Server rejects missing artwork approval'); }
$drive->tree['pse']=[$file('one','PSE-DUC-RED.png'),$file('two','PSE-DUC-RED.tif')];
$counts=$rematch->rematch();
$check($counts['ambiguous']===1,'Rematch reports ambiguous count');
try { $svc->verify($item->fresh(),'CORRECT',$user->id); throw new RuntimeException('Ambiguous approved'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode()===422,'Server rejects ambiguous artwork approval'); }
$drive->tree['pse']=[$file('correct','PSE-DUC-RED.png')];
foreach (['QUEUED','CLAIMED','PREPARED','HANDOFF_REQUESTED','SENT_TO_RIP','PRINTED','FAILED'] as $state) {
    $locked=$make('locked-'.$state);$svc->verify($locked,'CORRECT',$user->id);
    PrintBatch::create(['batch_id'=>'batch-'.$state,'status'=>$state,'order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>$locked->line_item_id]]]]);
}
$beforeItems=ProductionItem::where('line_item_id','like','locked-%')->get()->toJson();
$beforeDecisions=VerificationDecision::where('line_item_id','like','locked-%')->get()->toJson();
$beforeBatches=PrintBatch::all()->toJson();
$drive->tree['pse']=[];$counts=$rematch->rematch();
$check($counts['skipped']===7 && ProductionItem::where('line_item_id','like','locked-%')->get()->toJson()===$beforeItems,'Every batch state preserves locked items');
$check(VerificationDecision::where('line_item_id','like','locked-%')->get()->toJson()===$beforeDecisions,'Batched approvals preserved');
$check(PrintBatch::all()->toJson()===$beforeBatches,'Rematch neither creates batches nor requests print handoff');
$before=ProductionItem::all()->toJson();$drive->fail=true;
try { $rematch->rematch(); throw new RuntimeException('Drive error ignored'); }
catch(RuntimeException $e) { $check($e->getMessage()==='Drive unavailable' && ProductionItem::all()->toJson()===$before,'Drive failure leaves saved state untouched'); }
$drive->fail=false;
$shopify=new class extends ShopifyService { public array $rows=[]; public function __construct() {} public function getProductionBacklog(int $first=50): array { return ['items'=>$this->rows,'item_count'=>count($this->rows),'order_count'=>1]; } };
$shopify->rows=[['line_item_id'=>'unbatched',...$row]];
$drive->tree['pse']=[$file('latest','PSE-DUC-RED.png')];
$sync=new BacklogSyncService($shopify,$drive,$matcher);$sync->sync();
$svc->verify($item->fresh(),'CORRECT',$user->id);
$drive->tree['pse']=[];$sync->sync();
$check(!$item->fresh()->matches && !$item->fresh('decision')->decision,'Regular backlog refresh removes obsolete selections and approvals');
$controller=$app->make(App\Http\Controllers\ProductionController::class);
try { $controller->rematchArtwork(Illuminate\Http\Request::create('/production/rematch','POST')); throw new RuntimeException('Unconfirmed rematch accepted'); }
catch(Illuminate\Validation\ValidationException $e) { $check(true,'Rematch requires explicit confirmation'); }
$route=app('router')->getRoutes()->getByName('production.rematch');
$middleware=$route->gatherMiddleware();
$check(in_array('web',$middleware) && in_array('auth',$middleware) && in_array(App\Http\Middleware\ReadOnlyDemo::class,$middleware) && in_array(App\Http\Middleware\PortalWriteLock::class,$middleware),'Rematch uses CSRF, authentication, demo guard and write serialization');
view()->share('errors',new Illuminate\Support\ViewErrorBag);
$html=$controller->verification()->render();
$check(str_contains($html,'Copy Filename') && str_contains($html,'Open Google Drive Folder') && str_contains($html,'data-rematch-form'),'Operator UI renders suggestions, clipboard control, folder link and confirmed rematch');
$legacy=$make('legacy');$legacy->update(['artwork_review'=>null]);
$check(!ArtworkMatcherService::validSelection($legacy),'Legacy selections cannot be approved without strict rematch');
echo "$checks artwork security/regression checks passed.\n";

// A legacy mockup remains hidden and gets a computed naming convention without any Drive request.
$legacy->update(['match_status'=>'REVIEW','matches'=>[['id'=>'old-mockup','name'=>'PSN-ELT-DUCK CAMO RED MOCKUP.jpg','side'=>'single']],'artwork_review'=>null]);
$drive->fail=true;
$html=$controller->verification()->render();
$check(!str_contains($html,'old-mockup') && !str_contains($html,'PSN-ELT-DUCK CAMO RED MOCKUP.jpg'),'Legacy invalid preview is hidden from the right-hand box');
$check(str_contains($html,'PSE-DUC-RED.jpg') && str_contains($html,'Missing production artwork'),'Legacy missing state computes the proposed filename without Drive or database writes');
$check($legacy->fresh()->artwork_review===null && $legacy->fresh()->matches[0]['id']==='old-mockup','Rendering missing instructions does not modify saved selections');
$drive->fail=false;
// Prove rollback even when a later item fails after an earlier selection was saved.
$before=ProductionItem::all()->toJson();
$throwing=new class($drive) extends ArtworkMatcherService {
    private int $calls=0;
    public function match(array $row,?array $index=null): array {
        if (++$this->calls===2) throw new RuntimeException('Matching failed');
        return parent::match($row,$index);
    }
};
try { (new ArtworkRematchService($drive,$throwing))->rematch(); throw new RuntimeException('Failure ignored'); }
catch(RuntimeException $e) { $check($e->getMessage()==='Matching failed' && ProductionItem::all()->toJson()===$before,'Later matching failure rolls back all earlier selection changes'); }
echo "$checks total artwork security/regression checks passed.\n";
$drive->tree['pse']=[$file('valid-ui-file','PSE-DUC-RED.png')];
$validUi=$make('valid-ui');
$html=$controller->verification()->render();
$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET);$xpath=new DOMXPath($dom);
$card=$xpath->query('//details[@data-item-id="'.$validUi->id.'"]')->item(0);
$cardHtml=$dom->saveHTML($card);
$check(str_contains($cardHtml,'valid-ui-file') && !str_contains($cardHtml,'Missing production artwork') && !str_contains($cardHtml,'Suggested filename'),'Correct production artwork displays without missing instructions or naming errors');
$missingBox=$xpath->query('//details[@data-item-id="'.$legacy->id.'"]//div[contains(@class,"drive-grid")]//div[contains(@class,"artwork-review")]');
$check($missingBox->length===1,'Missing filename instructions replace the right-hand image inside its comparison box');
echo "$checks total artwork checks passed.\n";
$drive->tree['group-b']=[$folder('clover','Clover')];
$earth=['sku'=>'2027-EAR-CLV-0001','product'=>'Lucky Bags 2027 Earth','variant'=>'Clover'];
$drive->tree['clover']=[$file('earth-jpg','CLV-EAR.jpg')];
$earthMatch=$matcher->match($earth);
$check(array_column($earthMatch['matches'],'id')===['earth-jpg'] && $earthMatch['color']===null,'Series-only Clover variant correctly matches colorless Earth JPG');
$check($earthMatch['artwork_review']['suggested_filenames']===['CLV-EAR.jpg'],'Earth naming suggestion uses requested JPG format without inventing a color');
$drive->tree['clover']=[$file('colored-earth','CLV-EAR-RED.jpg')];
$check(!$matcher->match($earth)['matches'],'Colorless variant never picks an arbitrary colored file');
$check(!$matcher->match([...$earth,'variant'=>'Clover / Purple'])['matches'],'Unrecognized variant color does not silently become colorless');
$saved=[...$file('saved-earth','CLV-EAR.jpg'),'path'=>'GROUP B (Carpet)/Clover/CLV-EAR.jpg'];
$preview=$matcher->savedPreviews($earth,[$saved]);
$check(array_column($preview,'id')===['saved-earth'],'Correct saved Earth preview survives without strict approval metadata');
$check(!$matcher->savedPreviews($earth,[[...$saved,'path'=>'GROUP B (Carpet)/Clover/MOCKUPS/CLV-EAR.jpg']]),'Saved nested previews are excluded');
$earthItem=ProductionItem::create(['line_item_id'=>'earth',...$earth,...$earthMatch,'matches'=>[$saved],'artwork_review'=>null]);
$html=$controller->verification()->render();
$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET);$xpath=new DOMXPath($dom);
$earthCard=$dom->saveHTML($xpath->query('//details[@data-item-id="'.$earthItem->id.'"]')->item(0));
$check(str_contains($earthCard,'saved-earth') && !str_contains($earthCard,'Missing production artwork') && !str_contains($earthCard,'Suggested filename'),'Existing correct colorless JPG displays with no missing-artwork errors');
$check(!ArtworkMatcherService::validSelection($earthItem->fresh()) && !$svc->approved($earthItem->fresh('decision')),'Display-only preview never authorizes printing or approval');
$css=file_get_contents(__DIR__.'/../public/css/app.css');
$check(str_contains($css,'.verification-cards .art img,.verification-cards .placeholder,.verification-cards .artwork-missing-frame{height:var(--artwork-frame-height)}'),'Shopify, Drive and missing panels use one shared frame height');
echo "$checks total artwork checks passed.\n";
