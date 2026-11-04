<?php
require __DIR__.'/factory-dispatch.php';
// Continue in the isolated in-memory DB; no PC, Drive or printer is contacted.
$agent->refresh()->update(['processing_enabled'=>false,'last_seen_at'=>now(),'telemetry'=>['agent_version'=>'1.5.0','reporter_mode'=>'listener','process_status'=>'running','stage'=>'idle']]);
$check($dispatch->readiness()['state']==='ready','Connected listener is available without an Admin enable switch');
$check($dispatch->next($id)===null && !$blocked->fresh()->operator_dispatch_enabled,'Existing paused queue entries are not released by the new flow');
$controller=new App\Http\Controllers\ProductionController($svc);
foreach(['operator','admin'] as $role){
    $actor=App\Models\User::create(['name'=>'Consent '.$role,'email'=>$role.'-consent@example.test','password'=>'unused','role'=>$role]);
    $item=$make('consent-'.$role);
    $request=Illuminate\Http\Request::create('/batches','POST',['items'=>[$item->id],'dispatch_confirmed'=>1]);$request->setUserResolver(fn()=>$actor);
    $controller->batch($request);$job=App\Models\PrintBatch::latest('id')->first();
    $check((bool)$job->operator_dispatch_enabled && $job->created_by===$actor->id && $job->order_count===1 && !$agent->fresh()->processing_enabled,$role.' can authorize only selected work without enabling the entire queue');
    $check($dispatch->next($id)===['id'=>$job->id,'phase'=>'prepare'],'Only the newly authorized batch is offered to the listener');
    $request=Illuminate\Http\Request::create('/api/work','POST',[],[],[],['HTTP_X_FACTORY_AGENT_ID'=>$id]);
    $operation=$dispatch->begin($request,$job,'prepare');
    $check($job->fresh()->status==='CLAIMED' && $operation['phase']==='prepare','Authorized work starts even though the legacy Admin switch is off');
    $job->refresh()->update(['status'=>'PREPARED']);
    $request=Illuminate\Http\Request::create('/handoff','POST');$request->setUserResolver(fn()=>$actor);
    try{$controller->requestEdgePrint($request,$job);throw new LogicException('Unsigned handoff accepted');}catch(Illuminate\Validation\ValidationException $e){$check($job->fresh()->status==='PREPARED','Handoff still requires final human name, phrase, counts and payload confirmation');}
    $signed=Illuminate\Http\Request::create('/handoff','POST',['signer_name'=>'Test operator','confirmation_phrase'=>'HANDOFF','review_confirmed'=>1,'confirmed_order_count'=>$job->order_count,'confirmed_artwork_file_count'=>$job->artwork_file_count,'payload_sha256'=>hash('sha256',json_encode($job->payload))]);$signed->setUserResolver(fn()=>$actor);
    $dispatch->setHandoffBlocked($agent,true,$user->id);
    $reject(fn()=>$controller->requestEdgePrint($signed,$job),423);
    $check($job->fresh()->status==='PREPARED' && !$job->confirmations()->exists(),$role.' cannot bypass the Admin block with a fully signed handoff request');
    $dispatch->setHandoffBlocked($agent,false,$user->id);
    $controller->requestEdgePrint($signed,$job);
    $check($job->fresh()->status==='HANDOFF_REQUESTED' && $job->confirmations()->where('action','HANDOFF')->exists(),$role.' must sign the final handoff before delivery is authorized');
    $job->update(['status'=>'FAILED']); // End the isolated fixture without delivering any artwork.
}
$check(!$blocked->fresh()->operator_dispatch_enabled,'Per-job permissions never enable older pending batches');
$route=app('router')->getRoutes()->getByName('batch.create');$middleware=$route->gatherMiddleware();
$check(in_array('auth',$middleware) && in_array(App\Http\Middleware\ReadOnlyDemo::class,$middleware) && !in_array('admin',$middleware),'Print authorization is authenticated and demo-protected, without an Admin-only restriction');
$monitor=new App\Http\Controllers\FactoryMonitoringController();
$normal=App\Models\User::where('role','operator')->first();$r=Illuminate\Http\Request::create('/admin/handoff','POST',['blocked'=>1,'confirm_control'=>1]);$r->setUserResolver(fn()=>$normal);
$reject(fn()=>$monitor->handoffControl($r,$agent,$dispatch),403);
$dispatch->setHandoffBlocked($agent,true,$user->id);
$job->refresh()->update(['status'=>'PREPARED']);
$check(!$dispatch->progress($job)['can_handoff'] && str_contains($dispatch->progress($job)['title'],'blocked'),'Admin handoff block is visible and removes the delivery action');
$reject(fn()=>$dispatch->assertHandoffAllowed($job),423);
$job->update(['status'=>'HANDOFF_REQUESTED']);
$check($dispatch->next($id)===null,'Blocked signed handoffs are not offered to a listener');
$r=Illuminate\Http\Request::create('/api/work','POST',[],[],[],['HTTP_X_FACTORY_AGENT_ID'=>$id]);
$reject(fn()=>$dispatch->begin($r,$job,'handoff'),423);
$dispatch->setHandoffBlocked($agent,false,$user->id);
$check($dispatch->next($id)===['id'=>$job->id,'phase'=>'handoff'],'Allow handoff restores only already authorized work');
$handoff=$dispatch->begin($r,$job,'handoff');
$check($handoff['phase']==='handoff','Unblocked signed handoff can obtain its bound delivery operation');
$dispatch->setHandoffBlocked($agent,true,$user->id);
$delivery=Illuminate\Http\Request::create('/api/status','POST',['status'=>'DELIVERING'],[],[],['HTTP_X_FACTORY_AGENT_ID'=>$id,'HTTP_X_FACTORY_OPERATION'=>$handoff['token']]);
$reject(fn()=>$factory->status($delivery,$job->fresh()),423);
$check($job->fresh()->status==='HANDOFF_REQUESTED','A block after worker assignment still prevents delivery from starting');
$dispatch->setHandoffBlocked($agent,false,$user->id);$factory->status($delivery,$job->fresh());
$dispatch->setHandoffBlocked($agent,true,$user->id);$delivery->request->set('status','SENT_TO_RIP');$factory->status($delivery,$job->fresh());
$check($job->fresh()->status==='SENT_TO_RIP','Already-started delivery may finish safely after an Admin block');
$check(count($agent->fresh()->control_audit)>=2,'Admin handoff block and allow actions are audited');
echo "$checks combined dispatch/consent checks passed.\n";
