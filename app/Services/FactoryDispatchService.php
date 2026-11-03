<?php
namespace App\Services;

use App\Models\{FactoryAgent,PrintBatch};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;

class FactoryDispatchService {
    private ?bool $schema=null;
    public function ready():bool{return $this->schema??=Schema::hasColumn('factory_agents','processing_enabled') && Schema::hasColumn('print_batches','automation_requested_at');}
    private function online(FactoryAgent $agent):bool{return $agent->last_seen_at->timestamp>now()->timestamp-max(30,min(600,(int)config('services.factory.offline_after_seconds',120))) && ($agent->telemetry['process_status']??'')!=='stopping';}
    public function capable(FactoryAgent $agent):bool{return in_array($agent->telemetry['reporter_mode']??'', ['listener','managed_worker'],true);}
    public function configure(FactoryAgent $agent,bool $enabled,int $actor):void {
        abort_unless($this->ready(),503,'Apply the portal dispatch migration first.');
        DB::transaction(function()use($agent,$enabled,$actor){
            $all=FactoryAgent::orderBy('id')->lockForUpdate()->get();$current=$all->firstWhere('id',$agent->id);abort_unless($current,404);
            if($enabled){
                abort_unless($this->capable($current) && $this->online($current),422,'A connected portal listener is required. A connection-only reporter cannot launch workers. Complete the one-time PC setup.');
                abort_if($all->contains(fn($row)=>$row->id!==$current->id && $row->processing_enabled),409,'Pause the other enabled Factory PC before enabling this one.');
            }
            $audit=$current->control_audit??[];$audit[]=['enabled'=>$enabled,'actor'=>$actor,'at'=>now()->toIso8601String()];
            $current->update(['processing_enabled'=>$enabled,'control_audit'=>array_slice($audit,-20)]);
        });
    }
    public function request(PrintBatch $batch):void {
        abort_unless($this->ready(),503,'Apply the portal dispatch migration before queueing automatic work.');
        $locked=PrintBatch::lockForUpdate()->findOrFail($batch->id);
        abort_unless($locked->status==='QUEUED' && !$locked->claimed_at && !$locked->agent_operation_token,409,'Only untouched queued batches can request automatic preparation.');
        $target=FactoryAgent::where('processing_enabled',true)->first();
        $locked->update(['automation_requested_at'=>now(),'factory_agent_id'=>$target?->agent_id]);
    }
    public function identity(Request $request):string {
        $id=(string)$request->header('X-Factory-Agent-ID');abort_unless(Str::isUuid($id),422,'Factory agent identity is required.');return strtolower($id);
    }
    public function next(string $id):?array {
        abort_unless($this->ready(),503,'Portal dispatch setup is required.');
        $agent=FactoryAgent::where('agent_id',$id)->first();
        if(!$agent || !$agent->processing_enabled || !$this->online($agent) || !$this->capable($agent))return null;
        // An interrupted phase is never automatically retried or bypassed by a second worker.
        $busy=PrintBatch::where('factory_agent_id',$id)->where(function($q){$q->whereIn('status',['CLAIMED','DOWNLOADING','DELIVERING'])->orWhere(fn($q)=>$q->where('status','HANDOFF_REQUESTED')->where('agent_operation_phase','handoff')->whereNotNull('agent_operation_token'));})->exists();
        if($busy)return null;
        $batch=PrintBatch::whereNotNull('automation_requested_at')->where(fn($q)=>$q->whereNull('factory_agent_id')->orWhere('factory_agent_id',$id))->whereIn('status',['QUEUED','HANDOFF_REQUESTED'])->oldest()->first();
        return $batch?['id'=>$batch->id,'phase'=>$batch->status==='QUEUED'?'prepare':'handoff']:null;
    }
    public function begin(Request $request,PrintBatch $batch,string $phase):array {
        abort_unless(in_array($phase,['prepare','handoff'],true),422,'Unsupported work phase.');
        $id=$this->identity($request);abort_unless($this->ready(),503,'Portal dispatch setup is required.');
        return DB::transaction(function()use($id,$batch,$phase){
            $agent=FactoryAgent::where('agent_id',$id)->lockForUpdate()->first();
            abort_unless($agent && $agent->processing_enabled && $this->capable($agent) && $this->online($agent),409,'Factory processing is paused or the listener is offline.');
            abort_if(PrintBatch::where('factory_agent_id',$id)->where('id','!=',$batch->id)->where(function($q){$q->whereIn('status',['CLAIMED','DOWNLOADING','DELIVERING'])->orWhere(fn($q)=>$q->where('status','HANDOFF_REQUESTED')->where('agent_operation_phase','handoff')->whereNotNull('agent_operation_token'));})->exists(),409,'Another operation for this Factory PC is still active.');
            $locked=PrintBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->automation_requested_at && (!$locked->factory_agent_id || $locked->factory_agent_id===$id),409,'This batch is not authorized for this Factory PC.');
            abort_unless($locked->status===($phase==='prepare'?'QUEUED':'HANDOFF_REQUESTED'),409,'The batch phase has changed.');
            abort_if($locked->agent_operation_phase===$phase && $locked->agent_operation_token,409,'This phase already started. Inspect the existing operation; do not retry automatically.');
            if($phase==='handoff')abort_unless($locked->confirmations()->where('action','HANDOFF')->exists(),422,'Signed handoff is required.');
            $locked->factory_agent_id=$id;$locked->agent_operation_phase=$phase;$locked->agent_operation_token=(string)Str::uuid();$locked->agent_operation_started_at=now();$locked->agent_error_code=null;
            if($phase==='prepare'){$locked->status='CLAIMED';$locked->claimed_at=now();}
            $locked->save();return ['batch_id'=>$locked->id,'phase'=>$phase,'token'=>$locked->agent_operation_token];
        });
    }
    public function assertOperation(Request $request,PrintBatch $batch):void {
        if(!$this->ready() || !$batch->automation_requested_at)return;
        $id=$this->identity($request);$token=(string)$request->header('X-Factory-Operation');
        abort_unless($batch->factory_agent_id===$id && $batch->agent_operation_token && hash_equals($batch->agent_operation_token,$token),409,'This worker does not own the active batch phase.');
    }
    public function readiness(?PrintBatch $batch=null):array {
        if(!$this->ready())return ['state'=>'setup_required','message'=>'Portal dispatch is not activated on the server. Ask an administrator to complete setup.'];
        $agent=$batch?->factory_agent_id?FactoryAgent::where('agent_id',$batch->factory_agent_id)->first():FactoryAgent::where('processing_enabled',true)->first();
        if(!$agent){
            $connected=FactoryAgent::orderByDesc('last_seen_at')->get()->first(fn($a)=>$this->online($a));
            if($connected && !$this->capable($connected))return ['state'=>'connection_only','message'=>'The PC connection monitor is online, but no portal listener is installed. It cannot process this queue. One-time PC setup is required.'];
            return ['state'=>'not_enabled','message'=>'No Factory PC is enabled for portal jobs. An administrator must enable a connected listener.'];
        }
        if(!$this->online($agent))return ['state'=>'offline','message'=>'Waiting for the Factory PC. It may be switched off, signed out, disconnected, or its background listener may be stopped. Nothing is confirmed as processing.'];
        if(!$this->capable($agent))return ['state'=>'connection_only','message'=>'Only a connection reporter is running. It cannot prepare or hand off this batch. Start the installed portal listener through the one-time PC setup.'];
        if(!$agent->processing_enabled)return ['state'=>'paused','message'=>'New jobs are paused in Admin Settings. The current operation, if any, may finish safely.'];
        return ['state'=>'ready','message'=>'The listener is ready. Authorized queue requests launch a temporary worker; it exits after the requested phase.'];
    }
    public function progress(PrintBatch $batch):array {
        $readiness=$this->readiness($batch);$state=$batch->status;$severity='info';$title='Queued — waiting for preparation';$message=$readiness['message'];$progress=null;
        $automatic=$this->ready() && (bool)$batch->automation_requested_at;
        $quantityBlocked=collect($batch->payload['orders']??[])->contains(fn($row)=>(int)($row['quantity']??0)!==1);
        if(!$automatic && $state==='QUEUED'){$title='Queued — automatic preparation not requested';$message='This is a legacy queue entry. Request automatic preparation here, or use the existing factory workflow.';}
        elseif($state==='QUEUED'){$title=$readiness['state']==='ready'?'Queued — waiting for the listener':'Pending — Factory PC not ready';$severity=$readiness['state']==='ready'?'info':'warning';}
        elseif(in_array($state,['CLAIMED','DOWNLOADING'],true)){$title='Preparing approved artwork';$message='The worker is downloading and validating the locked production originals. Physical printing has not started.';}
        elseif($state==='PREPARED'){$title=$quantityBlocked && $automatic?'Prepared — manual quantity handling required':'Prepared — handoff approval required';$message=$quantityBlocked && $automatic?'Automatic hot-folder delivery supports quantity one only. Do not change order quantities to bypass this check.':'Review and sign HANDOFF to authorize delivery to Epson. The preparation worker has finished.';}
        elseif($state==='HANDOFF_REQUESTED'){$title='Handoff authorized — waiting for delivery';$message=$readiness['state']==='ready'?'The listener will launch the delivery worker. This does not confirm physical printing.':$readiness['message'];}
        elseif($state==='DELIVERING'){$title='Delivering approved artwork to Epson';$message='The worker is validating and copying the approved originals. Physical printing is not confirmed.';}
        elseif($state==='SENT_TO_RIP'){$title='Delivered to Epson — physical printing not confirmed';$message='The delivery worker has finished. Review and print in Epson, then confirm the actual physical output in this portal. Epson does not report live printer progress here.';}
        elseif($state==='PRINTED'){$title='Physical print completion confirmed';$message='An operator signed the physical print confirmation.';$severity='success';}
        elseif($state==='CANCELLED'){$title='Cancelled — not queued';$message='This batch is history only. Returned items require new artwork approval.';}
        elseif($state==='FAILED'){$title='Processing failed — inspection required';$message=FactoryMonitoringService::ERROR_LABELS[$batch->agent_error_code??'']??'The operation failed or its outcome is uncertain. Inspect the Factory PC, Edge Print queue and handoff journals before retrying. Never blindly resubmit.';$severity='danger';}
        $agent=$this->ready() && $batch->factory_agent_id?FactoryAgent::where('agent_id',$batch->factory_agent_id)->first():null;
        if($agent && $this->online($agent) && ($agent->telemetry['reporter_mode']??'')==='managed_worker' && (int)($agent->telemetry['batch_id']??0)===$batch->id && in_array($state,['CLAIMED','DOWNLOADING','DELIVERING'],true)){
            $p=$agent->telemetry['progress']??null;
            if($p && (int)$p['total']===$batch->artwork_file_count && (($state==='DELIVERING')===($p['phase']==='publishing')))$progress=$p;
        }
        if($automatic && $agent && (in_array($state,['CLAIMED','DOWNLOADING','DELIVERING'],true) || ($state==='HANDOFF_REQUESTED' && $batch->agent_operation_phase==='handoff')) && ($agent->telemetry['reporter_mode']??'')==='listener' && $batch->agent_operation_started_at && \Illuminate\Support\Carbon::parse($batch->agent_operation_started_at)->lt(now()->subSeconds(30))){$title='Worker outcome unconfirmed — inspection required';$message='The listener is connected, but the assigned worker is not reporting this operation. It will not be launched again automatically. Inspect the Factory PC and Epson queue before recovery.';$severity='danger';$progress=null;}
        if(in_array($state,['CLAIMED','DOWNLOADING','HANDOFF_REQUESTED','DELIVERING'],true) && $automatic && $readiness['state']!=='ready'){$severity='warning';$message=$readiness['message'].' Current operation status is unconfirmed; do not requeue it.';}
        return ['status'=>$state,'title'=>$title,'message'=>$message,'severity'=>$severity,'worker_state'=>$readiness['state'],'progress'=>$progress,'file_count'=>$batch->artwork_file_count,'automatic'=>$automatic,
            'can_request'=>$state==='QUEUED' && !$automatic && !$batch->claimed_at && $this->ready(),'can_handoff'=>$state==='PREPARED' && !($automatic && $quantityBlocked),'can_confirm_printed'=>$state==='SENT_TO_RIP','server_time'=>now()->toIso8601String()];
    }
}
