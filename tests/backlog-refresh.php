<?php
// Fake sync and empty view fixtures only: no database, Shopify, Drive or print requests.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();require __DIR__.'/material-fixture.php';
require __DIR__.'/summary-fixture.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
config(['session.driver'=>'array','logging.default'=>'backlog_test','logging.channels.backlog_test'=>['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class]]);
$check=function($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;};
$sync=new class extends App\Services\BacklogSyncService {
 public bool $fail=false;
 public function __construct(){}
 public function sync():array{if($this->fail)throw new RuntimeException('Test failure');return ['shopify_items'=>2,'added'=>1,'updated'=>1,'unchanged'=>0,'matched'=>2,'missing'=>0,'ambiguous'=>0,'processed_skipped'=>0];}
};
$svc=new class extends App\Services\ProductionService { public function workspaceStats(bool $refresh=false):array{return ['ready'=>0,'in_print'=>0,'completed'=>0];} };
$app->instance(App\Services\BacklogSyncService::class,$sync);$app->instance(App\Services\ProductionService::class,$svc);
$controller=new App\Http\Controllers\ProductionController($svc);
foreach([['production','production'],[null,'dashboard'],['https://example.invalid','dashboard']] as [$returnTo,$destination]) {
 $request=Illuminate\Http\Request::create('/production/sync-backlog','POST',$returnTo===null?[]:['return_to'=>$returnTo]);$app->instance('request',$request);
 $sync->fail=false;session()->flush();$response=$controller->syncBacklog();
 $check($response->isRedirect(route($destination)) && str_contains(session('ok'),'1 added'),'Successful sync returns to the requested local screen with counts: '.$destination);
 $sync->fail=true;session()->flush();$response=$controller->syncBacklog();
 $check($response->isRedirect(route($destination)) && str_contains(session('error'),'No changes were saved'),'Failed sync returns to the same local screen with feedback: '.$destination);
}
$app->instance('request',Illuminate\Http\Request::create('/production'));
view()->share('errors',new Illuminate\Support\ViewErrorBag());
$user=new App\Models\User(['name'=>'Test operator','email'=>'operator@example.test','role'=>'manager']);$user->setRelation('accessRole',new App\Models\Role(['name'=>'Manager','permissions'=>App\Services\AccessCatalog::defaults()['manager'][1]]));auth()->setUser($user);
$data=['errors'=>new Illuminate\Support\ViewErrorBag(),'items'=>collect(),'reviewSummary'=>array_fill_keys(['approved','rejected','pending','in_print','completed','failed'],0)];
$html=view('production.verification',$data)->render();
$check(!str_contains($html,'data-backlog-form') && str_contains($html,'#order-sync-status') && str_contains($html,'data-rematch-form'),'Operators use Overview for order sync and keep the artwork rematch action');
if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/backlog-preview.html',$html);
$user->role='demo';$user->setRelation('accessRole',new App\Models\Role(['name'=>'Demo','permissions'=>App\Services\AccessCatalog::defaults()['demo'][1]]));$demo=view('production.verification',$data)->render();
$check(!str_contains($demo,'data-backlog-form') && !str_contains($demo,'data-rematch-form'),'Read-only demo users are not offered either refresh action');
echo "Backlog refresh checks passed.\n";
