<?php
// Isolated database and faked integrations; never touches live queue entries.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array']);Illuminate\Support\Facades\DB::purge('sqlite');
foreach(glob(__DIR__.'/../database/migrations/*.php') as $file)(require $file)->up();
use App\Models\{ProductionItem,PrintBatch,User,VerificationDecision};
use App\Services\{ProductionService,BatchReturnService,ArtworkMatcherService};
$checks=0;$check=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;$checks++;};
$user=User::create(['name'=>'Operator','email'=>'return@example.test','password'=>'unused','role'=>'operator']);$svc=new ProductionService();$return=new BatchReturnService();
$create=fn($id)=>ProductionItem::create(['line_item_id'=>$id,'product'=>'Test artwork','variant'=>'PSE','quantity'=>1,'series_code'=>'PSE','design_type'=>'single','match_status'=>'REVIEW','artwork_review'=>['policy'=>ArtworkMatcherService::POLICY],'matches'=>[['id'=>'file-'.$id,'side'=>'single','name'=>'PSE-TEST.jpg']]]);
$a=$create('return-a');$b=$create('return-b');$other=$create('unchanged');$otherBefore=$other->fresh()->toJson();
$svc->verify($a,'CORRECT',$user->id);$svc->verify($b,'CORRECT',$user->id);$batch=$svc->batch($user->id);$orders=$batch->payload['orders'];
$check($return->returnToReview($batch,$user->id)===2,'Returns every item from one untouched queued batch');
$cancelled=$batch->fresh();$check($cancelled->status==='CANCELLED' && $cancelled->payload['orders']===$orders && $cancelled->payload['returned_to_review']['user_id']===$user->id,'Preserves original batch orders and records who returned them');
$check(!$a->fresh('decision')->decision && !$b->fresh('decision')->decision && $a->fresh()->matches===[] && $b->fresh()->matches===[],'Clears selections and approvals, requiring rematch and fresh approval');
$svc->batchIndex(true);$check(!$svc->processed($a) && $svc->queue()->isEmpty() && $other->fresh()->toJson()===$otherBefore,'Returned items are unbatched but not ready, and unrelated items are unchanged');
$factory=new App\Http\Controllers\FactoryController();$check($factory->next()->getData(true)['batch']===null,'Cancelled batch is never offered to the factory agent');
$request=Illuminate\Http\Request::create('/factory/status','POST',['status'=>'CLAIMED']);
try{$factory->status($request,$batch);throw new LogicException('Cancelled batch claimed');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'Agent holding a stale queued response cannot claim a cancelled batch');}
try{$return->returnToReview($batch,$user->id);throw new LogicException('Repeated return accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'Repeated return cannot replay or create another batch');}
$a->refresh()->update(['matches'=>[['id'=>'replacement','side'=>'single']],'match_status'=>'REVIEW','artwork_review'=>['policy'=>ArtworkMatcherService::POLICY]]);$svc->verify($a->fresh(),'CORRECT',$user->id);$new=$svc->batch($user->id);
$check($new->id!==$batch->id && $batch->fresh()->status==='CANCELLED','Fresh matching and approval can create a new batch without reviving the cancelled snapshot');
foreach(['CLAIMED','DOWNLOADING','PREPARED','HANDOFF_REQUESTED','SENT_TO_RIP','PRINTED','FAILED'] as $state){
 $locked=PrintBatch::create(['batch_id'=>'blocked-'.$state,'status'=>$state,'order_count'=>1,'artwork_file_count'=>1,'total_yards'=>.143,'payload'=>['orders'=>[['line_item_id'=>$a->line_item_id]]],'created_by'=>$user->id]);$before=$locked->fresh()->toJson();$itemBefore=$a->fresh()->toJson();
 try{$return->returnToReview($locked,$user->id);throw new LogicException('Processed batch returned');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409 && $locked->fresh()->toJson()===$before && $a->fresh()->toJson()===$itemBefore,'Processed batch remains unchanged: '.$state);}
}
$stale=PrintBatch::create(['batch_id'=>'stale-race','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[['line_item_id'=>$b->line_item_id]]],'created_by'=>$user->id]);PrintBatch::whereKey($stale->id)->update(['status'=>'CLAIMED','claimed_at'=>now()]);
try{$return->returnToReview($stale,$user->id);throw new LogicException('Stale state accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409 && $stale->fresh()->status==='CLAIMED','Claim winning the race blocks return under the row lock');}
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
view()->share('errors',new Illuminate\Support\ViewErrorBag());auth()->setUser($user);$show=$controller->showBatch($new)->render();$check(str_contains($show,'Return to Artwork Review') && str_contains($show,'data-return-batch'),'Untouched queued batch page offers the return action');
$new->update(['status'=>'CLAIMED','claimed_at'=>now()]);$show=$controller->showBatch($new->fresh())->render();$check(!str_contains($show,'data-return-batch'),'Claimed batch page has no return control');
$check(str_contains($controller->showBatch($batch->fresh())->render(),'This batch is cancelled and cannot be printed'),'Cancelled snapshot is clearly labeled as non-printable history');
echo "$checks batch return checks passed.\n";
