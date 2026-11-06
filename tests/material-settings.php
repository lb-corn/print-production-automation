<?php
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL: '.$e.PHP_EOL);exit(1);});
use Illuminate\Support\Facades\Http;use App\Services\MaterialCatalog;
$check=function($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;};
config(['services.materials'=>[],'session.driver'=>'array']);
Http::fake(['docs.google.com/*'=>Http::response(file_get_contents(__DIR__.'/fixtures/lucky-bags-materials.csv'),200)]);
$catalog=new MaterialCatalog();$check($catalog->rows(true)['PSN']['front']==='Rave9','Old cached hosting config still reads the linked live sheet without hardcoded material assignments');
$check(Http::recorded(fn($r)=>str_contains($r->url(),MaterialCatalog::DEFAULT_SHEET_ID))->count()===1,'The supplied sheet remains the source when the new config key is not cached yet');
Http::swap(new Illuminate\Http\Client\Factory());Http::fake(fn()=>throw new Illuminate\Http\Client\ConnectionException('internal sensitive connection detail'));
$catalog=new MaterialCatalog();$check($catalog->rows()===[] && str_contains($catalog->error(),'could not connect') && !str_contains($catalog->error(),'sensitive'),'Connection failures have an actionable message without exposing internal error details');
$app->instance(MaterialCatalog::class,$catalog);
$svc=new class extends App\Services\ProductionService {public function workspaceStats(bool $refresh=false):array{return ['ready'=>0,'in_print'=>0,'completed'=>0];}};$app->instance(App\Services\ProductionService::class,$svc);
$user=new App\Models\User(['name'=>'Operator','role'=>'manager']);$user->setRelation('accessRole',new App\Models\Role(['name'=>'Manager','permissions'=>App\Services\AccessCatalog::defaults()['manager'][1]]));auth()->setUser($user);view()->share('errors',new Illuminate\Support\ViewErrorBag());
$html=view('dashboard.index',['total'=>36,'ready'=>0,'review'=>32,'custom'=>0,'summary'=>$svc->summary(collect()),'batches'=>collect(),'materialCatalogError'=>$catalog->error()])->render();
$check(str_contains($html,'Material totals are temporarily unavailable') && str_contains($html,'Refresh settings') && !str_contains($html,'<b>0</b><em>Exact artwork approved'),'Dashboard distinguishes unavailable material totals from real zero counts');
if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/material-settings-error.html',$html);
echo "Material settings compatibility and UI checks passed.\n";
