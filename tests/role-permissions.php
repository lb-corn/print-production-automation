<?php
// Isolated local validation. Never writes to the configured application database.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
foreach(glob(__DIR__.'/../database/migrations/*.php') as $file){
 if(str_contains($file,'create_configurable_roles')) Illuminate\Support\Facades\DB::table('users')->insert(['name'=>'Legacy','email'=>'legacy@example.test','password'=>'unused','role'=>'operator']);
 (require $file)->up();
}
use App\Models\User;
use App\Models\Role;
use App\Services\RouteAccess;
use App\Services\AccessCatalog;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as HttpRoute;
$checks=0;
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function userFor($role){static $i=0;return User::create(['name'=>$role,'email'=>'role'.(++$i).'@example.test','password'=>'TestPassword12345','role'=>$role]);}
$admin=userFor('admin');$manager=userFor('manager');$bag=userFor('bag-production');$apparel=userFor('apparel-production');$demo=userFor('demo');
check(User::where('email','legacy@example.test')->firstOrFail()->role==='manager','Migration preserves legacy accounts as Manager');
check($manager->roleLabel()==='Manager','Manager display name');
foreach(AccessCatalog::permissions() as $key=>$label) check($admin->allows($key),'Admin '.$key);
check(!$manager->allows('admin.roles.edit') && $manager->allows('bags.artwork.approve') && $manager->allows('apparel.orders.edit'),'Manager production defaults exclude admin');
check($bag->allows('bags.batches.edit') && !$bag->allows('apparel.orders.view'),'Bag production area isolation');
check($apparel->allows('apparel.orders.approve') && !$apparel->allows('bags.overview.view'),'Apparel area isolation');
check($demo->allows('bags.artwork.view') && !$demo->allows('bags.artwork.approve'),'Demo remains read only');
$gate=new App\Http\Middleware\RequireFeature;
function requestFor($user,$name,$method='GET',$data=[]){global $app;$r=Request::create('/test',$method,$data);$route=new HttpRoute([$method],'test',fn()=>null);$route->name($name);$r->setRouteResolver(fn()=>$route);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($app['session']->driver());return $r;}
function rejected($callback,$label){try{$callback();throw new RuntimeException('Allowed: '.$label);}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===403,$label);}}
foreach(app('router')->getRoutes() as $route){if(in_array('auth',$route->gatherMiddleware(),true)){
 $name=$route->getName();check(in_array(App\Http\Middleware\RequireFeature::class,$route->gatherMiddleware(),true),'Feature middleware '.$name);
 check(in_array($name,['dashboard','profile','docs','logout','admin.settings'],true)||RouteAccess::permission($name)!==null,'Explicit permission mapping '.$name);
}}
rejected(fn()=>$gate->handle(requestFor($apparel,'production'),fn()=>'allowed'),'Apparel cannot directly access bags');
rejected(fn()=>$gate->handle(requestFor($manager,'admin.roles'),fn()=>'allowed'),'Manager cannot directly edit roles');
rejected(fn()=>$gate->handle(requestFor($demo,'verify','POST'),fn()=>'allowed'),'Demo cannot approve through direct requests');
$custom=Role::create(['slug'=>'custom-approver','name'=>'Cross-area approver','permissions'=>['bags.overview.view','bags.artwork.view','apparel.orders.approve','apparel.overview.view']]);
$reviewer=userFor($custom->slug);
check($reviewer->allows('apparel.orders.approve')&&!$reviewer->allows('apparel.orders.edit'),'Cross-area approval without edit');
$custom->update(['permissions'=>['apparel.overview.view']]);
check(!$reviewer->fresh()->allows('apparel.orders.approve'),'Revocation applies on next request');
$roles=new App\Http\Controllers\RoleController;
$req=requestFor($admin,'admin.roles.store','POST',['name'=>'Custom bag approver','permissions'=>['bags.artwork.approve']]);$roles->store($req);
$new=Role::where('name','Custom bag approver')->firstOrFail();check(in_array('bags.artwork.view',$new->permissions,true)&&in_array('bags.overview.view',$new->permissions,true),'Permission dependencies enforced');
$delegatedRole=Role::create(['slug'=>'delegated','name'=>'Delegated','permissions'=>['admin.roles.edit','admin.users.edit','admin.users.view']]);$delegate=userFor('delegated');
rejected(fn()=>$roles->store(requestFor($delegate,'admin.roles.store','POST',['name'=>'Escalate','permissions'=>['admin.reset.edit']])),'Role manager cannot grant permissions they lack');
rejected(fn()=>$roles->update(requestFor($delegate,'admin.roles.update','PUT',['name'=>'Own role','permissions'=>[]]),$delegatedRole),'Delegated role manager cannot change own role');
$users=new App\Http\Controllers\AdminController;
rejected(fn()=>$users->store(requestFor($delegate,'admin.users.store','POST',['name'=>'Escalation','email'=>'escalation@example.test','role'=>'admin','password'=>'Password123456','password_confirmation'=>'Password123456'])),'User manager cannot create Admin');
Illuminate\Support\Facades\Auth::login($admin);view()->share('errors',new Illuminate\Support\ViewErrorBag);
$html=$roles->index()->render();
preg_match('/<nav class="admin-settings-nav"[\s\S]*?<\/nav>/', $html, $settingsNav);
check(isset($settingsNav[0]) && strpos($settingsNav[0],'Roles and permissions') < strpos($settingsNav[0],'Manage Users'),'Roles settings precede user management');
check(str_contains($html,'Production permissions')&&str_contains($html,'Bag Production'),'Role editor renders shared theme');
Illuminate\Support\Facades\Auth::login($apparel);
$html=view('dashboard.apparel')->render();check(!str_contains($html,'Artwork verification')&&!str_contains($html,'data-factory-connection'),'Apparel theme hides bag navigation and widgets');
Illuminate\Support\Facades\Auth::login($bag);
$html=view('partials.production-navigation')->render();check(str_contains($html,'Artwork verification')&&!str_contains($html,'Apparel Production'),'Bag navigation excludes apparel');
$exception=new Symfony\Component\HttpKernel\Exception\HttpException(403,'Your role does not allow this feature.');
Illuminate\Support\Facades\Auth::login($apparel);
$response=app(Illuminate\Contracts\Debug\ExceptionHandler::class)->render(Request::create('/production'),$exception);
check($response->getStatusCode()===403 && str_contains($response->getContent(),'Access restricted') && str_contains($response->getContent(),'Back to Overview'),'Denied HTML requests return themed HTTP 403 with recovery link');
$jsonRequest=Request::create('/production','POST');$jsonRequest->headers->set('Accept','application/json');
$response=app(Illuminate\Contracts\Debug\ExceptionHandler::class)->render($jsonRequest,$exception);
check($response->getStatusCode()===403 && str_contains($response->getContent(),'Your role does not allow this feature.'),'Denied JSON requests return actionable HTTP 403');
check(Illuminate\Support\Facades\DB::table('access_audits')->where('action','role.created')->exists(),'Role creation is audited');
check(!str_contains(json_encode(Illuminate\Support\Facades\DB::table('access_audits')->get()),'password'),'Access audit does not store passwords');
echo "SUCCESS $checks checks; in-memory database only\n";
