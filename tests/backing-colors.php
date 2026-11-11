<?php
// Isolated database/cache with synthetic image fixtures. No live services.
require __DIR__.'/role-permissions.php';
use App\Models\ProductionItem;
use App\Services\{MaterialCatalog,BackingColorService,BackingImageMatcher,ProductionService,PrintLabelService};
use Illuminate\Support\Facades\{Auth,Cache,Http};
Http::preventStrayRequests();config(['cache.default'=>'array','services.backing_colors.enabled'=>true]);
$catalog=new class extends MaterialCatalog {
    public string $csv="Bag Name,Bag Code,Front Material,Back Material,Type,Template Size\nPro Sniper Elite,PSE,Rave9,Viewmont,Single,Normal\n\nLuckyBag Single Side Back Material Configuration\nViewmont,Other Material\nLatte,Red\nAsh,Blue\nCharcoal,Green\n";
    public function rows(bool $fresh=false):array{return $this->parse($this->csv);}
};$app->instance(MaterialCatalog::class,$catalog);$service=app(ProductionService::class);
$item=ProductionItem::create(['line_item_id'=>'backing-test','order_number'=>'#4019','product'=>'Duck Camo','variant'=>'PSE / Brown','sku'=>'2027-DUC-PSE-001','color'=>'Brown','series_code'=>'PSE','design_type'=>'single','quantity'=>1,'shopify_image'=>'https://cdn.shopify.com/test-product.png','match_status'=>'REVIEW','artwork_review'=>['policy'=>App\Services\ArtworkMatcherService::POLICY],'matches'=>[['id'=>'drive-duck','side'=>'single','name'=>'PSE-DUCK-BROWN-SINGLE.jpg']]]);
check(BackingColorService::definition($item)['colors']===['Latte','Ash','Charcoal'],'Dropdown choices come only from the current backing material column');
check(!BackingColorService::confirmed($item),'A website/design color does not count as a backing confirmation');
check(!str_contains(PrintLabelService::label($item,'Rave9'),'BROWN'),'Unconfirmed label never uses the general product color');
$reject=function($call,$expected) {try{$call();throw new LogicException('Unsafe operation allowed');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===$expected,'Backing state rejects invalid operation with '.$expected);}};
$reject(fn()=>$service->verify($item,'CORRECT',$manager->id),422);
$backing=new BackingColorService;$context=BackingColorService::context($item);
$reject(fn()=>$backing->confirm($item,'Brown',$context,$manager->id),422);
$reject(fn()=>$backing->confirm($item,'latte',$context,$manager->id),422);
$reject(fn()=>$backing->confirm($item,'Latte',str_repeat('0',64),$manager->id),409);
$service->verify($item,'CORRECT',$manager->id,['color'=>'Latte','context'=>$context]);$item->refresh();
check(BackingColorService::confirmed($item) && PrintLabelService::label($item,'Rave9')==='RAVE9 LB4019 VIEWMONT LATTE','Confirmed label uses the exact sheet backing color rather than Brown');
check($service->approved($item),'One approval saves both artwork and backing color');
$reject(fn()=>$service->verify($item,'CORRECT',$manager->id,['color'=>'Ash','context'=>$context]),409);
$reject(fn()=>$backing->confirm($item,'Ash',$context,$manager->id),409);
$original=$item->shopify_image;$item->shopify_image='https://cdn.shopify.com/changed.png';check(!BackingColorService::confirmed($item) && !$service->approved($item),'A changed original website image invalidates backing and artwork approval');$item->shopify_image=$original;
$controller=app(App\Http\Controllers\ProductionController::class);$returnRequest=requestFor($manager,'production.return-review','POST');$controller->returnReadyToReview($returnRequest,$item);$item->refresh();check(!$item->decision && !$service->ready($item),'Returning to review removes readiness before allowing edits');
$service->verify($item,'CORRECT',$manager->id,['color'=>'Ash','context'=>BackingColorService::context($item)]);$item->refresh();
$jobs=$service->batchByMaterial($manager->id,[$item->id]);$order=$jobs[0]->payload['orders'][0];
check($order['backing_color']==='Ash' && $order['backing_confirmation']['color']==='Ash' && $order['artworks'][0]['print_label']==='RAVE9 LB4019 VIEWMONT ASH','Batch snapshot locks the confirmed backing color and its label');
$reject(fn()=>$backing->confirm($item,'Latte',BackingColorService::context($item),$manager->id),409);
$stock=$item->replicate();$stock->line_item_id='one-click-controller';$stock->backing_selection=null;$stock->matches=[['id'=>'one-click-art','side'=>'single','name'=>'PSE-DUCK-BROWN-SINGLE.jpg']];$stock->save();$stock->unsetRelation('decision');
$approve=requestFor($manager,'verify','POST',['decision'=>'CORRECT','backing_color'=>'Latte','backing_context'=>BackingColorService::context($stock)]);
$controller->verify($approve,$stock);$stock->refresh();
check($service->approved($stock) && $stock->backing_selection['color']==='Latte','Existing Approve controller saves the chosen color and artwork in one action');
$reject(fn()=>$controller->returnReadyToReview($returnRequest,$item),409);
$invalid=$stock->replicate();$invalid->line_item_id='one-click-invalid';$invalid->backing_selection=null;$invalid->matches=[];$invalid->save();$invalid->unsetRelation('decision');
$reject(fn()=>$service->verify($invalid,'CORRECT',$manager->id,['color'=>'Latte','context'=>BackingColorService::context($invalid)]),422);
check($invalid->fresh()->backing_selection===null && !$invalid->fresh()->decision,'Failed artwork approval rolls back its backing selection too');
$img=function(array $rgb,bool $composite=false,bool $reverse=false):string {
    $im=imagecreatetruecolor(500,400);imagefill($im,0,0,0xffffff);
    if($composite){$frontX=$reverse?280:35;$backX=$reverse?35:280;
        for($y=80;$y<310;$y++)for($x=$frontX;$x<$frontX+175;$x++)imagesetpixel($im,$x,$y,(($x+$y)%13<6)?0x3b3323:0xc9ab6b);
        imagefilledrectangle($im,$backX,100,$backX+175,330,($rgb[0]<<16)|($rgb[1]<<8)|$rgb[2]);
    }else imagefill($im,0,0,($rgb[0]<<16)|($rgb[1]<<8)|$rgb[2]);
    ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;
};
$matcher=new BackingImageMatcher;$refs=['Latte'=>$img([150,130,95]),'Ash'=>$img([105,115,100]),'Charcoal'=>$img([40,40,40])];
$result=$matcher->compare($img([150,130,95],true),$refs);
check($result['suggested']==='Latte' && $result['crop']['x']>.5,'Website composite comparison isolates the backside and suggests its sheet color');
$result=$matcher->compare($img([150,130,95],true,true),$refs);check($result['suggested']==='Latte' && $result['crop']['x']<.3,'Backside isolation supports either left or right position');
$result=$matcher->compare($img([150,130,95],true),['Latte'=>$refs['Latte'],'Other close color'=>$refs['Latte']]);check($result['suggested']===null,'Indistinguishable swatches require manual selection');
check($matcher->compare($refs['Latte'],$refs)['suggested']===null,'An image without separate bags cannot silently become an automatic match');
$profile=$matcher->profile($refs['Latte']);check($matcher->compare($img([150,130,95],true),['Latte'=>$profile,'Ash'=>$matcher->profile($refs['Ash'])])['suggested']==='Latte','Numeric cached swatch profiles do not require retaining image bytes');
try{(new App\Services\BackingReferenceLoader)->product('http://127.0.0.1/private');throw new LogicException('Unsafe host allowed');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Product image reader rejects non-HTTPS and unapproved hosts');}
$item->backing_selection=null;$item->setRelation('decision',null);
Auth::setUser($manager);view()->share('errors',new Illuminate\Support\ViewErrorBag());
$html=view('partials.backing-color-review',['item'=>$item])->render();check(str_contains($html,'<option value="Latte"') && !str_contains($html,'<option value="Red"') && !str_contains($html,'name="backing_confirmed"') && !str_contains($html,'<form') && str_contains($html,'form="approve-artwork-'),'Compact sheet-only selector submits with the existing approval form, without a checkbox or second action');
check(substr_count($html,'class="backing-swatch ')===3 && str_contains($html,'backing-reference?'),'Allowed material swatch tiles and private image URLs are rendered before comparison starts');
if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/backing-review.html','<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>'.file_get_contents(public_path('css/app.css')).'</style><main style="max-width:1100px;margin:auto">'.$html.'</main>');
if(in_array('--preview',$argv,true)){
    $ui=$stock->replicate();$ui->line_item_id='compact-ui-preview';$ui->order_number='#9009';$ui->backing_selection=null;$ui->save();$ui->unsetRelation('decision');
    file_put_contents(__DIR__.'/../tmp/backing-compact-page.html',$controller->verification()->render());
    file_put_contents(__DIR__.'/../tmp/backing-ui-id.txt',(string)$ui->id);
}
$app->instance(App\Services\BackingSwatchService::class,new class extends App\Services\BackingSwatchService {
    public function files(string $material,array $colors):array{return ['Latte'=>['id'=>'private-swatch','mimeType'=>'image/png']];}
    public function suggest($item):array{return ['suggested'=>'Latte'];}
});
$loader=new class($refs['Latte']) extends App\Services\BackingReferenceLoader {
    public int $reads=0;
    public function __construct(private string $bytes){}
    public function swatch(array $file):string{$this->reads++;return $this->bytes;}
};$app->instance(App\Services\BackingReferenceLoader::class,$loader);
$backingController=new App\Http\Controllers\BackingColorController;
$suggestion=$backingController->suggest($stock)->getData(true);
check(str_contains($suggestion['swatches'][0]['image'],'backing-reference') && str_contains($suggestion['swatches'][0]['image'],'partner=lucky') && !str_contains($suggestion['swatches'][0]['image'],'drive.google.com'),'Private swatches use partner-scoped app URLs');
$response=$backingController->swatch(Illuminate\Http\Request::create('/swatch?color=Latte'),$item);
check($response->headers->get('Content-Type')==='image/png' && $response->getContent()===$refs['Latte'] && str_contains($response->headers->get('Cache-Control'),'private'),'App serves authenticated swatch bytes with private cache headers');
$reject(fn()=>$backingController->swatch(Illuminate\Http\Request::create('/swatch?color=Brown'),$item),404);
check($loader->reads===1,'Unknown colors cannot request arbitrary Drive files');
$response=$backingController->reference(Illuminate\Http\Request::create('/reference?material=Viewmont&color=Latte'));
check($response->getContent()===$refs['Latte'],'Shared material reference loads without depending on any one order');
$reject(fn()=>$backingController->reference(Illuminate\Http\Request::create('/reference?material=Viewmont&color=Brown')),404);
$reject(fn()=>$backingController->reference(Illuminate\Http\Request::create('/reference?material=Unknown&color=Latte')),404);
echo "Backing-color checks passed.\n";
