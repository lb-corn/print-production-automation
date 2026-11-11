<?php
require __DIR__.'/role-permissions.php';
foreach(['app.css','apparel.css'] as $asset){$source=file_get_contents(base_path('packages/ui-theme/resources/css/'.$asset));$published=file_get_contents(public_path('css/'.$asset));check(str_replace("\r\n","\n",$source)===str_replace("\r\n","\n",$published),'Published '.$asset.' matches its one package source');}
Illuminate\Support\Facades\Auth::setUser($admin);
$pages=['dashboard'=>['Production Overview','Orders and factory status'],'production'=>['Production Verification','Compare Shopify and Drive artwork'],'apparel.orders.index'=>['Apparel Orders','Jersey and hoodie production'],'batches'=>['Print Batches','Factory queue and history'],'admin.settings'=>['Admin Settings','Administrative tools and production controls'],'profile'=>['Profile','Your workspace account']];
foreach($pages as $route=>[$heading,$subheading]){
    $request=requestFor($admin,$route);$request->route()->bind($request);$app->instance('request',$request);
    $html=view('production-ui::app',['heading'=>$heading,'subheading'=>$subheading,'slot'=>new Illuminate\Support\HtmlString('<section class="panel">Theme fixture</section>')])->render();
    check(substr_count($html,'<header class="page-header">')===1 && str_contains($html,'header-account-tools'),'The same package header renders for '.$route);
    if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/theme-'.str_replace('.','-',$route).'.html',$html);
}
$html=Illuminate\Support\Facades\Blade::render('<x-production-ui::panel class="demo"><x-production-ui::badge>Vibe</x-production-ui::badge><x-production-ui::button type="submit" variant="chip" disabled>Save</x-production-ui::button></x-production-ui::panel>');
check(str_contains($html,'panel') && str_contains($html,'partner-badge') && str_contains($html,'type="submit"') && str_contains($html,'disabled'),'Package components preserve classes, control types and disabled state');
echo "Unified UI theme checks passed.\n";
$request=requestFor($admin,'batches');$request->route()->bind($request);$app->instance('request',$request);
$batch=(new App\Models\PrintBatch)->forceFill(['id'=>42,'batch_id'=>'LB-BATCH-TEST-LONG-IDENTIFIER-12345','status'=>'CANCELLED','order_count'=>1,'artwork_file_count'=>1,'partner'=>'lucky','payload'=>[],'created_at'=>now()]);
$html=view('batches.index',['batches'=>collect([$batch])])->render();$markup=preg_replace('~<style\b[^>]*>.*?</style>~s','',$html);
check(strpos($markup,'Batch history')<strpos($markup,'aria-label="Batch filters"') && str_contains($markup,'ui-toolbar batch-filter-toolbar'),'Batch title and filters use one shared list toolbar');
check(!str_contains($markup,'company-filter-panel'),'Batch filters do not create a nested company panel');
$markup=preg_replace('~<style\b[^>]*>.*?</style>~s','',view('batches.index',['batches'=>collect()])->render());
check(str_contains($markup,'ui-empty-state') && !str_contains($markup,'batch-list-heading'),'An empty batch list uses the shared empty state without empty column headers');
