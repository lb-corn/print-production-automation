<?php
// Run with: php tests/bulk-review.php. Uses an isolated in-memory database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Illuminate\Support\Facades\DB::purge('sqlite');
foreach (glob(__DIR__.'/../database/migrations/*.php') as $file) (require $file)->up();
$user = App\Models\User::create(['name'=>'Test','email'=>'ui-test@example.test','password'=>'unused']);
// CI and these tests must not require live Shopify credentials or a Google private key.
$app->instance(App\Services\ShopifyService::class, new class extends App\Services\ShopifyService { public function __construct() {} });
$app->instance(App\Services\GoogleDriveService::class, new class extends App\Services\GoogleDriveService { public function __construct() {} });
$controller = $app->make(App\Http\Controllers\ProductionController::class);
$svc = $app->make(App\Services\ProductionService::class);
$create = fn($id, $extra=[]) => App\Models\ProductionItem::create(array_merge([
    'line_item_id'=>$id, 'product'=>'Test artwork', 'design_type'=>'single',
    'series_code'=>'PSE', 'matches'=>[['id'=>'drive-'.$id, 'side'=>'single']],
], $extra));
$a=$create('a'); $b=$create('b'); $custom=$create('custom',['is_custom'=>true]); $missing=$create('missing',['matches'=>[]]);
$submit = function($ids, $decision) use ($controller, $user) {
    $request = Illuminate\Http\Request::create('/production/bulk-verify', 'POST', ['items'=>$ids,'decision'=>$decision]);
    $request->setUserResolver(fn()=>$user);
    $controller->bulkVerify($request);
};
$check = function($condition, $label) { if (!$condition) throw new RuntimeException($label); echo "PASS: $label\n"; };
$submit([$a->id,$b->id],'CORRECT');
$check($svc->ready($a->fresh('decision')) && $svc->ready($b->fresh('decision')), 'Bulk approval saves exact artwork decisions');
$submit([$a->id,$b->id],'WRONG');
$check(App\Models\VerificationDecision::where('decision','WRONG')->count()===2, 'Bulk rejection updates all selected orders');
foreach ([$custom, $missing] as $invalid) {
    try { $submit([$a->id,$invalid->id],'CORRECT'); throw new RuntimeException('Invalid selection was accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode()===422, 'Ineligible selection is rejected'); }
    $check($a->fresh('decision')->decision->decision==='WRONG', 'Failed bulk action leaves existing decisions unchanged');
}
foreach ([[[], 'CORRECT'], [[$a->id,$a->id], 'CORRECT'], [[$a->id], 'INVALID'], [[999999], 'CORRECT']] as [$ids,$decision]) {
    try { $submit($ids,$decision); throw new RuntimeException('Invalid input accepted'); }
    catch (Illuminate\Validation\ValidationException $e) { $check(true, 'Invalid bulk input fails validation'); }
}
// Lifecycle regression coverage; same isolated in-memory database as above.
$submit([$a->id,$b->id],'CORRECT');
$batch=$svc->batch($user->id);
$check($svc->queue()->isEmpty(), 'Batched orders immediately leave the ready-for-print queue');
try { $svc->batch($user->id); throw new RuntimeException('Duplicate batch accepted'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode()===422, 'Repeated print request cannot duplicate orders'); }
$batch->update(['status'=>'PRINTED','completed_at'=>now()]);
$check($svc->queue()->isEmpty(), 'Completed orders stay out of print queue');
$shopify=new class extends App\Services\ShopifyService {
    public array $rows=[];
    public function __construct() {}
    public function getProductionBacklog(int $first=50): array { return ['items'=>$this->rows,'item_count'=>count($this->rows),'order_count'=>1]; }
};
$drive=new class extends App\Services\GoogleDriveService {
    public int $calls=0;
    public function __construct() {}
    public function artworkIndex(): array { $this->calls++; return []; }
};
$matcher=new class extends App\Services\ArtworkMatcherService {
    public int $calls=0;
    public function __construct() {}
    public function match(array $row, ?array $index=null): array { $this->calls++; return ['matches'=>[],'match_status'=>'MISSING']; }
};
$app->instance(App\Services\ProductionService::class,$svc);
$shopify->rows=[['line_item_id'=>'a','product'=>'Changed after print','quantity'=>99],['line_item_id'=>'custom','product'=>'Custom updated','quantity'=>2]];
$sync=new App\Services\BacklogSyncService($shopify,$drive,$matcher);
$result=$sync->sync();
$check($result['processed_skipped']===1 && $a->fresh()->quantity===1 && $a->fresh()->product==='Test artwork', 'Resync skips completed items without changing locked order details');
$check($drive->calls===0 && $matcher->calls===0, 'Resync does not download or rematch artwork for known items');
$shopify->rows[]=['line_item_id'=>'new','product'=>'New order','quantity'=>1];
$sync->sync(); $again=$sync->sync();
$check(App\Models\ProductionItem::where('line_item_id','new')->count()===1 && $again['added']===0, 'Repeated sync imports new items once');
$check($a->fresh('decision')->decision->decision==='CORRECT', 'Sync preserves saved artwork decisions');
$batch->update(['status'=>'FAILED']);
$check($svc->queue()->isEmpty(), 'Failed batches require attention instead of silently requeuing');
try { $svc->verify($a->fresh(),'WRONG',$user->id); throw new RuntimeException('Processed order changed'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode()===422, 'Processed order cannot be reverified'); }
view()->share('errors',new Illuminate\Support\ViewErrorBag);
$view=(new App\Http\Controllers\ProductionController($svc,$sync))->verification();
$data=$view->getData();
$check($data['reviewSummary']['failed']===2 && $data['reviewSummary']['pending']===3, 'Summary separates processed and pending states');
$html=$view->render();
$check(str_contains($html,'data-workflow="failed"') && str_contains($html,$batch->batch_id), 'Review renders current batch stage and batch link');
// Material arithmetic regression coverage.
$materialItem = fn($id,$series,$type,$quantity) => new App\Models\ProductionItem([
    'line_item_id'=>$id,'product'=>'Material test','series_code'=>$series,'design_type'=>$type,'quantity'=>$quantity,
]);
$singleSummary=$svc->summary(collect([$materialItem('single','PSE','single',2)]));
$check($singleSummary['printable_yards']===0.286 && $singleSummary['nonprint_yards']===0.286 && $singleSummary['total_yards']===0.572, 'Two single-sided sets: 0.286 printed + 0.286 plain = 0.572 yd');
$doubleSummary=$svc->summary(collect([$materialItem('double','SNP','double',3)]));
$check($doubleSummary['total_sets']===3 && $doubleSummary['printable_sides']===6 && $doubleSummary['artwork_files']===2 && $doubleSummary['total_yards']===0.858 && $doubleSummary['nonprint_yards']===0.0, 'Three double-sided sets: 6 printed side-sets, 2 artwork files, 0.858 yd');
$mixed=$svc->summary(collect([$materialItem('one','PSE','single',2),$materialItem('two','SNP','double',3)]));
$check($mixed['total_yards']===1.43 && $mixed['printable_materials']['Rave 9']['sides']===5 && $mixed['nonprint_materials']['Viewmont 6']['sides']===2, 'Mixed quantities aggregate shared material and separate backing');
$empty=$svc->summary(collect());
$check($empty['total_yards']===0.0 && $empty['total_sets']===0, 'Empty queue uses zero material');
foreach ([$materialItem('bad','UNKNOWN','single',1),$materialItem('badtype','PSE','invalid',1),$materialItem('badqty','PSE','single',0)] as $bad) {
 try { $svc->summary(collect([$bad])); throw new RuntimeException('Partial material total accepted'); }
 catch (LogicException $e) { $check(true,'Invalid material input cannot silently understate fabric'); }
}
$ajaxItem=$create('ajax');
$ajaxRequest=Illuminate\Http\Request::create('/production/'.$ajaxItem->id.'/verify','POST',['decision'=>'CORRECT'],[],[],['HTTP_ACCEPT'=>'application/json']);
$ajaxRequest->setUserResolver(fn()=>$user);
$json=$controller->verify($ajaxRequest,$ajaxItem)->getData(true);
$check($json['items'][0]['workflow']==='approved' && $json['items'][0]['ready']===true && isset($json['summary']['approved']), 'Individual AJAX save returns authoritative state and refreshed counts');
$ajaxRequest=Illuminate\Http\Request::create('/production/bulk-verify','POST',['items'=>[$ajaxItem->id],'decision'=>'WRONG'],[],[],['HTTP_ACCEPT'=>'application/json']);
$ajaxRequest->setUserResolver(fn()=>$user);
$json=$controller->bulkVerify($ajaxRequest)->getData(true);
$check($json['items'][0]['workflow']==='rejected' && $json['items'][0]['ready']===false, 'Bulk AJAX save returns saved rejection without redirect');
// Named, double-confirmed handoff and physical completion.
$signedItem=$create('signed');$svc->verify($signedItem,'CORRECT',$user->id);$signedBatch=$svc->batch($user->id);$signedBatch->update(['status'=>'PREPARED']);
$signData=['signer_name'=>'Test Operator','confirmation_phrase'=>'HANDOFF','review_confirmed'=>'1','confirmed_order_count'=>$signedBatch->order_count,'confirmed_artwork_file_count'=>$signedBatch->artwork_file_count,'payload_sha256'=>hash('sha256',json_encode($signedBatch->payload))];
$makeRequest=function($data) use($user) {$request=Illuminate\Http\Request::create('/confirm','POST',$data);$request->setUserResolver(fn()=>$user);return $request;};
foreach (['signer_name','confirmation_phrase','review_confirmed'] as $required) {
    $invalid=$signData;unset($invalid[$required]);
    try {$controller->requestEdgePrint($makeRequest($invalid),$signedBatch);throw new RuntimeException('Unsigned handoff accepted');}
    catch(Illuminate\Validation\ValidationException $e){$check(true,'Handoff requires '.$required);}
}
$invalid=$signData;$invalid['confirmed_order_count']=999;
try{$controller->requestEdgePrint($makeRequest($invalid),$signedBatch);throw new RuntimeException('Stale counts accepted');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'Handoff rejects changed quantities');}
$controller->requestEdgePrint($makeRequest($signData),$signedBatch);
$record=$signedBatch->confirmations()->first();
$check($signedBatch->fresh()->status==='HANDOFF_REQUESTED' && $record->signer_name==='Test Operator' && $record->user_id===$user->id && $record->confirmed_at!==null,'Handoff records operator account, name, time and counts');
$factory=new App\Http\Controllers\FactoryController;
try{$factory->status($makeRequest(['status'=>'PRINTED']),$signedBatch);throw new RuntimeException('Agent marked physical completion');}
catch(Illuminate\Validation\ValidationException $e){$check(true,'Factory API cannot mark physical print completion');}
$factory->status($makeRequest(['status'=>'SENT_TO_RIP']),$signedBatch);
$printData=$signData;$printData['confirmation_phrase']='PRINTED';
$controller->markPrinted($makeRequest($printData),$signedBatch);
$check($signedBatch->fresh()->status==='PRINTED' && $signedBatch->confirmations()->count()===2,'Physical print completion has a separate signed record');
try{$factory->status($makeRequest(['status'=>'CLAIMED']),$signedBatch);throw new RuntimeException('Completed state regressed');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'Factory API prevents completed batch state regression');}
$claimBatch=App\Models\PrintBatch::create(['batch_id'=>'CLAIM-TEST','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'total_yards'=>0.286,'payload'=>[]]);
$factory->status($makeRequest(['status'=>'CLAIMED']),$claimBatch);
try{$factory->status($makeRequest(['status'=>'CLAIMED']),$claimBatch);throw new RuntimeException('Duplicate claim accepted');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===409,'Second agent cannot claim a batch twice');}
$middleware=new App\Http\Middleware\FactoryKey;
config(['services.factory.api_key'=>'']);
try{$middleware->handle(Illuminate\Http\Request::create('/api/factory/batches/next'),fn()=>true);throw new RuntimeException('Empty key accepted');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===503,'Factory API fails closed when server key is blank');}
config(['services.factory.api_key'=>str_repeat('a',64)]);
try{$middleware->handle(Illuminate\Http\Request::create('/api/factory/batches/next'),fn()=>true);throw new RuntimeException('Missing bearer accepted');}
catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===401,'Factory API rejects unauthenticated requests');}
$authorized=Illuminate\Http\Request::create('/api/factory/batches/next','GET',[],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('a',64)]);
$check($middleware->handle($authorized,fn()=>true)===true,'Configured factory bearer token is accepted');
