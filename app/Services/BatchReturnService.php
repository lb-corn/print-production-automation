<?php
namespace App\Services;

use App\Models\{PrintBatch,ProductionItem,VerificationDecision};
use Illuminate\Support\Facades\DB;

class BatchReturnService
{
    public function returnItemToReview(PrintBatch $batch,ProductionItem $item,int $actor): int { return $this->returnItems($batch,$actor,$item->line_item_id); }

    /** Supersede the untouched snapshot so a stale factory poll can never claim removed items. */
    private function returnItems(PrintBatch $batch,int $actor,string $selected): int
    {
        return DB::transaction(function() use($batch,$actor,$selected) {
            $snapshot=PrintBatch::findOrFail($batch->id);
            $orders=$snapshot->payload['orders']??[];
            $allIds=array_values(array_unique(array_filter(array_column($orders,'line_item_id'))));
            abort_unless($allIds,422,'This batch has no production items to return.');
            abort_if(!in_array($selected,$allIds,true),422,'The selected item is not in this batch.');
            $items=ProductionItem::whereIn('line_item_id',$allIds)->orderBy('id')->lockForUpdate()->get();
            $locked=PrintBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status==='QUEUED' && !$locked->claimed_at && !$locked->completed_at && !$locked->confirmations()->exists(),409,'This batch is no longer an untouched queue entry. Claimed or processed batches cannot be returned.');
            abort_unless($locked->payload===$snapshot->payload && $items->count()===count($allIds),409,'Batch contents changed or production items are unavailable. Refresh the batch.');
            foreach(PrintBatch::where('id','!=',$locked->id)->where('status','!=','CANCELLED')->get() as $other) {
                abort_if(array_intersect($allIds,array_column($other->payload['orders']??[],'line_item_id')),409,'An item is also referenced by another print batch. Operator review is required.');
            }
            $ids=[$selected];
            $remaining=array_values(array_filter($orders,fn($order)=>!in_array($order['line_item_id'],$ids,true)));
            $replacement=null;
            if($remaining) {
                // Use the original approved snapshots, never fresh mutable item fields.
                $summary=(new ProductionService())->summary(collect($remaining)->map(fn($order)=>(object)$order));
                $files=array_sum(array_map(fn($order)=>count($order['artworks']),$remaining));
                abort_unless($summary['artwork_files']===$files,422,'The retained artwork snapshot is incomplete.');
                $newId='LB-BATCH-'.now('UTC')->format('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
                $payload=[...$locked->payload,'batch_id'=>$newId,'status'=>'QUEUED','created_at'=>now('UTC')->toIso8601String(),'order_count'=>count($remaining),'artwork_file_count'=>$files,'orders'=>$remaining,'replaces_batch_id'=>$locked->batch_id];
                $replacement=PrintBatch::create(['batch_id'=>$newId,'status'=>'QUEUED','order_count'=>count($remaining),'artwork_file_count'=>$files,'total_yards'=>$summary['total_yards'],'payload'=>$payload,'created_by'=>$locked->created_by]);
            }
            VerificationDecision::whereIn('line_item_id',$ids)->delete();
            foreach($items->whereIn('line_item_id',$ids) as $item)$item->update(['matches'=>[],'match_status'=>'MISSING','confidence'=>null,'artwork_review'=>null]);
            $payload=$locked->payload;
            $payload['returned_to_review']=['user_id'=>$actor,'at'=>now('UTC')->toIso8601String(),'line_item_ids'=>$ids,'scope'=>'item','replacement_batch_id'=>$replacement?->id,'reason'=>'Operator returned selected unclaimed artwork for correction'];
            $locked->update(['status'=>'CANCELLED','payload'=>$payload]);
            return count($ids);
        });
    }
}
