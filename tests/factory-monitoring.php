<?php
// SQLite memory + fake telemetry only. Never connects to a PC, Drive, printer or live database.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','services.factory.api_key'=>str_repeat('a',64)]);
Illuminate\Support\Facades\DB::purge('sqlite');
foreach(glob(__DIR__.'/../database/migrations/*.php') as $file)(require $file)->up();
use App\Services\FactoryMonitoringService;
use App\Models\{FactoryAgent,PrintBatch,User};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
$checks=0;$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;echo 'PASS: '.$message.PHP_EOL;};
$reject=function(callable $action,int $code)use($check){try{$action();throw new LogicException('Request was accepted');}catch(Illuminate\Validation\ValidationException $e){$check($code===422,'Invalid telemetry is rejected without storing values');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===$code,'Rejected unsafe request with HTTP '.$code);}};
$monitor=new FactoryMonitoringService();$controller=new App\Http\Controllers\FactoryMonitoringController();
$auth=new App\Http\Middleware\FactoryKey();
foreach(['',str_repeat('b',64)] as $key){$r=Request::create('/api/factory/health','GET',[],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$key]);$reject(fn()=>$auth->handle($r,fn()=>true),401);}
$r=Request::create('/api/factory/health','GET',[],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('a',64)]);
$check($auth->handle($r,fn()=>true)===true,'Valid existing Factory API key is accepted');
$batch=PrintBatch::create(['batch_id'=>'MONITORING-SAFETY','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>['orders'=>[]]]);$before=$batch->fresh()->toJson();
$health=$controller->health($monitor)->getData(true);
$check($health['authenticated']===true && $health['monitoring_ready']===true && FactoryAgent::count()===0 && $batch->fresh()->toJson()===$before,'Health validates connectivity without heartbeat, batch claim, download or status side effects');
$check($monitor->connection()['state']==='never_connected','New installation starts as Never connected');
$sample=['agent_id'=>'67a937b6-a8c2-4fbc-94ad-a03392572d17','agent_version'=>'1.2.2','source_sha256'=>str_repeat('b',64),'python_version'=>'3.13.0','windows_version'=>'10.0.26100','sampled_at_ms'=>now()->getTimestampMs(),'process_status'=>'running','workspace_exists'=>true,'hot_folder_exists'=>true,'credentials_present'=>true,'batch_id'=>null,'stage'=>'idle'];
$monitor->accept($sample);$snapshot=$monitor->snapshot();
$check($snapshot['agents'][0]['online']===true && $monitor->connection()['state']==='online','Accepted heartbeat marks the reporting PC Online using server receipt time');
$check($snapshot['agents'][0]['telemetry']['agent_version']==='1.2.2','v1.2.2 version reports and existing batch API remain compatible');
$beforeReplay=FactoryAgent::first()->last_seen_at->toIso8601String();$reject(fn()=>$monitor->accept($sample),409);
$check(FactoryAgent::first()->last_seen_at->toIso8601String()===$beforeReplay,'Replayed telemetry cannot refresh last-seen time');
$reject(fn()=>$monitor->accept([...$sample,'sampled_at_ms'=>now()->getTimestampMs()-61000]),422);
$reject(fn()=>$monitor->accept([...$sample,'sampled_at_ms'=>now()->getTimestampMs()+16000]),422);
$reject(fn()=>$monitor->accept([...$sample,'api_key'=>'do-not-store-secret']),422);
$reject(fn()=>$monitor->accept([...$sample,'agent_version'=>'secret-token']),422);
$reject(fn()=>$monitor->accept([...$sample,'stage'=>'PRINTED']),422);
$reject(fn()=>$monitor->accept([...$sample,'diagnostics'=>['private_key'=>'do-not-store-secret']]),422);
$check(!str_contains(FactoryAgent::all()->toJson(),'do-not-store-secret'),'Secrets and arbitrary fields are never persisted in telemetry or activity');
$diag=['checked_at_ms'=>now()->getTimestampMs(),'workspace_writable'=>true,'hot_folder_writable'=>true,'defender_enabled'=>true,'defender_realtime'=>true,'defender_scan'=>true,'drive_connected'=>true,'error_code'=>null];
$sample['sampled_at_ms']+=10;$monitor->accept([...$sample,'diagnostics'=>$diag,'last_failure'=>['kind'=>'poll','at_ms'=>now()->getTimestampMs(),'error_code'=>'api_unreachable']]);
$check($monitor->snapshot()['agents'][0]['diagnostics_fresh']===true,'Explicit diagnostic evidence is distinct from folder existence');
$sample['sampled_at_ms']+=10;$monitor->accept([...$sample,'diagnostics'=>[...$diag,'checked_at_ms'=>$diag['checked_at_ms']-1000,'drive_connected'=>false]]);
$check(FactoryAgent::first()->telemetry['diagnostics']['drive_connected']===true,'Periodic reports cannot overwrite a newer explicit diagnostic with cached evidence');
$stored=FactoryAgent::first()->telemetry;
$check(!isset($stored['printer_ready']) && !isset($stored['auto_print_off']) && !isset($stored['epson_registered']),'No heartbeat infers printer readiness, registration or Auto Print from folders');
$other=[...$sample,'agent_id'=>'9de7eb35-89a0-4e49-92f7-11504e4e0395'];$monitor->accept($other);
$check(FactoryAgent::count()===2 && $monitor->connection()['online']===2,'Distinct agent identifiers are tracked independently');
$public=$monitor->connection();$check(array_keys($public)===['state','online','expires_in_seconds'],'Shared header status contains no PC identifiers, hashes, paths, diagnostics or error details');
Carbon::setTestNow(now()->addSeconds(121));
$check($monitor->connection()['state']==='offline' && !$monitor->snapshot()['agents'][0]['diagnostics_fresh'],'Offline agents cannot display stale diagnostic results as current');Carbon::setTestNow();
config(['services.factory.offline_after_seconds'=>45]);FactoryAgent::query()->update(['last_seen_at'=>now()->subSeconds(46)]);
$check($monitor->connection()['state']==='offline','Offline threshold is configurable');config(['services.factory.offline_after_seconds'=>120]);
$admin=new User(['name'=>'Admin','email'=>'agent-admin@example.test','role'=>'admin']);$admin->id=1;
$normal=new User(['name'=>'Operator','email'=>'agent-operator@example.test','role'=>'operator']);$normal->id=2;
$request=Request::create('/admin/factory-agent/status');$request->setUserResolver(fn()=>$normal);$reject(fn()=>$controller->status($request,$monitor),403);
$request->setUserResolver(fn()=>$admin);$check($controller->status($request,$monitor)->headers->get('Cache-Control')==='no-store, private','Detailed reports require admin access and are not cacheable');
foreach(['factory.health','factory.heartbeat'] as $name){$route=app('router')->getRoutes()->getByName($name);$m=$route->gatherMiddleware();$check(in_array('factory.key',$m) && !in_array(App\Http\Middleware\PortalWriteLock::class,$m) && count(array_filter($m,fn($v)=>str_starts_with($v,'throttle:')))===1,$name.' is authenticated, rate-limited and independent of the production write lock');}
$limiter=new Illuminate\Cache\RateLimiter(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
foreach(['factory-health'=>30,'factory-heartbeat'=>60] as $name=>$maximum){
 $limiter->for($name,app(Illuminate\Cache\RateLimiter::class)->limiter($name));$throttle=new Illuminate\Routing\Middleware\ThrottleRequests($limiter);
 $rateRequest=Request::create('/api/factory/health','GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
 for($i=0;$i<$maximum;$i++)$throttle->handle($rateRequest,fn()=>response('ok'),$name);
 $reject(fn()=>$throttle->handle($rateRequest,fn()=>response('unexpected'),$name),429);
}
$check($batch->fresh()->toJson()===$before,'Monitoring never changes queued batches or print confirmations');
$tooLarge=Request::create('/api/factory/heartbeat','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],str_repeat('x',8193));$reject(fn()=>$controller->heartbeat($tooLarge,$monitor),413);
$check(hash_file('sha256',resource_path('factory-agent-test-connectivity.ps1'))===hash_file('sha256',base_path('automation/test-connectivity.ps1')),'Downloadable v1.2.2 health helper matches the reviewed agent helper');
// Render the actual admin page with disposable data for responsive/browser checks.
auth()->setUser($admin);view()->share('errors',new Illuminate\Support\ViewErrorBag());$app->instance('request',Request::create('/admin/settings?section=agent'));
$html=view('admin.settings',['section'=>'agent','monitoring'=>$monitor->snapshot()])->render();
$check(str_contains($html,'Agent installation &amp; health') && str_contains($html,'data-factory-connection') && str_contains($html,'test-connectivity.ps1'),'Admin page includes installation guidance and the shared connection badge');
if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/agent-preview.html',$html);
Illuminate\Support\Facades\Schema::drop('factory_agents');
$check(!$controller->health($monitor)->getData(true)['monitoring_ready'] && $monitor->connection()['state']==='unavailable','Health remains read-only and usable before the monitoring migration');
echo "$checks factory monitoring checks passed.\n";
