<?php
require __DIR__.'/apparel-integration.php';
use App\Models\JerseyOrder;
use App\Models\Role;
use App\Services\ProductionSummaryService;
use Illuminate\Support\Facades\Auth;
$app->instance(App\Services\ProductionService::class,new class extends App\Services\ProductionService {
    public function workspaceStats(bool $refresh=false):array {return ['ready'=>4,'in_print'=>2,'completed'=>3];}
});
$ids=JerseyOrder::orderBy('id')->limit(3)->pluck('id');
JerseyOrder::findOrFail($ids[0])->update(['shopify_fulfillment_status'=>'FULFILLED']);
JerseyOrder::findOrFail($ids[1])->update(['shopify_fulfillment_status'=>'PARTIALLY_FULFILLED']);
JerseyOrder::findOrFail($ids[2])->update(['shopify_cancelled_at'=>now()]);
$summaryService=new ProductionSummaryService;$app->instance(ProductionSummaryService::class,$summaryService);
foreach([[$admin,['bags','apparel']],[$manager,['bags','apparel']],[$bag,['bags']],[$apparel,['apparel']],[$demo,['bags']]] as [$account,$expected]) {
    Auth::setUser($account);
    $summary=$summaryService->forUser($account,true);
    check(array_keys($summary['areas'])===$expected,'Summary includes only authorized areas: '.$account->role);
    if(in_array('bags',$expected,true))check($summary['areas']['bags']===['ready'=>4,'active'=>2,'done'=>3],'Bag summary retains ready, printing and completed counts');
    if(in_array('apparel',$expected,true))check($summary['areas']['apparel']===['waiting'=>26,'active'=>1,'done'=>1],'Apparel summary separates waiting/partial/fulfilled and excludes cancelled lines');
    $html=view('partials.sidebar-help')->render();
    check(str_contains($html,'Production summary') && str_contains($html,'Quick guide') && !str_contains($html,'sidebar-help sidebar-live'),'Summary has compact content and a guide instead of an empty panel');
    $nav=view('partials.production-navigation')->render();
    check(!str_contains($nav,'data-nav-group="admin"'),'Admin group is absent from workspace navigation');
    $accountMenu=view('partials.account-menu')->render();
    check(str_contains($accountMenu,'Admin Settings')===($account->role==='admin'),'Admin settings remain in the authorized account menu');
    check($gate->handle(requestFor($account,'workspace.status'),fn()=>'allowed')==='allowed','Summary refresh permits authorized overview reader');
    $response=(new App\Http\Controllers\ProductionController(app(App\Services\ProductionService::class)))->workspaceStatus()->getData(true);
    check(array_keys($response['areas'])===$expected,'JSON summary filters unauthorized production areas');
}
$role=Role::create(['slug'=>'summary-admin-only','name'=>'Accounts only','permissions'=>['admin.users.view']]);$accountsOnly=userFor($role->slug);Auth::setUser($accountsOnly);
check($summaryService->forUser($accountsOnly,true)===['areas'=>[]],'No production permission exposes no production counts');
$html=view('partials.sidebar-help')->render();check(!str_contains($html,'production-snapshot') && str_contains($html,'Quick guide'),'Account-only user gets a plain guide link without a blank card');
check(str_contains(view('partials.account-menu')->render(),'Admin Settings'),'Delegated account administrator retains settings through account menu');
rejected(fn()=>$gate->handle(requestFor($accountsOnly,'workspace.status'),fn()=>'allowed'),'Account-only user cannot fetch production summaries');
Auth::setUser($manager);
$r=Illuminate\Http\Request::create('/apparel/orders');$r->setUserResolver(fn()=>$manager);$app->instance('request',$r);
$apparelSummary=$summaryService->forUser($manager,true);
$r=Illuminate\Http\Request::create('/production');$r->setUserResolver(fn()=>$manager);$app->instance('request',$r);
check($summaryService->forUser($manager,true)===$apparelSummary,'Combined summary is identical on apparel and bag screens');
echo "SUCCESS $checks permission, apparel and combined-summary checks.\n";
