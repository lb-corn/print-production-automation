<?php
namespace App\Http\Controllers;
use App\Models\{CustomArtworkTransfer as Transfer,ProductionItem,FactoryAgent,PrintBatch};
use App\Services\{CustomArtworkService as Custom,MaterialCatalog,PrintLabelService,FactoryDispatchService,ProductionService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CustomArtworkController extends Controller {
 private function metadataOnly(Request $r):void {abort_unless($r->isJson(),415,'Only JSON connection metadata is accepted. Image bytes transfer directly to the PC.');abort_if(strlen($r->getContent())>131072 || $r->allFiles(),413,'This endpoint accepts connection metadata only. Images transfer directly to the Print PC.');}
 private function checkItem(ProductionItem $item):void {abort_unless(Custom::ready(),422,'The server needs the custom-artwork database update.');abort_unless($item->is_custom,422);abort_if(app(ProductionService::class)->processed($item),409,'This order is already queued.');}
 public function create(Request $r,ProductionItem $item){
  $this->metadataOnly($r);abort_unless(Custom::ready() && $item->is_custom,422,'Custom artwork setup is unavailable.');
  $data=$r->validate(['backing_color'=>'nullable|string|max:100','backing_context'=>'nullable|string|size:64','id'=>'required|uuid','kind'=>'required|in:UPLOAD,PREVIEW','parent_id'=>'nullable|uuid','side'=>'required|in:single,front,back','filename'=>'nullable|string|max:160','bytes'=>'nullable|integer|min:1|max:104857600','sha256'=>'nullable|regex:/^[a-f0-9]{64}$/','offer'=>'required|string|max:65536']);
  if($data['kind']==='UPLOAD')$this->checkItem($item);
  abort_unless(str_starts_with($data['offer'],'v=0') && substr_count($data['offer'],'m=application ')===1 && !preg_match('/^m=(?!application )/m',$data['offer']),422,'Only one file data channel is permitted.');
  $parent=null;
  if($data['kind']==='PREVIEW'){
   $parent=Transfer::findOrFail($data['parent_id']??'');abort_unless($parent->production_item_id===$item->id && $parent->kind==='UPLOAD' && $parent->side===$data['side'] && in_array($parent->state,['READY','APPROVED'],true) && ($parent->created_by===$r->user()->id || in_array($parent->id,array_column($item->matches??[],'upload_id'),true)),403);
   $agent=FactoryAgent::where('agent_id',$parent->factory_agent_id)->first();$metadata=$parent->metadata;
  }else{
   $catalog=app(MaterialCatalog::class);$catalog->rows(true);if(\App\Services\BackingColorService::required($item)){app(\App\Services\BackingColorService::class)->confirm($item,(string)($data['backing_color']??''),(string)($data['backing_context']??''),$r->user()->id);$item->refresh();}$definition=$catalog->row($item->series_code);
   abort_unless($definition && $definition['type']===$item->design_type,422,'Sync this custom bag again to use current material settings.');
   $sides=$item->design_type==='double'?['front','back']:['single'];abort_unless(in_array($data['side'],$sides,true),422,'This bag does not use that print side.');
   abort_unless(isset($data['bytes'],$data['sha256'],$data['filename']) && in_array(strtolower(pathinfo($data['filename'],PATHINFO_EXTENSION)),['jpg','jpeg','png','tif','tiff'],true) && !preg_match('/[\x00-\x1F\x7F]/',$data['filename']),422,'Choose a production JPEG, PNG or single-page TIFF.');
   $connected=FactoryAgent::where('last_seen_at','>',now()->subSeconds(120))->get()->filter(fn($a)=>app(FactoryDispatchService::class)->capable($a) && ($a->telemetry['process_status']??'')!=='stopping');
   abort_unless($connected->count()===1,422,'Exactly one Print PC must be connected for direct artwork upload.');$agent=$connected->first();
   $material=$definition[$data['side']==='back'?'back':'front'];
   $metadata=['filename'=>$data['filename'],'bytes'=>(int)$data['bytes'],'sha256'=>$data['sha256'],'context_sha256'=>Custom::context($item),'material'=>$material,'print_label'=>PrintLabelService::label($item,$material),'print_label_policy'=>PrintLabelService::POLICY];
  }
  abort_unless($agent && $agent->last_seen_at->gt(now()->subSeconds(120)) && version_compare($agent->telemetry['agent_version']??'0.0.0','1.6.1','>='),422,'Update and connect the Print PC agent to version 1.6.1 for direct custom artwork transfer.');
  return DB::transaction(function()use($r,$item,$data,$agent,$metadata,$parent){
   FactoryAgent::whereKey($agent->id)->lockForUpdate()->first();
   abort_if(Transfer::where('factory_agent_id',$agent->agent_id)->whereIn('state',['WAITING','CLAIMED','CONNECTED'])->where('expires_at','>',now())->exists(),409,'Another direct artwork transfer is active. Finish it before sending this file.');
   abort_if(Transfer::whereKey($data['id'])->exists(),409,'This transfer already exists. Start a new upload.');
   $transfer=Transfer::create(['id'=>$data['id'],'production_item_id'=>$item->id,'created_by'=>$r->user()->id,'factory_agent_id'=>$agent->agent_id,'kind'=>$data['kind'],'parent_id'=>$parent?->id,'side'=>$data['side'],'state'=>'WAITING','metadata'=>$metadata,'offer'=>$data['offer'],'expires_at'=>now()->addMinutes(10)]);
   return response()->json(['id'=>$transfer->id,'state'=>$transfer->state]);
  });
 }
 public function status(Request $r,Transfer $transfer){abort_unless($transfer->created_by===$r->user()->id,403);return response()->json(['id'=>$transfer->id,'state'=>$transfer->expires_at->isPast() && in_array($transfer->state,['WAITING','CLAIMED','CONNECTED'],true)?'EXPIRED':$transfer->state,'answer'=>$transfer->answer,'error_code'=>$transfer->error_code,'receipt'=>$transfer->receipt])->header('Cache-Control','no-store');}
 public function cancel(Request $r,Transfer $transfer){abort_unless($transfer->created_by===$r->user()->id,403);if(in_array($transfer->state,['WAITING','CLAIMED','CONNECTED'],true))$transfer->update(['state'=>'FAILED','error_code'=>'cancelled','offer'=>null,'answer'=>null]);return response()->json(['ok'=>true]);}
 public function confirm(Request $r,ProductionItem $item){$this->metadataOnly($r);$data=$r->validate(['backing_color'=>'nullable|string|max:100','backing_context'=>'nullable|string|size:64','confirm_custom'=>'required|accepted','uploads'=>'required|array|min:1|max:2','uploads.*'=>'required|uuid']);$this->checkItem($item);DB::transaction(function()use($item,$data,$r){$locked=ProductionItem::lockForUpdate()->findOrFail($item->id);if(\App\Services\BackingColorService::required($locked))abort_unless(\App\Services\BackingColorService::confirmed($locked) && ($data['backing_color']??null)===$locked->backing_selection['color'] && ($data['backing_context']??null)===\App\Services\BackingColorService::context($locked),422,'Backing color changed after upload. Upload the print copy again with the selected color.');Custom::confirm($locked,$data['uploads'],$r->user()->id);});return response()->json(['ok'=>true,'message'=>'Custom artwork approved and stored on the Print PC. Ready to queue by material.']);}
 public function pending(Request $r,FactoryDispatchService $dispatch){$id=$dispatch->identity($r);if(!Custom::ready())return response()->json(['transfer'=>null]);Transfer::whereIn('state',['WAITING','CLAIMED','CONNECTED'])->where('expires_at','<',now())->update(['state'=>'FAILED','offer'=>null,'answer'=>null,'error_code'=>'transfer_failed']);$t=Transfer::where('factory_agent_id',$id)->where('state','WAITING')->where('expires_at','>',now())->oldest()->first();return response()->json(['transfer'=>$t?['id'=>$t->id]:null])->header('Cache-Control','no-store');}
 public function begin(Request $r,Transfer $transfer,FactoryDispatchService $dispatch){$id=$dispatch->identity($r);return DB::transaction(function()use($transfer,$id){$t=Transfer::lockForUpdate()->findOrFail($transfer->id);abort_unless($t->factory_agent_id===$id,403);abort_unless($t->state==='WAITING' && $t->expires_at->isFuture(),409,'Transfer expired or already started.');$t->update(['state'=>'CLAIMED']);return response()->json(['id'=>$t->id,'kind'=>$t->kind,'parent_id'=>$t->parent_id,'side'=>$t->side,'metadata'=>$t->metadata,'offer'=>$t->offer]);});}
 public function report(Request $r,Transfer $transfer,FactoryDispatchService $dispatch){
  $this->metadataOnly($r);abort_unless($transfer->factory_agent_id===$dispatch->identity($r),403);
  $data=$r->validate(['state'=>'required|in:CONNECTED,READY,FAILED','answer'=>'nullable|string|max:65536','error_code'=>'nullable|in:transfer_failed,invalid_artwork,cancelled','receipt'=>'nullable|array','receipt.source_sha256'=>'nullable|regex:/^[a-f0-9]{64}$/','receipt.output_sha256'=>'nullable|regex:/^[a-f0-9]{64}$/','receipt.output_bytes'=>'nullable|integer|min:1|max:104857600','receipt.width'=>'nullable|integer|min:100|max:20000','receipt.height'=>'nullable|integer|min:100|max:20000']);
  return DB::transaction(function()use($transfer,$data){$t=Transfer::lockForUpdate()->findOrFail($transfer->id);abort_unless($t->expires_at->isFuture() && in_array($t->state,['CLAIMED','CONNECTED'],true),409,'Transfer is no longer active.');
   if($data['state']==='CONNECTED')abort_unless($t->state==='CLAIMED' && !empty($data['answer']) && str_starts_with($data['answer'],'v=0') && substr_count($data['answer'],'m=application ')===1,422);
   if($data['state']==='READY' && $t->kind==='UPLOAD')abort_unless(($data['receipt']['source_sha256']??null)===$t->metadata['sha256'] && !empty($data['receipt']['output_sha256']) && !empty($data['receipt']['output_bytes']) && !empty($data['receipt']['width']) && !empty($data['receipt']['height']),422,'Validated source receipt does not match the offered file.');
   $values=['state'=>$data['state'],'error_code'=>$data['error_code']??null];if($data['state']==='CONNECTED')$values['answer']=$data['answer'];else{$values['offer']=null;$values['answer']=null;}$values['receipt']=$data['state']==='READY'?array_intersect_key($data['receipt']??[],array_flip(['source_sha256','output_sha256','output_bytes','width','height'])):null;$t->update($values);return response()->json(['ok'=>true]);
  });
 }
 public function completion(Request $r,Transfer $transfer,FactoryDispatchService $dispatch){
  return DB::transaction(function()use($r,$transfer,$dispatch){
  $transfer=Transfer::lockForUpdate()->findOrFail($transfer->id);
  abort_unless($transfer->factory_agent_id===$dispatch->identity($r) && $transfer->kind==='UPLOAD',403);
  $refs=PrintBatch::where('status','!=','CANCELLED')->get()->filter(function($b)use($transfer){foreach($b->payload['orders']??[] as $o)foreach($o['artworks']??[] as $a)if(($a['custom_upload_id']??null)===$transfer->id)return true;return false;});
  $printed=$refs->isNotEmpty() && $refs->every(fn($b)=>$b->status==='PRINTED' && $b->completed_at && $b->confirmations()->where('action','PRINTED')->where('order_count',$b->order_count)->where('artwork_file_count',$b->artwork_file_count)->where('payload_sha256',hash('sha256',json_encode($b->payload)))->exists());
  $selected=ProductionItem::find($transfer->production_item_id);$used=in_array($transfer->id,array_column($selected?->matches??[],'upload_id'),true);
  $discard=$refs->isEmpty() && !$used && (in_array($transfer->state,['REPLACED','DISCARDING'],true) || (in_array($transfer->state,['READY','FAILED'],true) && $transfer->updated_at->lt(now()->subDay())));
  if($discard && $transfer->state!=='DISCARDING')$transfer->update(['state'=>'DISCARDING','offer'=>null,'answer'=>null]);
  return response()->json(['cleanup_authorized'=>$printed||$discard,'id'=>$transfer->id,'source_sha256'=>$transfer->metadata['sha256'],'reason'=>$printed?'PRINTED':($discard?'DISCARDED':null)])->header('Cache-Control','no-store');
  });
 }
}
