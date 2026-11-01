<?php
namespace App\Services;

use App\Models\{PrintBatch,ProductionItem,VerificationDecision};
use Illuminate\Support\Facades\DB;

class BatchReturnService
{
    /** Cancel only an untouched queue entry, preserving the original batch snapshot. */
    public function returnToReview(PrintBatch $batch,int $actor): int
    {
        return DB::transaction(function() use($batch,$actor) {
            $snapshot=PrintBatch::findOrFail($batch->id);
            $ids=array_values(array_unique(array_filter(array_column($snapshot->payload['orders']??[],'line_item_id'))));
            abort_unless($ids,422,'This batch has no production items to return.');
            $items=ProductionItem::whereIn('line_item_id',$ids)->orderBy('id')->lockForUpdate()->get();
            $locked=PrintBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status==='QUEUED' && !$locked->claimed_at && !$locked->completed_at && !$locked->confirmations()->exists(),409,'This batch is no longer an untouched queue entry. Claimed or processed batches cannot be returned.');
            abort_unless($locked->payload===$snapshot->payload && $items->count()===count($ids),409,'Batch contents changed or production items are unavailable. Refresh the batch.');
            foreach(PrintBatch::where('id','!=',$locked->id)->where('status','!=','CANCELLED')->get() as $other) {
                abort_if(array_intersect($ids,array_column($other->payload['orders']??[],'line_item_id')),409,'An item is also referenced by another print batch. Operator review is required.');
            }
            VerificationDecision::whereIn('line_item_id',$ids)->delete();
            foreach($items as $item)$item->update(['matches'=>[],'match_status'=>'MISSING','confidence'=>null,'artwork_review'=>null]);
            $payload=$locked->payload;
            $payload['returned_to_review']=['user_id'=>$actor,'at'=>now('UTC')->toIso8601String(),'line_item_ids'=>$ids,'reason'=>'Operator returned an unclaimed batch for artwork correction'];
            $locked->update(['status'=>'CANCELLED','payload'=>$payload]);
            return $items->count();
        });
    }
}
