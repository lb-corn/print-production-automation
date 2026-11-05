<?php
use App\Models\PrintBatch;
use Illuminate\Http\Request;
require __DIR__.'/factory-dispatch.php';
// Disposable in-memory fixture only.
Illuminate\Support\Facades\DB::table('batch_confirmations')->delete();
PrintBatch::query()->delete();
$request=fn()=>Request::create('/api/factory/maintenance-check','POST',[],[],[],['HTTP_X_FACTORY_AGENT_ID'=>$id]);
$proof=fn()=>$factory->maintenance($request(),$dispatch)->getData(true);
$check($proof()['busy']===false && $proof()['agent_id']===$id,'An empty/reset portal gives an explicit idle proof for this registered PC');
$job=PrintBatch::create(['batch_id'=>'MAINTENANCE-TEST','status'=>'QUEUED','factory_agent_id'=>$id,'order_count'=>1,'artwork_file_count'=>1,'payload'=>[]]);
foreach(['QUEUED'=>false,'PREPARED'=>false,'SENT_TO_RIP'=>false,'PRINTED'=>false,'FAILED'=>false,'CLAIMED'=>true,'DOWNLOADING'=>true,'DELIVERING'=>true] as $status=>$busy){
    $job->update(['status'=>$status]);$before=$job->fresh()->toJson();
    $check($proof()['busy']===$busy && $job->fresh()->toJson()===$before,'Maintenance reports '.$status.' correctly without changing the job');
}
$job->update(['status'=>'HANDOFF_REQUESTED','agent_operation_phase'=>'handoff','agent_operation_token'=>Illuminate\Support\Str::uuid()->toString()]);
$check($proof()['busy']===true,'An interrupted signed handoff remains blocked');
$job->update(['status'=>'CLAIMED','factory_agent_id'=>null]);
$check($proof()['busy']===true,'Unassigned legacy active work is never bypassed');
$job->update(['factory_agent_id'=>'fa90d56c-ce85-484f-af1d-71db6de50c07']);
$check($proof()['busy']===false,'Another identified PC does not block this idle installation');
$job->delete();
$check($proof()['busy']===false,'A removed old batch does not require a stale operation token');
$transfer=App\Models\CustomArtworkTransfer::create(['id'=>Illuminate\Support\Str::uuid()->toString(),'production_item_id'=>$a->id,'created_by'=>$user->id,'factory_agent_id'=>$id,'kind'=>'UPLOAD','side'=>'single','state'=>'CONNECTED','metadata'=>[],'expires_at'=>now()->addMinutes(5)]);
$check($proof()['busy']===true && $proof()['reason']==='active_transfer','A connected custom transfer postpones maintenance');
$transfer->update(['expires_at'=>now()->subMinute()]);$before=$transfer->fresh()->toJson();
$check($proof()['busy']===false && $transfer->fresh()->toJson()===$before,'Expired transfers do not strand an idle updater or get rewritten');
$invalid=Request::create('/api/factory/maintenance-check','POST',[],[],[],['HTTP_X_FACTORY_AGENT_ID'=>'fa90d56c-ce85-484f-af1d-71db6de50c07']);
$reject(fn()=>$factory->maintenance($invalid,$dispatch),422);
$route=collect(app('router')->getRoutes())->first(fn($r)=>$r->uri()==='api/factory/maintenance-check');
$check($route && in_array('POST',$route->methods()) && in_array('factory.key',$route->gatherMiddleware()) && in_array(App\Http\Middleware\PortalWriteLock::class,$route->gatherMiddleware()),'Maintenance proof is authenticated and serialized with job claims and reset');
