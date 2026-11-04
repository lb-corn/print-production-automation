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
    // Metadata only: the PC downloads originals directly from Drive. Nothing is uploaded here.
    public function syncArtwork(Request $request,\App\Services\FactoryDispatchService $dispatch){
        $dispatch->identity($request);
        $data=$request->validate(['after'=>'nullable|integer|min:0']);$after=(int)($data['after']??0);
        $items=\App\Models\ProductionItem::where('id','>',$after)->orderBy('id')->limit(100)->get();
        $processed=app(\App\Services\ProductionService::class)->batchIndex(true);
        $orders=[];
        foreach($items as $item){
            if((int)$item->quantity<1 || (int)$item->quantity>10000 || isset($processed[$item->line_item_id]) || !\App\Services\ArtworkMatcherService::validSelection($item) || !isset(\App\Services\ProductionService::MATERIALS[$item->series_code]))continue;
            $materials=\App\Services\ProductionService::MATERIALS[$item->series_code];$arts=[];
            foreach($item->matches as $match){
                $side=$item->design_type==='single'?'PRINT':strtoupper($match['side']);
                $material=$materials[$side==='BACK'?1:0];
                $art=app(\App\Services\ProductionService::class)->art($side,$material,$match);
                if(!in_array(strtolower(pathinfo((string)$art['filename'],PATHINFO_EXTENSION)),['jpg','jpeg','png','tif','tiff'],true) || !$art['md5_checksum'] || !$art['drive_version'])continue;
                $art['print_label']=\App\Services\PrintLabelService::label($item,$material);$arts[]=$art;
            }
            if($arts)$orders[]=['line_item_id'=>$item->line_item_id,'order'=>$item->order_number,'quantity'=>(int)$item->quantity,'artworks'=>$arts];
        }
        return response()->json(['orders'=>$orders,'next_after'=>$items->count()===100?$items->last()->id:0])->header('Cache-Control','no-store');
    }
    public function completion(Request $request,PrintBatch $batch,\App\Services\FactoryDispatchService $dispatch){
        $id=$dispatch->identity($request);
        abort_unless($batch->factory_agent_id && hash_equals($batch->factory_agent_id,$id),403);
        $confirmation=$batch->confirmations()->where('action','PRINTED')->latest('id')->first();
        $valid=$batch->status==='PRINTED' && $batch->completed_at && $confirmation &&
            hash_equals($confirmation->payload_sha256,hash('sha256',json_encode($batch->payload))) &&
            (int)$confirmation->order_count===(int)$batch->order_count && (int)$confirmation->artwork_file_count===(int)$batch->artwork_file_count;
        if(!$valid)return response()->json(['cleanup_authorized'=>false])->header('Cache-Control','no-store');
        return response()->json(['cleanup_authorized'=>true,'id'=>$batch->id,'batch_id'=>$batch->batch_id,'status'=>'PRINTED','confirmation_id'=>$confirmation->id,'payload'=>$batch->payload])->header('Cache-Control','no-store');
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
