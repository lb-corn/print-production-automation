<?php
// Reviewed regression harness. All persistence is SQLite :memory:, all integrations are fakes.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/material-fixture.php';
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, 'FAIL: '.$error->getMessage().PHP_EOL); exit(1); });
config(['services.google_drive.reviewed_selections_enabled'=>false,'database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','logging.default'=>'stderr']);
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
$check($missing['artwork_review']['suggested_filenames']===['LB-PSE-DUC-RED-SINGLE.jpg'],'Missing artwork supplies conventional filename');
$check(str_ends_with($missing['artwork_review']['corrections'][0]['suggested'],'.TIFF') && str_starts_with($missing['artwork_review']['corrections'][0]['suggested'],'LB-PSE-DUC-RED-SINGLE.'),'Corrections preserve actual extension');
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
$double=[...$row,'sku'=>'2027-DUC-SRF-0001','variant'=>'Surefire / Red'];
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
$check(!str_contains($html,'data-copy-filename') && str_contains($html,'artwork-folder-link') && str_contains($html,'data-rematch-form'),'Order UI offers folder access and confirmed rematch without filename suggestions or copy controls');
$legacy=$make('legacy');$legacy->update(['artwork_review'=>null]);
$check(!ArtworkMatcherService::validSelection($legacy),'Legacy selections cannot be approved without strict rematch');
echo "$checks artwork security/regression checks passed.\n";

