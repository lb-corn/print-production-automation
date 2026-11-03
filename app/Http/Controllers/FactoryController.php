<?php
namespace App\Http\Controllers;

use App\Models\PrintBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FactoryController extends Controller
{
    public function next()
    {
        $query=PrintBatch::whereIn('status', ['QUEUED','HANDOFF_REQUESTED']);
        if(app(\App\Services\FactoryDispatchService::class)->ready())$query->whereNull('automation_requested_at');
        if(app(\App\Services\FactoryDispatchService::class)->consentReady() && \App\Models\FactoryAgent::where('handoff_blocked',true)->exists())$query->where('status','QUEUED');
        $batch = $query->oldest()->first();
        if ($batch) {
            $batch->setAttribute('handoff_authorized', $batch->confirmations()->where('action','HANDOFF')->exists());
        }
        return response()->json(['ok'=>true,'batch'=>$batch]);
    }

    public function status(Request $request, PrintBatch $batch)
    {
        // Physical completion is exclusively a signed-in human action in the app.
        $data=$request->validate(['status'=>'required|in:CLAIMED,DOWNLOADING,PREPARED,DELIVERING,SENT_TO_RIP,FAILED','error_code'=>['nullable',\Illuminate\Validation\Rule::in(\App\Services\FactoryMonitoringService::ERRORS)]]);$state=$data['status'];
        return DB::transaction(function () use ($request,$batch,$state,$data) {
            if($state==='DELIVERING'){
                $gate=\App\Models\FactoryAgent::where('agent_id',$batch->factory_agent_id)->lockForUpdate()->first();
                abort_if($gate?->handoff_blocked,423,'Handoff is blocked by an administrator.');
            }
            $locked = PrintBatch::lockForUpdate()->findOrFail($batch->id);
            $dispatch=app(\App\Services\FactoryDispatchService::class);$dispatch->assertOperation($request,$locked);
            if($state==='DELIVERING')$dispatch->assertHandoffAllowed($locked);
            if($state==='DELIVERING')abort_unless($dispatch->ready() && $locked->automation_requested_at && $locked->agent_operation_phase==='handoff',422,'A managed handoff operation is required.');
            if($dispatch->ready() && $locked->automation_requested_at && $state==='SENT_TO_RIP')abort_unless($locked->status==='DELIVERING',409,'The managed handoff phase has not started.');
            $allowed = [
                'QUEUED'=>['CLAIMED'],
                'CLAIMED'=>['DOWNLOADING','FAILED'],
                'DOWNLOADING'=>['PREPARED','FAILED'],
                'HANDOFF_REQUESTED'=>['DELIVERING','SENT_TO_RIP','FAILED'],
                'DELIVERING'=>['SENT_TO_RIP','FAILED'],
            ];
            abort_unless(in_array($state,$allowed[$locked->status] ?? [],true),409,'Batch state changed or transition is not permitted.');
            if ($state === 'SENT_TO_RIP') {
                abort_unless($locked->confirmations()->where('action','HANDOFF')->exists(),422,'Human handoff confirmation is missing.');
            }
            $locked->status = $state;
            if($dispatch->ready() && $state==='FAILED')$locked->agent_error_code=$data['error_code']??'batch_operation_failed';
            if ($state === 'CLAIMED') $locked->claimed_at = now();
            $locked->save();
            return response()->json(['ok'=>true,'batch'=>$locked]);
        });
    }
    public function work(Request $request,\App\Services\FactoryDispatchService $dispatch){
        return response()->json(['ok'=>true,'job'=>$dispatch->next($dispatch->identity($request))])->header('Cache-Control','no-store');
    }
    public function beginWork(Request $request,PrintBatch $batch,\App\Services\FactoryDispatchService $dispatch){
        $phase=$request->validate(['phase'=>'required|in:prepare,handoff'])['phase'];
        return response()->json(['ok'=>true,'operation'=>$dispatch->begin($request,$batch,$phase)])->header('Cache-Control','no-store');
    }
    public function assigned(Request $request,PrintBatch $batch,\App\Services\FactoryDispatchService $dispatch){
        $dispatch->assertOperation($request,$batch);
        abort_unless($dispatch->ready() && $batch->automation_requested_at && (($batch->agent_operation_phase==='prepare' && $batch->status==='CLAIMED') || ($batch->agent_operation_phase==='handoff' && $batch->status==='HANDOFF_REQUESTED')),409,'This operation is no longer available to start.');
        $batch->setAttribute('handoff_authorized',$batch->confirmations()->where('action','HANDOFF')->exists());
        return response()->json(['ok'=>true,'batch'=>$batch])->header('Cache-Control','no-store');
    }
}
