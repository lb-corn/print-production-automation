<?php
// Isolated database and faked integrations; never touches live queue entries.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
config(['services.google_drive.reviewed_selections_enabled'=>false,'database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array']);Illuminate\Support\Facades\DB::purge('sqlite');
foreach(glob(__DIR__.'/../database/migrations/*.php') as $file)(require $file)->up();
use App\Models\{ProductionItem,PrintBatch,User,VerificationDecision};
use App\Services\{ProductionService,BatchReturnService,ArtworkMatcherService};
$checks=0;$check=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;$checks++;};
$user=User::create(['name'=>'Operator','email'=>'return@example.test','password'=>'unused','role'=>'operator']);$svc=new ProductionService();$return=new BatchReturnService();
$create=fn($id)=>ProductionItem::create(['line_item_id'=>$id,'product'=>'Test artwork','variant'=>'PSE','quantity'=>1,'series_code'=>'PSE','design_type'=>'single','match_status'=>'REVIEW','artwork_review'=>['policy'=>ArtworkMatcherService::POLICY],'matches'=>[['id'=>'file-'.$id,'side'=>'single','name'=>'PSE-TEST.jpg']]]);
$a=$create('return-a');$b=$create('return-b');$other=$create('unchanged');$otherBefore=$other->fresh()->toJson();
$svc->verify($a,'CORRECT',$user->id);$batch=$svc->batch($user->id);$orders=$batch->payload['orders'];
$check($return->returnItemToReview($batch,$a,$user->id)===1,'Returns the selected item from an untouched queued batch');
$cancelled=$batch->fresh();$check($cancelled->status==='CANCELLED' && $cancelled->payload['orders']===$orders && $cancelled->payload['returned_to_review']['user_id']===$user->id,'Preserves original batch orders and records who returned them');
$check(!$a->fresh('decision')->decision && $a->fresh()->matches===[] && $b->fresh()->matches!==[],'Clears only selected artwork and approval, requiring fresh review');
$svc->batchIndex(true);$check(!$svc->processed($a) && $svc->queue()->isEmpty() && $other->fresh()->toJson()===$otherBefore,'Returned items are unbatched but not ready, and unrelated items are unchanged');
$factory=new App\Http\Controllers\FactoryController();$check($factory->next()->getData(true)['batch']===null,'Cancelled batch is never offered to the factory agent');
$request=Illuminate\Http\Request::create('/factory/status','POST',['status'=>'CLAIMED']);
try{$factory->status($request,$batch);throw new LogicException('Cancelled batch claimed');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'Agent holding a stale queued response cannot claim a cancelled batch');}
try{$return->returnItemToReview($batch,$a,$user->id);throw new LogicException('Repeated return accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'Repeated return cannot replay or create another batch');}
$a->refresh()->update(['matches'=>[['id'=>'replacement','side'=>'single']],'match_status'=>'REVIEW','artwork_review'=>['policy'=>ArtworkMatcherService::POLICY]]);$svc->verify($a->fresh(),'CORRECT',$user->id);$new=$svc->batch($user->id);
$check($new->id!==$batch->id && $batch->fresh()->status==='CANCELLED','Fresh matching and approval can create a new batch without reviving the cancelled snapshot');
foreach(['CLAIMED','DOWNLOADING','PREPARED','HANDOFF_REQUESTED','SENT_TO_RIP','PRINTED','FAILED'] as $state){
 $locked=PrintBatch::create(['batch_id'=>'blocked-'.$state,'status'=>$state,'order_count'=>1,'artwork_file_count'=>1,'total_yards'=>.143,'payload'=>['orders'=>[['line_item_id'=>$a->line_item_id]]],'created_by'=>$user->id]);$before=$locked->fresh()->toJson();$itemBefore=$a->fresh()->toJson();
 try{$return->returnItemToReview($locked,$a,$user->id);throw new LogicException('Processed batch returned');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409 && $locked->fresh()->toJson()===$before && $a->fresh()->toJson()===$itemBefore,'Processed batch remains unchanged: '.$state);}
}
$stale=PrintBatch::create(['batch_id'=>'stale-race','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>$b->line_item_id]]],'created_by'=>$user->id]);PrintBatch::whereKey($stale->id)->update(['status'=>'CLAIMED','claimed_at'=>now()]);
try{$return->returnItemToReview($stale,$b,$user->id);throw new LogicException('Stale state accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409 && $stale->fresh()->status==='CLAIMED','Claim winning the race blocks return under the row lock');}
$route=app('router')->getRoutes()->getByName('batch.return-to-review');$middleware=$route->gatherMiddleware();$check(in_array(App\Http\Middleware\PortalWriteLock::class,$middleware) && in_array(App\Http\Middleware\ReadOnlyDemo::class,$middleware) && in_array('auth',$middleware),'Return action uses authentication, demo write protection and the shared factory/portal write lock');
$check(App\Models\BatchConfirmation::count()===0,'Returning batches never creates a handoff or print-completion confirmation');
echo "$checks batch return checks passed.\n";

// Endpoint and UI safeguards are independent of JavaScript confirmation.
$controller=new App\Http\Controllers\ProductionController($svc);
$demo=User::create(['name'=>'Demo','email'=>'return-demo@example.test','password'=>'unused','role'=>'demo']);
$request=Illuminate\Http\Request::create('/batches/return','POST',['return_confirmed'=>'1']);$request->setUserResolver(fn()=>$demo);
try{$controller->returnBatchToReview($request,$new);throw new LogicException('Demo write accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===403,'Demo accounts cannot return batches through a direct endpoint call');}
$request=Illuminate\Http\Request::create('/batches/return','POST');$request->setUserResolver(fn()=>$user);
try{$controller->returnBatchToReview($request,$new);throw new LogicException('Missing confirmation accepted');}catch(Illuminate\Validation\ValidationException $e){$check(isset($e->errors()['return_confirmed']),'Return requires explicit confirmation server-side');}
view()->share('errors',new Illuminate\Support\ViewErrorBag());auth()->setUser($user);$show=$controller->showBatch($new)->render();$check(str_contains($show,'Return this item to Artwork Review') && str_contains($show,'data-return-batch'),'Untouched queued batch page offers the return action');
$new->update(['status'=>'CLAIMED','claimed_at'=>now()]);$show=$controller->showBatch($new->fresh())->render();$dom=new DOMDocument();@$dom->loadHTML($show,LIBXML_NONET);$check((new DOMXPath($dom))->query('//form[@data-return-batch]')->length===0,'Claimed batch page has no return control');
$check(str_contains($controller->showBatch($batch->fresh())->render(),'Cancelled batch history'),'Cancelled snapshot is clearly labeled as non-printable history');
echo "$checks batch return checks passed.\n";

// The queued artwork card exposes the same guarded action as the batch detail page.
$cardItem=$create('card-return');$cardBatch=PrintBatch::create(['batch_id'=>'card-queued-batch','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>$cardItem->line_item_id]]],'created_by'=>$user->id]);
$index=$svc->batchIndex(true);$check($index[$cardItem->line_item_id]['can_return']===true && $index[$cardItem->line_item_id]['order_count']===1,'Queued artwork cards receive current batch eligibility and full-batch counts');
auth()->setUser($user);$verification=$controller->verification()->render();$dom=new DOMDocument();@$dom->loadHTML($verification,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//details[@data-item-id="'.$cardItem->id.'"]//form[@data-return-batch]')->length===1 && str_contains($verification,'This item only'),'Queued Artwork Verification card provides a selected-item return button');
$cardBatch->update(['claimed_at'=>now()]);$check(!$svc->batchIndex(true)[$cardItem->line_item_id]['can_return'],'A claimed timestamp hides return even if a stale status still says QUEUED');
$verification=$controller->verification()->render();$dom=new DOMDocument();@$dom->loadHTML($verification,LIBXML_NONET);$xpath=new DOMXPath($dom);$check($xpath->query('//details[@data-item-id="'.$cardItem->id.'"]//form[@data-return-batch]')->length===0,'Claimed artwork card does not offer a queue return');
echo "$checks batch return checks passed.\n";

// Selected-item return supersedes the old queue snapshot and preserves all other approvals.
$cardBatch->update(['status'=>'CLAIMED']);
// Prior state-guard fixtures are not part of this polling scenario.
PrintBatch::where('batch_id','blocked-HANDOFF_REQUESTED')->update(['status'=>'PREPARED']);
$selected=$create('partial-selected');$retained=$create('partial-retained');
$retained->update(['series_code'=>'SNP','design_type'=>'double','quantity'=>2,'matches'=>[['id'=>'keep-front','side'=>'front','name'=>'SNP-KEEP-FRONT.jpg'],['id'=>'keep-back','side'=>'back','name'=>'SNP-KEEP-BACK.jpg']]]);
$svc->verify($selected,'CORRECT',$user->id);$svc->verify($retained->fresh(),'CORRECT',$user->id);$partial=$svc->batch($user->id);$original=$partial->payload;
$retained->update(['quantity'=>99]);$retainedBefore=$retained->fresh('decision')->toJson();$oldPoll=$factory->next()->getData(true)['batch'];
$check($return->returnItemToReview($partial,$selected,$user->id)===1,'Selected-item return affects exactly one production line');
$old=$partial->fresh();$replacement=PrintBatch::findOrFail($old->payload['returned_to_review']['replacement_batch_id']);
$check($old->status==='CANCELLED' && $old->payload['orders']===$original['orders'] && $old->payload['returned_to_review']['line_item_ids']===[$selected->line_item_id],'Old queue payload is cancelled with preserved history and an exact selected-item audit');
$expected=array_values(array_filter($original['orders'],fn($order)=>$order['line_item_id']===$retained->line_item_id));
$check($replacement->status==='QUEUED' && $replacement->payload['orders']===$expected && $retained->fresh('decision')->toJson()===$retainedBefore,'Every retained order, artwork and approval snapshot remains unchanged in the queued replacement');
$check($replacement->order_count===1 && $replacement->artwork_file_count===2 && (float)$replacement->total_yards===.572 && $replacement->payload['artwork_file_count']===2,'Replacement counts and material use original locked quantities, not mutable production rows');
$check($selected->fresh()->matches===[] && !$selected->fresh('decision')->decision,'Only the returned item loses artwork selection and approval');
$index=$svc->batchIndex(true);$check(!isset($index[$selected->line_item_id]) && $index[$retained->line_item_id]['id']===$replacement->id && !$svc->ready($selected->fresh()),'Returned item is out of active print/ready queues while retained item stays in print');
$check($oldPoll['id']===$partial->id && $factory->next()->getData(true)['batch']['id']===$replacement->id,'Factory polling offers the replacement without the returned item');
try{$factory->status(Illuminate\Http\Request::create('/factory/status','POST',['status'=>'CLAIMED']),$partial);throw new LogicException('Old payload claimed');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'A factory agent holding the old multi-item response cannot claim its returned item');}
$countBefore=PrintBatch::count();try{$return->returnItemToReview($partial,$selected,$user->id);throw new LogicException('Return replay accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409 && PrintBatch::count()===$countBefore,'Repeated selected-item return cannot create duplicate replacement batches');}
try{$return->returnItemToReview($replacement,$other,$user->id);throw new LogicException('Foreign item returned');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422 && $replacement->fresh()->status==='QUEUED','Items outside this batch cannot be injected into the return request');}
$history=$controller->showBatch($old)->render();$dom=new DOMDocument();@$dom->loadHTML($history,LIBXML_NONET);$xpath=new DOMXPath($dom);
$check($xpath->query('//details[contains(@class,"cancelled-batch-history") and not(@open)]')->length===1 && str_contains($history,'not in the print queue') && str_contains($history,'replacement batch'),'Cancelled contents are collapsed history with a link to the remaining queued items');
// Exercise the full returned-item -> rematch -> human approval -> explicit requeue flow.
$selected->refresh()->update(['sku'=>'2027-DUC-PSE-0001','variant'=>'Pro Sniper Elite / Red','product'=>'Lucky Bags 2027 Duck Camo']);
$fakeDrive=new class extends App\Services\GoogleDriveService {public array $index=[];public function __construct(){}public function artworkIndex():array{return $this->index;}};
$fakeDrive->index=[['kind'=>'series_folder','id'=>'pse','name'=>'PSE','path'=>'GROUP B (Carpet) / PSE'],['kind'=>'artwork','id'=>'corrected-artwork','parent_id'=>'pse','name'=>'PSE-DUC-RED.jpg','path'=>'GROUP B (Carpet) / PSE / PSE-DUC-RED.jpg']];
$app->instance(ProductionService::class,$svc);$beforeRematchCount=PrintBatch::count();$keeper=$retained->fresh('decision')->toJson();
$rematch=(new App\Services\ArtworkRematchService($fakeDrive,new ArtworkMatcherService($fakeDrive)))->rematch();
$check($selected->fresh()->matches[0]['id']==='corrected-artwork' && !$selected->fresh('decision')->decision && !$svc->ready($selected->fresh()),'Returned item rematches to fresh artwork but stays unapproved and out of Ready for Print');
$check($retained->fresh('decision')->toJson()===$keeper && PrintBatch::count()===$beforeRematchCount && $rematch['skipped']>0,'Rematch leaves the retained queued item untouched and creates no batch');
$svc->verify($selected->fresh(),'CORRECT',$user->id);$check($svc->ready($selected->fresh()) && PrintBatch::count()===$beforeRematchCount,'Only fresh human approval makes the returned item ready; approval does not enqueue it');
$requeued=$svc->batch($user->id);$check(count($requeued->payload['orders'])===1 && $requeued->payload['orders'][0]['line_item_id']===$selected->line_item_id && $svc->batchIndex(true)[$retained->line_item_id]['id']===$replacement->id,'Explicit new batch creation queues only the corrected item without moving the retained item');
$countBefore=PrintBatch::count();
$check($return->returnItemToReview($replacement,$retained,$user->id)===1 && $replacement->fresh()->status==='CANCELLED' && PrintBatch::count()===$countBefore,'Returning the last item cancels the empty queue entry without another replacement');
$check(App\Models\BatchConfirmation::count()===0,'Selected-item return never requests handoff or records printing');
$route=app('router')->getRoutes()->getByName('batch.return-item');$check(in_array(App\Http\Middleware\PortalWriteLock::class,$route->gatherMiddleware()) && in_array('auth',$route->gatherMiddleware()),'Selected-item endpoint retains authentication and the factory write lock');
echo "$checks batch return checks passed.\n";

// Cached whole-batch forms are harmless after the selected-only workflow change.
$beforeLegacy=PrintBatch::all()->toJson();$request=Illuminate\Http\Request::create('/batches/return','POST',['return_confirmed'=>'1']);$request->setUserResolver(fn()=>$user);$response=$controller->returnBatchToReview($request,$requeued);
$check($response->getStatusCode()===302 && PrintBatch::all()->toJson()===$beforeLegacy && str_contains(session('error'),'Whole-batch return is no longer available'),'A stale whole-batch POST cannot return all items');
echo "$checks batch return checks passed.\n";

$legacyReturn=$create('pre-schema-return');$svc->verify($legacyReturn,'CORRECT',$user->id);$legacyBatch=$svc->batch($user->id);
Illuminate\Support\Facades\Schema::table('production_items',fn($table)=>$table->dropColumn('artwork_review'));
$check($return->returnItemToReview($legacyBatch,$legacyReturn,$user->id)===1 && $legacyReturn->fresh()->matches===[] && !$legacyReturn->fresh('decision')->decision,'Selected-item return and approval invalidation work before the optional review column is deployed');
(require __DIR__.'/../database/migrations/2026_11_01_000005_add_artwork_review.php')->up();
echo "$checks batch return checks passed.\n";
