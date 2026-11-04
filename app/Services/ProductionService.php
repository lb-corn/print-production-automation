<?php
namespace App\Services;use App\Models\{ProductionItem,VerificationDecision,PrintBatch};use Illuminate\Support\Facades\DB;
class ProductionService{
 const YARDS=.143;
 function materials():array {return app(MaterialCatalog::class)->map();}
 function materialCompatible($item):bool {$row=app(MaterialCatalog::class)->row($item->series_code);return $row && $row['type']===$item->design_type;}

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
 function batchIndex(bool $refresh=false):array {
  if($refresh || $this->batchIndexCache===null){
   $groups=[];$this->batchIndexCache=[];
   foreach(PrintBatch::withExists('confirmations')->where('status','!=','CANCELLED')->latest('id')->get() as $batch)foreach($batch->payload['orders']??[] as $order){
    if(empty($order['line_item_id']))continue;
    $groups[$order['line_item_id']][]=['id'=>$batch->id,'batch_id'=>$batch->batch_id,'status'=>$batch->status,'order_count'=>$batch->order_count,'material'=>$batch->payload['material_group']??null,'can_return'=>$batch->status==='QUEUED' && !$batch->claimed_at && !$batch->completed_at && !$batch->confirmations_exists];
   }
   foreach($groups as $line=>$entries){
    $pending=array_values(array_filter($entries,fn($e)=>$e['status']!=='PRINTED'));
    $selected=collect($pending)->firstWhere('status','FAILED')??$pending[0]??$entries[0];$selected['can_return']=!array_filter($entries,fn($e)=>!$e['can_return']);$selected['batches']=$entries;
    $this->batchIndexCache[$line]=$selected;
   }
  }
  return $this->batchIndexCache;
 }
 function processed($i): bool { return isset($this->batchIndex()[$i->line_item_id]); }
 function approved($i): bool { return ArtworkMatcherService::validSelection($i) && $i->decision?->decision === 'CORRECT' && count($this->ids($i)) && $this->current($i); }
 function ids($i){return collect($i->matches??[])->pluck('id')->filter()->sort()->values()->all();}
 function current($i){$d=$i->decision;return $d&&$this->ids($i)===collect($d->drive_file_ids??[])->sort()->values()->all();}
 function ready($i){return $this->approved($i) && !$this->processed($i) && $this->materialCompatible($i) && in_array($i->design_type,['single','double'],true) && (int)$i->quantity>0;}
 function verify($item,$decision,$uid){
  return DB::transaction(function() use($item,$decision,$uid) {
   $locked=ProductionItem::lockForUpdate()->findOrFail($item->id);
   $this->batchIndex(true);
   abort_if($this->processed($locked),422,'This order is already in a print batch. Review its batch status instead.');
   abort_if($locked->is_custom,422,'Custom artwork uses a separate approval journey.');
   $ids=$this->ids($locked);
   abort_unless(ArtworkMatcherService::validSelection($locked),422,'No complete, unambiguous production artwork selected. Rematch artwork first.');
   $this->workspaceStatsCache=null;
   return VerificationDecision::updateOrCreate(['line_item_id'=>$locked->line_item_id],['decision'=>$decision,'drive_file_ids'=>$ids,'updated_by'=>$uid]);
  });
 }
 function queue(){$this->batchIndex(true);return ProductionItem::with('decision')->get()->filter(fn($i)=>$this->ready($i))->values();}
 function summary($items): array {
  $printable = $nonprint = array_fill_keys(app(MaterialCatalog::class)->materials(), ['sides'=>0,'yards'=>0]); $sets = 0; $sides = 0; $files = 0;
  foreach ($items as $item) {
   $quantity = (int) $item->quantity;
   if(isset($item->artworks)){
    $sets+=$quantity;$files+=count($item->artworks);
    foreach($item->artworks as $art){$name=$art['material'];$printable[$name]['sides']=($printable[$name]['sides']??0)+$quantity;$sides+=$quantity;}
    if($item->design_type==='single' && !empty($item->backing_material)){$name=$item->backing_material;$nonprint[$name]['sides']=($nonprint[$name]['sides']??0)+$quantity;}
    continue;
   }
   $materials = $this->materials()[$item->series_code] ?? null;
   // Never publish a partial material total for an unsupported item.
   if (!$materials || !in_array($item->design_type, ['single','double'], true) || $quantity < 1) {
    throw new \LogicException('Cannot calculate material for production item '.$item->line_item_id.'. Check series, design type and quantity.');
   }
   $single = $item->design_type === 'single';
   $sets += $quantity;
   $sides += $quantity * ($single ? 1 : 2);
   $files += $single ? 1 : 2;
   $front = $materials[0]; $back = $materials[1]??($item->backing_material??null);
   $printable[$front]['sides'] = ($printable[$front]['sides'] ?? 0) + $quantity;
   if ($single) {if($back)$nonprint[$back]['sides'] = ($nonprint[$back]['sides'] ?? 0) + $quantity;}
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
 function batch($uid,?array $selected=null,bool $split=false){return DB::transaction(function()use($uid,$selected,$split){$items=ProductionItem::with('decision')->orderBy('id')->lockForUpdate()->get();$this->batchIndex(true);$items=$items->filter(fn($i)=>$this->ready($i))->values();if($selected!==null){$selected=array_values(array_unique(array_map('intval',$selected)));abort_if(!$selected,422,'Select approved items to queue.');$items=$items->whereIn('id',$selected)->values();abort_unless($items->count()===count($selected),422,'One or more selected items are no longer approved or available. Refresh the queue.');}abort_if($items->isEmpty(),422,'No approved stock orders ready.');abort_if(!$split && $selected!==null && $items->map(fn($i)=>PrintLabelService::group($i))->unique()->count()>1,422,'Choose items from one printed-material group per queue request. Front/back pairs stay together.');$orders=[];$fc=0;foreach($items as $i){$r=$this->materials()[$i->series_code]??null;abort_if(!$r,422,'Material mapping missing.');$ms=collect($i->matches??[]);$arts=[];if($i->design_type==='single'){$m=$ms->first();abort_if(!$m,422,'Single artwork missing.');$arts[]=$this->art('PRINT',$r[0],$m);$fc++;}else{$f=$ms->firstWhere('side','front');$b=$ms->firstWhere('side','back');abort_if(!$f||!$b,422,'Double artwork incomplete.');$arts[]=$this->art('FRONT',$r[0],$f);$arts[]=$this->art('BACK',$r[1],$b);$fc+=2;}if($selected!==null)$arts=array_map(function($art)use($i){if(!in_array(strtolower(pathinfo((string)($art['filename']??''),PATHINFO_EXTENSION)),['jpg','jpeg','png','tif','tiff'],true))throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'Automatic print labels require approved JPEG, PNG or single-page TIFF originals. Convert and review unsupported artwork before queueing.']);$art['print_label']=PrintLabelService::label($i,$art['material']);return $art;},$arts);$orders[]=['material_settings'=>app(MaterialCatalog::class)->row($i->series_code),'backing_material'=>$r[1],'color'=>$i->color,'line_item_id'=>$i->line_item_id,'order'=>$i->order_number,'product'=>$i->product,'variant'=>$i->variant,'sku'=>$i->sku,'series_code'=>$i->series_code,'design_type'=>$i->design_type,'quantity'=>$i->quantity,'artworks'=>$arts,'artwork_approval'=>['user_id'=>$i->decision->updated_by,'account_name'=>$i->decision->user?->name,'approved_at'=>$i->decision->updated_at?->toIso8601String(),'drive_file_ids'=>$i->decision->drive_file_ids]];}abort_if(!$orders,422,'No supported approved stock orders ready.');$id='LB-BATCH-'.now('UTC')->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,4));$payload=[...($selected!==null?['print_label_policy'=>PrintLabelService::POLICY,'material_group'=>PrintLabelService::group($items->first())]:[]),'batch_id'=>$id,'status'=>'QUEUED','created_at'=>now('UTC')->toIso8601String(),'yards_per_side_per_set'=>self::YARDS,'order_count'=>count($orders),'artwork_file_count'=>$fc,'orders'=>$orders];if($split)return $this->createMaterialBatches($uid,$orders);$batch=PrintBatch::create(['batch_id'=>$id,'status'=>'QUEUED','order_count'=>count($orders),'artwork_file_count'=>$fc,'total_yards'=>$this->summary($items)['total_yards'],'payload'=>$payload,'created_by'=>$uid]);$this->batchIndexCache=null;$this->workspaceStatsCache=null;return $batch;});}
 function batchByMaterial($uid,array $selected):array {
  app(MaterialCatalog::class)->rows(true); // Fresh sheet required at the authorization boundary.
  return $this->batch($uid,$selected,true);
 }
 private function createMaterialBatches($uid,array $orders):array {
  $groups=[];foreach($orders as $order)foreach($order['artworks'] as $art){$key=$art['material'];$line=$order['line_item_id'];if(!isset($groups[$key][$line]))$groups[$key][$line]=[...$order,'artworks'=>[]];$groups[$key][$line]['artworks'][]=$art;}
  ksort($groups);$group=(string)\Illuminate\Support\Str::uuid();$created=[];
  foreach($groups as $material=>$byLine){
   $locked=array_values($byLine);$files=array_sum(array_map(fn($o)=>count($o['artworks']),$locked));
   $sides=array_sum(array_map(fn($o)=>count($o['artworks'])*(int)$o['quantity'],$locked));
   $id='LB-BATCH-'.now('UTC')->format('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(4)));
   $payload=['company'=>'Lucky Bags','dispatch_group'=>$group,'material_group'=>$material,'material_catalog_sha256'=>app(MaterialCatalog::class)->fingerprint(),'print_label_policy'=>PrintLabelService::POLICY,'batch_id'=>$id,'status'=>'QUEUED','created_at'=>now('UTC')->toIso8601String(),'yards_per_side_per_set'=>self::YARDS,'order_count'=>count($locked),'artwork_file_count'=>$files,'orders'=>$locked];
   $created[]=PrintBatch::create(['batch_id'=>$id,'status'=>'QUEUED','order_count'=>count($locked),'artwork_file_count'=>$files,'total_yards'=>round($sides*self::YARDS,3),'payload'=>$payload,'created_by'=>$uid]);
  }
  $this->batchIndexCache=null;$this->workspaceStatsCache=null;return $created;
 }
 function art($s,$mat,$m){return ['side'=>$s,'material'=>$mat,'drive_file_id'=>$m['id']??null,'filename'=>$m['name']??null,'md5_checksum'=>$m['md5Checksum']??null,'drive_version'=>$m['version']??null,'drive_link'=>$m['webViewLink']??null,'thumbnail'=>isset($m['id'])?'https://drive.google.com/thumbnail?id='.$m['id'].'&sz=w800':null];}
}
