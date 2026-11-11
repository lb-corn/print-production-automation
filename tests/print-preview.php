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
$previewUrl=App\Http\Controllers\ArtworkPreviewController::url('private-earth');
$previewRequest=Illuminate\Http\Request::create($previewUrl);
check(Illuminate\Support\Facades\URL::hasValidSignature($previewRequest),'Private artwork URL is signed');
check(!Illuminate\Support\Facades\URL::hasValidSignature(Illuminate\Http\Request::create(str_replace('private-earth','other-file',$previewUrl))),'Changing the artwork ID invalidates the signature');
check(!Illuminate\Support\Facades\URL::hasValidSignature(Illuminate\Http\Request::create(str_replace('partner=lucky','partner=vibe',$previewUrl))),'Changing the preview partner invalidates the signature');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH1sAAAAASUVORK5CYII=');
$app->instance(App\Services\BackingReferenceLoader::class,new class($png) extends App\Services\BackingReferenceLoader {
    public function __construct(private string $bytes){}
    public function artworkPreview(string $id):string{return $this->bytes;}
});
$response=(new App\Http\Controllers\ArtworkPreviewController)->show($previewRequest,'private-earth');
check($response->getContent()===$png && $response->headers->get('Content-Type')==='image/png' && str_contains($response->headers->get('Cache-Control'),'private'),'Private artwork preview returns validated image bytes with private caching');
check(str_contains($html,'artwork-preview/preview-source') && !str_contains($html,'drive.google.com/thumbnail'),'Ready for Print uses authenticated previews');
echo "Print preview checks passed.\n";
