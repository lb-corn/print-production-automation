<?php
// Isolated limiter/session; no database, imports or remote calls.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
config(['session.driver'=>'array']);
use Illuminate\Cache\{RateLimiter,Repository,ArrayStore};
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Http\Request;
$limiter=new RateLimiter(new Repository(new ArrayStore));
foreach(['order-sync','order-sync-status','backing-references'] as $name)$limiter->for($name,app(RateLimiter::class)->limiter($name));
$middleware=new ThrottleRequests($limiter);$imports=0;
$request=function($id=1,$json=false)use($app){$r=Request::create('/orders/sync','POST',['area'=>'apparel'],[],[],$json?['HTTP_ACCEPT'=>'application/json']:[]);$u=new App\Models\User;$u->id=$id;$r->setUserResolver(fn()=>$u);$r->setLaravelSession($app['session']->driver());return $r;};
$check=function($value,$message){if(!$value)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;};
// Reproduce activity in the old shared numeric bucket before the first sync click.
for($i=0;$i<30;$i++)$middleware->handle($request(),fn()=>response('background'),'300','1');
for($i=0;$i<30;$i++)$middleware->handle($request(),fn()=>response('status'),'order-sync-status');
for($i=0;$i<30;$i++)$middleware->handle($request(),fn()=>response('swatch'),'backing-references');
$handle=function($r)use($middleware,&$imports){try{return $middleware->handle($r,function()use(&$imports){$imports++;return response('synced');},'order-sync');}catch(Illuminate\Http\Exceptions\HttpResponseException $e){return $e->getResponse();}};
for($i=0;$i<3;$i++)$check($handle($request())->getStatusCode()===200,'Background requests do not consume manual sync allowance '.($i+1));
App\Services\PartnerContext::get()->partner='all';
$limited=$handle($request());
$check($limited->isRedirect(route('dashboard',['area'=>'apparel','partner'=>'all'])) && $imports===3,'Limited browser requests return to Overview without running imports');
$check(str_contains(session('warning'),'seconds') && (int)$limited->headers->get('Retry-After')>0,'Overview notice includes the retry duration');
$limited=$handle($request(1,true));
$check($limited->getStatusCode()===429 && isset($limited->getData(true)['message']) && $imports===3,'JSON requests preserve 429 and actionable retry information');
$check($handle($request(2))->getStatusCode()===200,'Other users retain their own sync allowance');
foreach(['orders.sync','apparel.orders.sync'] as $name){$route=app('router')->getRoutes()->getByName($name);$check(in_array('throttle:order-sync',$route->gatherMiddleware()),$name.' uses the dedicated manual sync limiter');}
$check(in_array('throttle:order-sync-status',app('router')->getRoutes()->getByName('orders.sync-status')->gatherMiddleware()),'Status polling has its own limiter');
