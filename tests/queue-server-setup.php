<?php
require __DIR__.'/print-job-consent.php';
// Simulate uploaded code with the new migration still pending, without network access.
Illuminate\Support\Facades\Schema::table('print_batches',fn($t)=>$t->dropColumn('operator_dispatch_enabled'));
$count=App\Models\PrintBatch::count();$approved=$svc->queue()->pluck('id')->all();
$request=Illuminate\Http\Request::create('/batches','POST',['items'=>$approved,'dispatch_confirmed'=>1]);$request->setUserResolver(fn()=>$normal);
$response=$controller->batch($request);
$check($response->getStatusCode()===302 && $response->getTargetUrl()===route('queue'),'Missing server migration redirects safely to Ready for Print instead of a 503 page');
$check(App\Models\PrintBatch::count()===$count && $svc->queue()->pluck('id')->all()===$approved,'Blocked queue requests create no batch and preserve approved items');
$view=$controller->queue();$check($view->getData()['factoryQueueAvailable']===false,'Queue preflight identifies missing consent setup before confirmation');
$response=$controller->requestPreparation($request,$blocked->fresh());
$check($response->getStatusCode()===302 && $response->getTargetUrl()===route('batch.show',$blocked),'Old batch preparation also reports missing setup without a generic 503');
foreach($svc->queue() as $item){$matches=$item->matches;foreach($matches as &$match)$match['webViewLink']='https://drive.google.com/file/d/test-fixture/view';unset($match);$item->update(['matches'=>$matches]);}
auth()->setUser($normal);view()->share('errors',new Illuminate\Support\ViewErrorBag());
$html=$controller->queue()->render();
$check(str_contains($html,'Printing is waiting for a server update.') && str_contains($html,'data-queue-blocked="1"'),'Queue displays a visible setup notice and a blocked submission state');
$check(!str_contains($html,'bash deployment/post-deploy.sh'),'Ordinary users receive a clear notice without server command instructions');
auth()->setUser($user);$html=$controller->queue()->render();
$check(str_contains($html,'bash deployment/post-deploy.sh'),'Administrator receives the specific server activation command');
echo "$checks combined queue/setup checks passed.\n";

if(in_array('--preview',$argv,true))file_put_contents(__DIR__.'/../tmp/queue-blocked-preview.html',$html);
