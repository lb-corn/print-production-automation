<?php
// In-memory company fixtures and fake integrations. No remote services.
require __DIR__.'/partner-integration.php';
config(['services.google_drive.factory_root_id'=>null]);
$dashboardCharts=new App\Services\DashboardCharts;
$middleware->handle(requestFor($manager,'dashboard'),function()use($dashboardCharts,$svc,$app){
    $data=app(App\Http\Controllers\ApparelOverviewController::class)->index()->getData();$charts=$data['charts'];
    check(array_column($charts['companies'],'key')===['lucky','vibe'] && array_column($charts['companies'],'value')===[1,1],'All-company chart explicitly counts Lucky and Vibe');
    check(array_sum(array_column($charts['states'],'value'))===$data['summary']['total'],'Apparel doughnut segments reconcile with imported totals');
    check(count($charts['trend'])===6,'History chart has six chronological monthly buckets');
    $bags=$dashboardCharts->bags($svc->activeItems(),$svc);
    check(array_sum(array_column($bags['states'],'value'))===$bags['total'] && array_sum(array_column($bags['companies'],'value'))===$bags['total'],'Bag state and company charts reconcile with scoped order lines');
});
$middleware->handle(requestFor($onlyVibe,'dashboard','GET',['partner'=>'all']),function(){
    $charts=app(App\Http\Controllers\ApparelOverviewController::class)->index()->getData()['charts'];
    check(array_column($charts['companies'],'key')===['vibe'] && $charts['total']===1,'Chart data respects company role restrictions');
});
App\Services\PartnerContext::run('vibe',function()use($app){
    App\Models\JerseyOrder::query()->delete();
    $charts=app(App\Http\Controllers\ApparelOverviewController::class)->index()->getData()['charts'];
    check($charts['total']===0 && $charts['companies'][0]['value']===0,'Empty company remains visible with a zero count');
    $html=view('partials.dashboard-charts',['charts'=>$charts])->render();
    check(!str_contains($html,'NaN') && !str_contains($html,'INF') && str_contains($html,'No order lines'),'Empty charts render a clear state without invalid geometry');
});
echo "Dashboard chart checks passed.\n";