// A legacy mockup remains hidden and gets a computed naming convention without any Drive request.
$legacy->update(['match_status'=>'REVIEW','matches'=>[['id'=>'old-mockup','name'=>'PSN-ELT-DUCK CAMO RED MOCKUP.jpg','side'=>'single']],'artwork_review'=>null]);
$drive->fail=true;
$html=$controller->verification()->render();
$check(!str_contains($html,'old-mockup') && !str_contains($html,'PSN-ELT-DUCK CAMO RED MOCKUP.jpg'),'Legacy invalid preview is hidden from the right-hand box');
$check(!str_contains($html,'LB-PSE-DUC-RED-SINGLE.jpg') && str_contains($html,'Check production artwork'),'Legacy missing state shows lookup guidance without proposed filenames or Drive/database writes');
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
$check(str_contains($cardHtml,'valid-ui-file') && !str_contains($cardHtml,'Production file not found') && !str_contains($cardHtml,'Suggested filename'),'Correct production artwork displays without missing instructions or naming errors');
$missingBox=$xpath->query('//details[@data-item-id="'.$legacy->id.'"]//div[contains(@class,"drive-grid")]//div[contains(@class,"artwork-review")]');
$check($missingBox->length===1,'Missing filename instructions replace the right-hand image inside its comparison box');
echo "$checks total artwork checks passed.\n";
$drive->tree['group-b']=[$folder('clover','Clover')];
$earth=['sku'=>'2027-EAR-CLV-0001','product'=>'Lucky Bags 2027 Earth','variant'=>'Clover'];
$drive->tree['clover']=[$file('earth-jpg','CLV-EAR.jpg')];
$earthMatch=$matcher->match($earth);
$check(array_column($earthMatch['matches'],'id')===['earth-jpg'] && $earthMatch['color']===null,'Series-only Clover variant correctly matches colorless Earth JPG');
$check($earthMatch['artwork_review']['suggested_filenames']===['LB-CLV-EAR-SINGLE.jpg'],'Earth naming suggestion uses requested JPG format without inventing a color');
$drive->tree['clover']=[$file('colored-earth','CLV-EAR-RED.jpg')];
$check($matcher->match($earth)['match_status']==='REVIEW','Unique colored artwork for unspecified Shopify color still requires operator review');
$check(!$matcher->match([...$earth,'variant'=>'Clover / Ultraviolet'])['matches'],'Unrecognized variant color does not silently become colorless');
$saved=[...$file('saved-earth','CLV-EAR.jpg'),'path'=>'GROUP B (Carpet)/Clover/CLV-EAR.jpg'];
$preview=$matcher->savedPreviews($earth,[$saved]);
$check(array_column($preview,'id')===['saved-earth'],'Correct saved Earth preview survives without strict approval metadata');
$check(!$matcher->savedPreviews($earth,[[...$saved,'path'=>'GROUP B (Carpet)/Clover/MOCKUPS/CLV-EAR.jpg']]),'Saved nested previews are excluded');
$earthItem=ProductionItem::create(['line_item_id'=>'earth',...$earth,...$earthMatch,'matches'=>[$saved],'artwork_review'=>null]);
$html=$controller->verification()->render();
$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NONET);$xpath=new DOMXPath($dom);
$earthCard=$dom->saveHTML($xpath->query('//details[@data-item-id="'.$earthItem->id.'"]')->item(0));
$check(str_contains($earthCard,'saved-earth') && !str_contains($earthCard,'Production file not found') && !str_contains($earthCard,'Suggested filename'),'Existing correct colorless JPG displays with no missing-artwork errors');
$check(!ArtworkMatcherService::validSelection($earthItem->fresh()) && !$svc->approved($earthItem->fresh('decision')),'Display-only preview never authorizes printing or approval');
$css=file_get_contents(__DIR__.'/../public/css/app.css');
$check(str_contains($css,'.verification-cards .art img,.verification-cards .placeholder,.verification-cards .artwork-missing-frame{height:var(--artwork-frame-height)}'),'Shopify, Drive and missing panels use one shared frame height');
echo "$checks total artwork checks passed.\n";
// Regressions from real production filenames and the reported failures.
$cases = [
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Red','PSE',['PSE-DUC-RED.jpg'],'REVIEW'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Red','PSE',['2027-PSN-ELT-DUCK CAMO-RED.jpg'],'REVIEW'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Black','PSE',['PSN-ELT-DUCK CAMO-BLACK.jpg'],'REVIEW'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Brown','PSE',['PSN-ELT-DUCK CAMO-RED.jpg'],'MISSING'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Red','PSE',['2027-PSN-ELT-DUCK CAMO-RED-MOCKUP.jpg'],'MISSING'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Red','PSE',['2026-PSN-ELT-DUCK CAMO-RED.jpg'],'REVIEW'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Red','PSE',['PSE-DUCK-RED.jpg'],'MISSING'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Red','PSE',['PSE-DUCK CAMOFLAGE-RED.jpg'],'MISSING'],
 ['2027-DUC-PSE-0001','Duck Camo','Pro Sniper Elite / Red','PSE',['PSE-DUC-RED.jpg','2027-PSN-ELT-DUCK CAMO-RED.jpg'],'AMBIGUOUS'],
 ['2027-EAR-CLV-0001','Earth','Clover','Clover',['CLV-EARTH-BLACK.jpg'],'REVIEW'],
 ['2027-EAR-CLV-0001','Earth','Clover','Clover',['CLV-EARTH-BLACK.jpg','CLV-EARTH-WHITE.jpg'],'AMBIGUOUS'],
 ['2027-EAR-CLV-0001','Earth','Clover / Red','Clover',['CLV-EARTH-BLACK.jpg'],'MISSING'],
 ['2027-BUB-CLV-0001','Bubble Fusion','Clovers','Clover',['CLV-BUBBLES.jpg'],'MISSING'],
 ['2027-BUB-PSE-0001','Bubble Fusion','Pro Sniper Elite','PSE',['Bubbles.jpg'],'MISSING'],
 ['2027-BUB-CLV-0001','Bubble Fusion','Clovers','Clover',['CLV-BUBBLES-EXTRA.jpg'],'MISSING'],
 ['2027-BUB-CLV-0001','Other design','Clovers','Clover',['CLV-BUBBLES.jpg'],'MISSING'],
 ['2027-BUB-SRF-0001','Bubble Fusion','Surefire','Surefire',['SRF-BUB-FRONT.jpg','SRF-BUB-BACK.jpg'],'REVIEW'],
 ['2027-BUB-CLT-0001','Bubble Fusion','Celtics','Celtics',['CLT-BUBBLE FUSION-FRONT.jpg','CLT-BUBBLE FUSION-BACK.jpg'],'REVIEW'],
 ['2027-BUB-SRF-0001','Bubble Fusion','Surefire','Surefire',['SRF-BUB-BLACK-FRONT.jpg','SRF-BUB-WHITE-BACK.jpg'],'AMBIGUOUS'],
 ['2027-GTA-PSE-0001','GTA','Pro Sniper Elite / Charcoal','PSE',['2027-PSN-ELT-GTA-CHARCOAL.jpg'],'REVIEW'],
 ['2027-VEG-PSE-0001','Vegas','Pro Sniper Elite','PSE',['2027-PSN-ELT-VEGAS-WHITE.jpg'],'REVIEW'],
 ['2027-FRI-PSE-0001','Lucky Friends','Pro Sniper Elite / White','PSE',['PSN-ELT-LUCKY FRIENDS-WHITE.jpg'],'REVIEW'],
 ['2027-BAC-PSE-0001','Big Ass Clover','Pro Sniper Elite / Black Leather','PSE',['PSN-ELT-BAC-BLACK LEATHER.jpg'],'REVIEW'],
 ['2027-BAC-PSE-0001','Big Ass Clover','Pro Sniper Elite / Black Leather','PSE',['PSN-ELT-BAC-BLACK.jpg'],'MISSING'],
 ['2027-BAC-PSE-0001','Big Ass Clover','Pro Sniper Elite / Black','PSE',['PSN-ELT-BAC-BLACK LEATHER.jpg'],'MISSING'],
 ['2027-GOW-PSE-0001','Golden Worlds','Pro Sniper Elite / Black','PSE',['PSN-ELT-GOLD WORLD-BLACK.jpg'],'MISSING'],
 ['2027-GOW-PSE-0001','Golden Worlds','Pro Sniper Elite / Black','PSE',['PSN-ELT-GOLDEN FINGER-BLACK.jpg'],'MISSING'],
 ['2027-CAM-PSE-0001','Clover Camo','Pro Sniper Elite / Green','PSE',['PSN-ELT-REVERSE CLOVER CAMO-GREEN.jpg'],'MISSING'],
 ['2027-REV-PSE-0001','Reverse Clover','Pro Sniper Elite / Navy','PSE',['2027-PSN-ELT-REVERSE CLOVER CAMO-NAVY.jpg'],'MISSING'],
];
foreach ($cases as [$sku,$title,$variant,$folderName,$names,$expected]) {
    $drive->tree=['group-b'=>[$folder('case-folder',$folderName)],'case-folder'=>array_map(fn($name,$id)=>$file('case-'.$id,$name),$names,array_keys($names))];
    $result=$matcher->match(['sku'=>$sku,'product'=>'Lucky Bags 2027 '.$title,'variant'=>$variant]);
    $check($result['match_status']===$expected && ($expected==='REVIEW' ? count($result['matches'])>0 : !$result['matches']),$sku.' / '.$variant.' / '.implode(' + ',$names).' => '.$expected);
    if ($expected==='REVIEW') $check(!$result['artwork_review']['issues'],'Correct established filenames produce no correction errors');
}
// Fail before any integration call if deployment has not applied the additive migration.
Illuminate\Support\Facades\Schema::table('production_items',fn($table)=>$table->dropColumn('artwork_review'));
$visited=$drive->visited;
try { $rematch->rematch(); throw new RuntimeException('Missing schema ignored'); }
catch(App\Exceptions\ArtworkSchemaNotReady $e) { $check($drive->visited===$visited && str_contains($e->getMessage(),'migration'),'Missing migration fails early with a specific actionable reason, without fetching Drive'); }
$request=Illuminate\Http\Request::create('/production/rematch','POST',['confirm_rematch'=>'1']);
$response=$controller->rematchArtwork($request);
$check($response->isRedirect() && str_contains(session('error'),'artwork_review migration'),'Rematch UI explains missing setup instead of returning a generic integration error');
// Backlog import still runs against the pre-migration schema; printing remains gated.
$drive->tree=['group-b'=>[$folder('pse','PSE')],'pse'=>[$file('schema-correct','PSE-DUC-RED.png'),$file('schema-amb-a','PSE-AMB-RED.jpg'),$file('schema-amb-b','PSE-AMB-RED.png')]];
$legacyMatch=$matcher->match($row);unset($legacyMatch['artwork_review']);$schemaItem=ProductionItem::create(['line_item_id'=>'schema-existing',...$row,...$legacyMatch]);
VerificationDecision::create(['line_item_id'=>$schemaItem->line_item_id,'decision'=>'CORRECT','drive_file_ids'=>['schema-correct'],'updated_by'=>$user->id]);
$shopify->rows=[['line_item_id'=>'schema-existing',...$row],['line_item_id'=>'schema-new',...$row],['line_item_id'=>'schema-missing',...$row,'sku'=>'2027-MIS-PSE-0001','product'=>'Lucky Bags Missing'],['line_item_id'=>'schema-ambiguous',...$row,'sku'=>'2027-AMB-PSE-0001','product'=>'Lucky Bags Ambiguous']];
$result=$sync->sync();
$check($result['added']===3 && $result['matched']===2 && $result['missing']===1 && $result['ambiguous']===1 && !$result['artwork_schema_ready'],'Missing review column does not block importing and checking mixed valid/missing/ambiguous orders');
$check($schemaItem->fresh('decision')->decision!==null && !$svc->ready($schemaItem->fresh()),'Unchanged legacy approval records survive sync without granting print eligibility before setup');
$check(ProductionItem::where('line_item_id','schema-new')->first()->match_status==='REVIEW' && ProductionItem::where('line_item_id','schema-missing')->first()->matches===[],'Pre-migration sync safely stores matching results in existing columns');
$app->instance(BacklogSyncService::class,$sync);session()->forget('error');$response=$controller->syncBacklog();
$check($response->isRedirect() && str_contains(session('ok'),'backlog refreshed') && str_contains(session('warning'),'server still needs'),'Backlog UI reports a successful sync separately from the server migration notice');
(require __DIR__.'/../database/migrations/2026_11_01_000005_add_artwork_review.php')->up();
echo "$checks total artwork checks passed.\n";
// Legacy spelling retains a whole, explicit series alias; PSN alone can never match PSE.
$drive->tree=['group-b'=>[$folder('pse','PSE')],'pse'=>[$file('finger','PNS-ELT-GOLDEN FINGER-WHITE.jpg')]];
$finger=['sku'=>'2027-GOF-PSE-0001','product'=>'Lucky Bags 2027 Golden Finger','variant'=>'Pro Sniper Elite / White'];
$check(!$matcher->match($finger)['matches'],'Nonstandard PNS-ELT spelling requires correction rather than a hardcoded alias');
$drive->tree['pse']=[$file('wrong-series','PSN-GOLDEN FINGER-WHITE.jpg')];
$check(!$matcher->match($finger)['matches'],'PSN alone never substitutes for PSE even in the PSE folder');
$ui=$controller->verification()->render();
$document=new DOMDocument();@$document->loadHTML($ui);$xpath=new DOMXPath($document);
$toolbar=$xpath->query('//section[@aria-labelledby="production-refresh-heading"]')->item(0);
$rematchForm=$toolbar?$xpath->query('.//form[@data-rematch-form][@method="post"]',$toolbar)->item(0):null;
$backlogForm=$toolbar?$xpath->query('.//form[@data-backlog-form][@method="post"]',$toolbar)->item(0):null;
$check($rematchForm && $rematchForm->getAttribute('action')===route('production.rematch') && $xpath->query('.//input[@name="_token"]',$rematchForm)->length===1 && $xpath->query('.//input[@name="confirm_rematch"][@value="1"]',$rematchForm)->length===1,'Orders and artwork section retains the protected global rematch action');
$check($backlogForm && $backlogForm->getAttribute('action')===route('production.sync-backlog') && $xpath->query('.//input[@name="_token"]',$backlogForm)->length===1 && $xpath->query('.//input[@name="return_to"][@value="production"]',$backlogForm)->length===1,'Backlog refresh shares the same section and returns to Artwork Verification');
$check(str_contains($ui,'Matching details') && !str_contains($ui,'<div class="artwork-filename-row">') && !str_contains($ui,'data-copy-filename'),'Missing-artwork UI retains diagnostics and removes filename suggestions');
echo "$checks total artwork checks passed.\n";
// A future design follows the same grammar; no image-specific configuration or alias is needed.
$future=['sku'=>'2028-ZEB42-PSE-0001','product'=>'Lucky Bags 2028 Zebra Night Sky','variant'=>'Pro Sniper Elite / Red'];
foreach (['PSE-ZEB42-RED.jpg','2028-PSN-ELT-ZEBRA NIGHT SKY-RED.jpg'] as $name) {
    $drive->tree=['group-b'=>[$folder('pse','PSE')],'pse'=>[$file('future',$name)]];
    $check($matcher->match($future)['match_status']==='REVIEW','New design matches generic standard: '.$name);
}
$drive->tree=['group-b'=>[$folder('clover','Clover')],'clover'=>[$file('shortened','CLV-BUBBLES.jpg')]];
$bubble=['sku'=>'2027-BUB-CLV-0001','product'=>'Lucky Bags 2027 Bubble Fusion','variant'=>'Clovers'];
$nonstandard=$matcher->match($bubble);
$check($nonstandard['match_status']==='MISSING' && $nonstandard['artwork_review']['suggested_filenames']===['LB-CLV-BUB-SINGLE.jpg'],'Nonstandard Bubble abbreviation gets a canonical SKU filename suggestion without a special-case alias');
foreach (['CLV-BUB.jpg','CLV-BUBBLE FUSION.jpg'] as $name) {
    $drive->tree['clover']=[$file('bubble',$name)];
    $check($matcher->match($bubble)['match_status']==='REVIEW','Bubble Fusion follows the same standard rule: '.$name);
}
echo "$checks total artwork checks passed.\n";

