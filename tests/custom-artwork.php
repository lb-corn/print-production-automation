<?php
require __DIR__.'/factory-dispatch.php';
use App\Services\{CustomArtworkService as Custom,MaterialCatalog};
use App\Models\{ProductionItem,CustomArtworkTransfer as Transfer};
$custom=new App\Http\Controllers\CustomArtworkController();$agent->update(['last_seen_at'=>now(),'telemetry'=>['agent_version'=>'1.6.2','reporter_mode'=>'listener','process_status'=>'running','stage'=>'idle']]);
$hash=str_repeat('a',128).'-1793651097';$url=Custom::thumbnail([['key'=>'_fpd-hash','value'=>$hash]]);
$check($url==='https://fpd-api.ganymede-apps.net/api/fe/line_item_cache/thumbnail_image?data_hash='.$hash,'Fancy Product hash becomes a direct external order preview, never hosted image bytes');
$check(Custom::thumbnail([['key'=>'_fpd-hash','value'=>'https://attacker.invalid/image']])===null && Custom::series('PSN-CSTM')==='PSN','Custom bag code comes from the material sheet and untrusted preview URLs are not accepted');
$row=['line_item_id'=>'custom-line-4269','order_number'=>'#4269','product'=>'Custom Pro Snipers','variant'=>'Default Title','sku'=>'PSN-CSTM','quantity'=>1,'shopify_image'=>$url];
$item=ProductionItem::create([...$row,...Custom::match($row)]);$check($item->is_custom && $item->series_code==='PSN' && $item->design_type==='double','Custom double-sided orders use the same dynamic bag type and material settings');
$offer="v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n";
$request=function($path,$data)use($user){$r=Illuminate\Http\Request::create($path,'POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($data));$r->setUserResolver(fn()=>$user);return $r;};
$agentRequest=function($data=[])use($id){return Illuminate\Http\Request::create('/factory','POST',[],[],[],['HTTP_X_FACTORY_AGENT_ID'=>$id,'CONTENT_TYPE'=>'application/json'],json_encode($data));};
$ids=[];
foreach(['front','back'] as $side){
 $uuid=(string)Illuminate\Support\Str::uuid();$data=['id'=>$uuid,'kind'=>'UPLOAD','side'=>$side,'filename'=>$side.'.png','bytes'=>1234,'sha256'=>str_repeat($side==='front'?'b':'c',64),'offer'=>$offer];
 $custom->create($request('/custom',$data),$item);$transfer=Transfer::findOrFail($uuid);$check($transfer->state==='WAITING' && $transfer->metadata['material']===($side==='front'?'Rave9':'Turbo6'),'Custom side is assigned to its own material before direct transfer: '.$side);
 $check($custom->pending($agentRequest(),$dispatch)->getData(true)['transfer']['id']===$uuid,'Only the bound PC receives the pending connection offer');
 $custom->begin($agentRequest(),$transfer,$dispatch);$reject(fn()=>$custom->begin($agentRequest(),$transfer->fresh(),$dispatch),409);
 $custom->report($agentRequest(['state'=>'CONNECTED','answer'=>$offer]),$transfer->fresh(),$dispatch);
 try{$custom->report($agentRequest(['state'=>'READY','receipt'=>['source_sha256'=>str_repeat('f',64)]]),$transfer->fresh(),$dispatch);throw new LogicException('Bad receipt accepted');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$check($e->getStatusCode()===422,'A PC receipt cannot substitute another original');}
 $receipt=['source_sha256'=>$data['sha256'],'output_sha256'=>str_repeat('d',64),'output_bytes'=>5678,'width'=>1000,'height'=>1000];
 $custom->report($agentRequest(['state'=>'READY','receipt'=>$receipt]),$transfer->fresh(),$dispatch);$ids[$side]=$uuid;
 $check($transfer->fresh()->state==='READY' && !$transfer->fresh()->offer && !$transfer->fresh()->answer,'Only scanned/hash-validated completion releases the slot and clears signaling details');
}
$reject(fn()=>$custom->confirm($request('/confirm',['confirm_custom'=>1,'uploads'=>['front'=>$ids['front']]]),$item),422);
$check(!$svc->ready($item->fresh()),'Incomplete custom sides cannot enter Ready for Print');
$custom->confirm($request('/confirm',['confirm_custom'=>1,'uploads'=>$ids]),$item);$item->refresh()->load('decision');
$check($svc->ready($item) && $item->decision->decision==='CORRECT','Both validated custom sides require explicit user confirmation before queueing');
$jobs=$svc->batchByMaterial($user->id,[$item->id]);foreach($jobs as $job)$dispatch->request($job);
$check(count($jobs)===2 && collect($jobs)->every(fn($j)=>$j->payload['contains_custom_artwork'] && count($j->payload['orders'][0]['artworks'])===1 && $j->payload['orders'][0]['artworks'][0]['source_kind']==='pc_custom'),'Custom originals follow separate material batches without a Drive source');
$check(!$custom->completion($agentRequest(),Transfer::find($ids['front']),$dispatch)->getData(true)['cleanup_authorized'],'Uploading, approving and queueing custom files never authorizes deletion');
$front=collect($jobs)->first(fn($j)=>$j->payload['material_group']==='Rave9');$front->update(['status'=>'PRINTED','completed_at'=>now()]);
$check(!$custom->completion($agentRequest(),Transfer::find($ids['front']),$dispatch)->getData(true)['cleanup_authorized'],'PRINTED state alone cannot delete a custom original without signed completion');
$front->update(['status'=>'SENT_TO_RIP']);$printRequest=$request('/printed',['signer_name'=>'Test Operator','confirmation_phrase'=>'PRINTED','review_confirmed'=>1,'confirmed_order_count'=>$front->order_count,'confirmed_artwork_file_count'=>$front->artwork_file_count,'payload_sha256'=>hash('sha256',json_encode($front->payload))]);
(new App\Http\Controllers\ProductionController($svc))->markPrinted($printRequest,$front->fresh());
$check($custom->completion($agentRequest(),Transfer::find($ids['front']),$dispatch)->getData(true)['cleanup_authorized'] && !$custom->completion($agentRequest(),Transfer::find($ids['back']),$dispatch)->getData(true)['cleanup_authorized'],'Signed material completion deletes only that custom side; the other side remains retained');
$agent->update(['telemetry'=>['agent_version'=>'1.5.1','reporter_mode'=>'listener','process_status'=>'running']]);$back=collect($jobs)->first(fn($j)=>$j->payload['material_group']==='Turbo6');
$check($dispatch->readiness($back->fresh())['state']==='agent_update_required','Older PC agents cannot process custom-source batches');
$reject(fn()=>$dispatch->begin($agentRequest(),$back->fresh(),'prepare'),409);
$wrong=Illuminate\Http\Request::create('/status');$other=App\Models\User::create(['name'=>'Other operator','email'=>'other-custom@example.test','password'=>'unused','role'=>'manager']);$wrong->setUserResolver(fn()=>$other);
$reject(fn()=>$custom->status($wrong,Transfer::find($ids['front'])),403);
$badPc=Illuminate\Http\Request::create('/report','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_FACTORY_AGENT_ID'=>'a448d851-4aba-42a0-9ba9-f3916c803975'],json_encode(['state'=>'READY']));$reject(fn()=>$custom->report($badPc,Transfer::find($ids['back']),$dispatch),403);
$shop=new class extends App\Services\ShopifyService {public array $line;public function __construct(){}public function graphql(string $query,array $variables=[]):array {return ['orders'=>['nodes'=>[['id'=>'order-custom-sync','name'=>'#4269','createdAt'=>'2026-11-02','cancelledAt'=>null,'displayFinancialStatus'=>'PAID','lineItems'=>['nodes'=>[$this->line]]]],'pageInfo'=>['hasNextPage'=>false]]];}};
$shop->line=['id'=>'custom-sync-source','sku'=>'PSN-CSTM','variantTitle'=>'Default Title','quantity'=>1,'unfulfilledQuantity'=>1,'customAttributes'=>[['key'=>'_fpd-hash','value'=>$hash]],'product'=>['id'=>'custom-product','title'=>'Custom Pro Snipers','productType'=>'Custom Bags']];
$backlog=$shop->getProductionBacklog();$check(count($backlog['items'])===1 && $backlog['items'][0]['is_custom'] && $backlog['items'][0]['shopify_image']===$url,'Real Shopify backlog parsing includes Fancy Product custom bags and their per-order preview');
$noDrive=new class extends App\Services\GoogleDriveService {public function __construct(){}public function artworkIndex():array{throw new LogicException('Custom sync requested Drive artwork');}};
$sync=new App\Services\BacklogSyncService($shop,$noDrive,new App\Services\ArtworkMatcherService($noDrive));$sync->sync();$synced=ProductionItem::where('line_item_id','custom-sync-source')->firstOrFail();
$check($synced->is_custom && $synced->matches===[] && $synced->design_type==='double','Custom-only sync imports orders without looking for their files in Google Drive');
auth()->setUser($user);view()->share('errors',new Illuminate\Support\ViewErrorBag());
$agent->update(['last_seen_at'=>now(),'telemetry'=>['agent_version'=>'1.6.2','reporter_mode'=>'listener','process_status'=>'running','stage'=>'idle']]);
$singleRow=[...$row,'line_item_id'=>'custom-single','product'=>'Custom Pro Sniper Elite','sku'=>'PSE-CSTM'];$single=ProductionItem::create([...$singleRow,...Custom::match($singleRow)]);
$single->production_batch=null;$singleHtml=view('partials.custom-artwork-upload',['item'=>$single])->render();
$item->production_batch=null;$doubleHtml=view('partials.custom-artwork-upload',['item'=>$item])->render();
$check(substr_count($singleHtml,'data-custom-slot')===1 && substr_count($doubleHtml,'data-custom-slot')===2,'Custom review preserves the existing tile and provides exactly one or two upload slots');
$controller=new App\Http\Controllers\ProductionController($svc);$reviewHtml=$controller->verification()->render();
$check(str_contains($reviewHtml,'CUSTOM ORDER PREVIEW') && str_contains($reviewHtml,'data-custom-review'),'Custom orders render the external website preview beside the upload controls');
config(['services.backing_colors.enabled'=>true]);
$colorHtml=$controller->verification()->render();
$check(str_contains($colorHtml,'data-backing-summary') && str_contains($colorHtml,'Choose color</b>') && str_contains($colorHtml,'Custom design</b>'),'Custom metadata shows backing selection guidance for single sides and custom design for double sides');
config(['services.backing_colors.enabled'=>false]);
if(in_array('--preview',$argv,true)){
 config(['services.backing_colors.enabled'=>true]);
 // Exercise each actual product renderer together, including single-side backing controls.
 foreach(['single','double'] as $type){
  $normal=ProductionItem::findOrFail(($type==='single'?$single:$item)->id)->replicate();$normal->line_item_id='layout-normal-'.$type;
  $normal->product='Normal '.$type.' layout fixture';$normal->is_custom=false;$normal->shopify_active=true;$normal->matches=[];$normal->artwork_review=[];$normal->save();
 }
 file_put_contents(__DIR__.'/../tmp/custom-review.html',$controller->verification()->render());
 file_put_contents(__DIR__.'/../tmp/custom-dashboard.html',$controller->dashboard()->render());
}
echo "$checks combined custom-artwork checks passed.\n";
