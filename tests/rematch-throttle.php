<?php
// Isolated cache/session test; never opens the production database or integration clients.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e):void { fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); });
config(['session.driver'=>'array']);
use Illuminate\Cache\{RateLimiter,Repository,ArrayStore};
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Http\Request;
$definition=app(RateLimiter::class)->limiter('artwork-rematch');
$limiter=new RateLimiter(new Repository(new ArrayStore));
$limiter->for('artwork-rematch',$definition);
$middleware=new ThrottleRequests($limiter);
$calls=0;
$next=function()use(&$calls){$calls++;return response('rematch permitted');};
$request=function(int $userId,bool $json=false)use($app){
 $r=Request::create('/production/rematch','POST',[],[],[],$json?['HTTP_ACCEPT'=>'application/json']:[]);
 $user=new App\Models\User;$user->id=$userId;
 $r->setUserResolver(fn()=>$user);$r->setLaravelSession($app['session']->driver());return $r;
};
$handle=function($request)use($middleware,$next){try{return $middleware->handle($request,$next,'artwork-rematch');}catch(Illuminate\Http\Exceptions\HttpResponseException $e){return $e->getResponse();}};
$check=function($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;};
for($i=0;$i<3;$i++) $check($handle($request(1))->getStatusCode()===200,'Allowed rematch attempt '.($i+1));
$response=$handle($request(1));
$check($response->isRedirect(route('production')) && $calls===3,'Limited browser request returns to Artwork Verification without calling rematch');
$check(str_contains(session('error'),'seconds') && (int)$response->headers->get('Retry-After')>0,'Friendly notice and retry duration are preserved');
$response=$handle($request(1,true));
$check($response->getStatusCode()===429 && isset($response->getData(true)['message']) && $calls===3,'JSON callers retain 429 and actionable retry information');
$check($handle($request(2))->getStatusCode()===200,'Operators have separate rematch limits');
$route=app('router')->getRoutes()->getByName('production.rematch');
$check(in_array('throttle:artwork-rematch',$route->gatherMiddleware()),'Rematch route uses the dedicated limiter');

$limiter->for('catalog-sync',app(RateLimiter::class)->limiter('catalog-sync'));
$syncCalls=0;$syncNext=function()use(&$syncCalls){$syncCalls++;return response('catalog permitted');};
$syncHandle=function($request)use($middleware,$syncNext){try{return $middleware->handle($request,$syncNext,'catalog-sync');}catch(Illuminate\Http\Exceptions\HttpResponseException $e){return $e->getResponse();}};
$syncRequest=function(int $id,bool $json=false)use($request){$r=$request($id,$json);$r->server->set('REQUEST_URI','/admin/data-sync');return $r;};
for($i=0;$i<3;$i++)$check($syncHandle($syncRequest(1))->getStatusCode()===200,'Catalog sync allowance is independent of rematch attempt '.($i+1));
$response=$syncHandle($syncRequest(1));
$check($response->isRedirect(route('admin.settings',['section'=>'sync'])) && $syncCalls===3,'Limited catalog browser POST redirects to Data sync without running integrations');
$check(str_contains(session('error'),'Catalog sync limit reached') && str_contains(session('error'),'seconds') && (int)$response->headers->get('Retry-After')>0,'Catalog throttle includes a friendly notice and retry duration');
$response=$syncHandle($syncRequest(1,true));
$check($response->getStatusCode()===429 && str_contains($response->getData(true)['message'],'Catalog sync') && $syncCalls===3,'Catalog JSON requests retain actionable 429 responses without running sync');
$check($syncHandle($syncRequest(2))->getStatusCode()===200,'Catalog limits are isolated per administrator');
$route=app('router')->getRoutes()->getByName('admin.data-sync');
$check(in_array('throttle:catalog-sync',$route->gatherMiddleware()) && !in_array('throttle:3,1',$route->gatherMiddleware()),'Data sync route uses its dedicated limiter instead of the generic error page');