// A problem on one product cannot hold up valid approved work from the same order.
$drive->tree=['group-b'=>[$folder('pse','PSE')],'pse'=>[$file('mixed-correct','PSE-DUC-RED.jpg'),$file('mixed-amb-a','PSE-AMB-RED.jpg'),$file('mixed-amb-b','PSE-AMB-RED.png')]];
$drive->tree['pse'][]=$file('previously-approved-missing','PSE-MIS-RED.jpg');
$oldMissingRow=[...$row,'sku'=>'2027-MIS-PSE-0001','product'=>'Lucky Bags Missing','order_number'=>'same-order'];
$oldMissing=ProductionItem::create(['line_item_id'=>'independent-missing',...$oldMissingRow,...$matcher->match($oldMissingRow)]);$svc->verify($oldMissing,'CORRECT',$user->id);array_pop($drive->tree['pse']);
$shopify->rows=[['line_item_id'=>'independent-good',...$row,'order_number'=>'same-order'],['line_item_id'=>'independent-missing',...$row,'order_number'=>'same-order','sku'=>'2027-MIS-PSE-0001','product'=>'Lucky Bags Missing'],['line_item_id'=>'independent-ambiguous',...$row,'order_number'=>'same-order','sku'=>'2027-AMB-PSE-0001','product'=>'Lucky Bags Ambiguous']];
$before=PrintBatch::count();$result=$sync->sync();$check($result['matched']===1 && $result['missing']===1 && $result['ambiguous']===1 && PrintBatch::count()===$before,'Sync checks only order artwork and reports individual problems without creating print batches');
$good=ProductionItem::where('line_item_id','independent-good')->first();$missingItem=ProductionItem::where('line_item_id','independent-missing')->first();$ambiguousItem=ProductionItem::where('line_item_id','independent-ambiguous')->first();
$svc->verify($good,'CORRECT',$user->id);
$check(!$missingItem->fresh('decision')->decision && $good->fresh('decision')->decision!==null,'Sync removes stale approval only from the product whose artwork disappeared');
foreach([$missingItem,$ambiguousItem] as $blocked)try{$svc->verify($blocked,'CORRECT',$user->id);throw new RuntimeException('Invalid artwork approved');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Only the item with invalid or ambiguous artwork is blocked from approval');}
$batch=$svc->batch($user->id);$check(array_column($batch->payload['orders'],'line_item_id')===['independent-good'],'Valid approved work enters the batch while missing and ambiguous sibling products stay out');
$check(!$svc->processed($missingItem) && !$svc->processed($ambiguousItem) && !VerificationDecision::whereIn('line_item_id',[$missingItem->line_item_id,$ambiguousItem->line_item_id])->exists(),'Blocked products remain pending and unapproved without blocking the rest of the order');
$check(!$batch->confirmations()->exists(),'Per-item gating never authorizes Epson handoff or physical printing automatically');
echo "$checks total artwork checks passed.\n";
