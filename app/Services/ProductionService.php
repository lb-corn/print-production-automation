<?php
namespace App\Services;use App\Models\{ProductionItem,VerificationDecision,PrintBatch};use Illuminate\Support\Facades\DB;
class ProductionService{
 const YARDS=.143; const MATERIALS=['SUP'=>['Rave 9','Supreme 5'],'LUC'=>['Rave 8','Anchorage 5'],'CLV'=>['Rave 9','Lucky 5'],'ELT-S'=>['Rave 9','Viewmont 6'],'PSE'=>['Rave 9','Viewmont 6'],'SRF-ELT'=>['Rave 8','Malley 5'],'SRF'=>['Rave 8','Embry 5'],'CLT'=>['Rave 8','Rave 9'],'SNP'=>['Turbo 7','Rave 9'],'PSN'=>['Turbo 6','Rave 9']];
 private ?array $batchIndexCache = null;
 private ?array $workspaceStatsCache = null;
 function workspaceStats(bool $refresh = false): array {
  if ($refresh || $this->workspaceStatsCache === null) {
   $index=$this->batchIndex($refresh);
   $items=ProductionItem::with('decision')->get();
   $this->workspaceStatsCache=[
    'ready'=>$items->filter(fn($item)=>$this->ready($item))->count(),
    'in_print'=>count(array_filter($index,fn($batch)=>!in_array($batch['status'],['PRINTED','FAILED'],true))),
    'completed'=>count(array_filter($index,fn($batch)=>$batch['status']==='PRINTED')),
   ];
  }
  return $this->workspaceStatsCache;
 }
 function batchIndex(bool $refresh = false): array {
  if ($refresh || $this->batchIndexCache === null) {
   $this->batchIndexCache = [];
   foreach (PrintBatch::latest('id')->get() as $batch) {
    foreach ($batch->payload['orders'] ?? [] as $order) {
     $key = $order['line_item_id'] ?? null;
     if (!$key) continue;
     if (!isset($this->batchIndexCache[$key]) || $batch->status === 'PRINTED') {
      $this->batchIndexCache[$key] = ['id'=>$batch->id, 'batch_id'=>$batch->batch_id, 'status'=>$batch->status];
     }
    }
   }
  }
  return $this->batchIndexCache;
 }
 function processed($i): bool { return isset($this->batchIndex()[$i->line_item_id]); }
 function approved($i): bool { return !$i->is_custom && $i->decision?->decision === 'CORRECT' && count($this->ids($i)) && $this->current($i); }
 function ids($i){return collect($i->matches??[])->pluck('id')->filter()->sort()->values()->all();}
 function current($i){$d=$i->decision;return $d&&$this->ids($i)===collect($d->drive_file_ids??[])->sort()->values()->all();}
 function ready($i){return $this->approved($i) && !$this->processed($i);}
 function verify($item,$decision,$uid){
  return DB::transaction(function() use($item,$decision,$uid) {
   $locked=ProductionItem::lockForUpdate()->findOrFail($item->id);
   $this->batchIndex(true);
   abort_if($this->processed($locked),422,'This order is already in a print batch. Review its batch status instead.');
   abort_if($locked->is_custom,422,'Custom artwork uses a separate approval journey.');
   $ids=$this->ids($locked);
   abort_if(!$ids,422,'No Drive artwork available.');
   $this->workspaceStatsCache=null;
   return VerificationDecision::updateOrCreate(['line_item_id'=>$locked->line_item_id],['decision'=>$decision,'drive_file_ids'=>$ids,'updated_by'=>$uid]);
  });
 }
 function queue(){$this->batchIndex(true);return ProductionItem::with('decision')->get()->filter(fn($i)=>$this->ready($i))->values();}
 function summary($items): array {
  $printable = []; $nonprint = []; $sets = 0; $sides = 0; $files = 0;
  foreach ($items as $item) {
   $quantity = (int) $item->quantity;
   $materials = self::MATERIALS[$item->series_code] ?? null;
   // Never publish a partial material total for an unsupported item.
   if (!$materials || !in_array($item->design_type, ['single','double'], true) || $quantity < 1) {
    throw new \LogicException('Cannot calculate material for production item '.$item->line_item_id.'. Check series, design type and quantity.');
   }
   $single = $item->design_type === 'single';
   $sets += $quantity;
   $sides += $quantity * ($single ? 1 : 2);
   $files += $single ? 1 : 2;
   $front = $materials[0]; $back = $materials[1];
   $printable[$front]['sides'] = ($printable[$front]['sides'] ?? 0) + $quantity;
   if ($single) $nonprint[$back]['sides'] = ($nonprint[$back]['sides'] ?? 0) + $quantity;
   else $printable[$back]['sides'] = ($printable[$back]['sides'] ?? 0) + $quantity;
  }
  // Sum integer side counts first and round once for each material.
  foreach ($printable as &$material) $material['yards'] = round($material['sides'] * self::YARDS, 3);
  unset($material);
  foreach ($nonprint as &$material) $material['yards'] = round($material['sides'] * self::YARDS, 3);
  unset($material);
  ksort($printable); ksort($nonprint);
  $printYards = round($sides * self::YARDS, 3);
  $backYards = round(array_sum(array_column($nonprint, 'sides')) * self::YARDS, 3);
  return ['approved_orders'=>$items->count(), 'total_sets'=>$sets, 'printable_sides'=>$sides, 'artwork_files'=>$files,
   'printable_materials'=>$printable, 'nonprint_materials'=>$nonprint, 'printable_yards'=>$printYards,
   'nonprint_yards'=>$backYards, 'total_yards'=>round($printYards + $backYards, 3)];
 }
 function batch($uid){return DB::transaction(function()use($uid){$items=ProductionItem::with('decision')->lockForUpdate()->get();$this->batchIndex(true);$items=$items->filter(fn($i)=>$this->ready($i))->values();abort_if($items->isEmpty(),422,'No approved stock orders ready.');$orders=[];$fc=0;foreach($items as $i){$r=self::MATERIALS[$i->series_code]??null;abort_if(!$r,422,'Material mapping missing.');$ms=collect($i->matches??[]);$arts=[];if($i->design_type==='single'){$m=$ms->first();abort_if(!$m,422,'Single artwork missing.');$arts[]=$this->art('PRINT',$r[0],$m);$fc++;}else{$f=$ms->firstWhere('side','front');$b=$ms->firstWhere('side','back');abort_if(!$f||!$b,422,'Double artwork incomplete.');$arts[]=$this->art('FRONT',$r[0],$f);$arts[]=$this->art('BACK',$r[1],$b);$fc+=2;}$orders[]=['line_item_id'=>$i->line_item_id,'order'=>$i->order_number,'product'=>$i->product,'variant'=>$i->variant,'sku'=>$i->sku,'series_code'=>$i->series_code,'design_type'=>$i->design_type,'quantity'=>$i->quantity,'artworks'=>$arts,'artwork_approval'=>['user_id'=>$i->decision->updated_by,'account_name'=>$i->decision->user?->name,'approved_at'=>$i->decision->updated_at?->toIso8601String(),'drive_file_ids'=>$i->decision->drive_file_ids]];}abort_if(!$orders,422,'No supported approved stock orders ready.');$id='LB-BATCH-'.now('UTC')->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,4));$payload=['batch_id'=>$id,'status'=>'QUEUED','created_at'=>now('UTC')->toIso8601String(),'yards_per_side_per_set'=>self::YARDS,'order_count'=>count($orders),'artwork_file_count'=>$fc,'orders'=>$orders];$batch=PrintBatch::create(['batch_id'=>$id,'status'=>'QUEUED','order_count'=>count($orders),'artwork_file_count'=>$fc,'total_yards'=>$this->summary($items)['total_yards'],'payload'=>$payload,'created_by'=>$uid]);$this->batchIndexCache=null;$this->workspaceStatsCache=null;return $batch;});}
 function art($s,$mat,$m){return ['side'=>$s,'material'=>$mat,'drive_file_id'=>$m['id']??null,'filename'=>$m['name']??null,'drive_link'=>$m['webViewLink']??null,'thumbnail'=>isset($m['id'])?'https://drive.google.com/thumbnail?id='.$m['id'].'&sz=w800':null];}
}
