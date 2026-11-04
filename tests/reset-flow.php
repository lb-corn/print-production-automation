<?php
// All persistence below uses the in-memory fixtures from admin-controls.php.
require __DIR__.'/admin-controls.php';
$sync=new class extends App\Services\BacklogSyncService {
    public bool $fail=false;
    public function __construct(){}
    public function sync():array {
        if($this->fail)throw new RuntimeException('Simulated Shopify outage');
        App\Models\ProductionItem::create(['line_item_id'=>'fresh-only','product'=>'Current Shopify bag','quantity'=>1]);
        return [];
    }
};
$app->instance(App\Services\BacklogSyncService::class,$sync);
Auth::login($admin);
$sync->fail=true;
$before=App\Models\ProductionItem::all()->toJson();
$response=$controller->reset($request([...$valid,'reload_shopify'=>'1']));
$check($response->isRedirect(route('admin.settings',['section'=>'reset'])) && App\Models\ProductionItem::all()->toJson()===$before,'Failed fresh import rolls back the entire reset');
$sync->fail=false;
$controller->reset($request([...$valid,'reload_shopify'=>'1']));
$check(App\Models\ProductionItem::pluck('line_item_id')->all()===['fresh-only'],'Reset imports only the current Shopify bag backlog');
$download=$controller->pcCleanup($request());
$check(str_contains($download->headers->get('Content-Disposition'),'Clear-LuckyBags-Print-Files.cmd') && str_contains($download->getContent(),'# POWERSHELL_START'),'Admin receives the self-contained PC cleanup download');
$limiter=new Illuminate\Cache\RateLimiter(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore));
$limiter->for('admin-reset',app(Illuminate\Cache\RateLimiter::class)->limiter('admin-reset'));
$middleware=new Illuminate\Routing\Middleware\ThrottleRequests($limiter);
$calls=0;$next=function()use(&$calls){$calls++;return response('allowed');};
// Polling via the legacy unnamed limit must not consume the reset allowance.
for($i=0;$i<15;$i++)$middleware->handle($request(),$next,30,1);
$calls=0;
for($i=0;$i<3;$i++)$middleware->handle($request(),$next,'admin-reset');
try{$response=$middleware->handle($request(),$next,'admin-reset');}catch(Illuminate\Http\Exceptions\HttpResponseException $e){$response=$e->getResponse();}
$check($calls===3 && $response->isRedirect(route('admin.settings',['section'=>'reset'])),'Polling cannot exhaust reset allowance; repeated reset redirects with feedback');
$check((int)$response->headers->get('Retry-After')>0 && str_contains(session('error'),'No reset was performed'),'Rate limit preserves an actionable retry notice without running reset');

if(in_array('--preview',$argv,true)){file_put_contents(__DIR__.'/../tmp/pc-cleanup-preview.cmd',$download->getContent());file_put_contents(__DIR__.'/../tmp/reset-preview.html',view('admin.reset',['counts'=>collect(App\Http\Controllers\AdminController::RESET_TABLES)->mapWithKeys(fn($table)=>[$table=>DB::table($table)->count()])])->render());}
