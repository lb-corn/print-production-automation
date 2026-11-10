<?php
require __DIR__.'/role-permissions.php';
require __DIR__.'/material-fixture.php';
Illuminate\Support\Facades\Auth::setUser($manager);
view()->share('errors',new Illuminate\Support\ViewErrorBag());
$items=collect([new App\Models\ProductionItem(['id'=>4019,'line_item_id'=>'preview-4019','order_number'=>'#4019','product'=>'Lucky Bags Duck Camo','series_code'=>'PSE','design_type'=>'single','variant'=>'Pro Sniper Elite / Brown','color'=>'Brown','quantity'=>1,'matches'=>[['id'=>'preview-source','side'=>'single','name'=>'approved-artwork.jpg']]])]);
$service=app(App\Services\ProductionService::class);
$html=view('production.queue',['items'=>$items,'summary'=>$service->summary($items),'materials'=>$service->materials(),'materialCatalogError'=>null,'factoryQueueAvailable'=>true,'factoryReadiness'=>['message'=>'Test preview only']])->render();
check(str_contains($html,'data-labeled-preview-open') && str_contains($html,'data-labeled-preview-dialog'),'Ready for Print opens its own labeled preview');
check(str_contains($html,'RAVE9 LB4019 VMT'),'The preview and printed file share the same label text');
if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/print-label-preview.html',$html);
echo "Print preview checks passed.\n";
