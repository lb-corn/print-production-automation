<?php
namespace App\Services;
use App\Models\{CustomArtworkTransfer,ProductionItem,VerificationDecision};
use Illuminate\Support\Facades\Schema;
class CustomArtworkService {
 public const POLICY='custom-pc-v1';
 public static function ready():bool {return Schema::hasTable('custom_artwork_transfers');}
 public static function series(?string $sku,?string $product=null):?string {
  $sku=strtoupper(trim((string)$sku));$rows=app(MaterialCatalog::class)->rows();
  foreach(array_keys($rows) as $code)if(in_array($sku,[$code.'-CSTM',$code.'-CUSTOM'],true))return $code;
  if(preg_match('/^Custom\s+(.+)$/i',trim((string)$product),$m))foreach($rows as $code=>$row)if(strcasecmp(trim($m[1]),$row['name'])===0)return $code;
  return null;
 }
 public static function thumbnail(array $attributes):?string {
  foreach($attributes as $attribute){$key=strtolower((string)($attribute['key']??''));$value=trim((string)($attribute['value']??''));
   if(in_array($key,['_fpd-hash','fpd-hash','_fpd_data_hash','_fpd-data-hash'],true) && preg_match('/^[a-f0-9]{32,256}(?:-[0-9]{1,16})?$/i',$value))return 'https://fpd-api.ganymede-apps.net/api/fe/line_item_cache/thumbnail_image?data_hash='.rawurlencode($value);
  }return null;
 }
 public static function context($item):string {
  $value=is_array($item)?$item:(method_exists($item,'toArray')?$item->toArray():get_object_vars($item));$context=array_map(fn($k)=>$value[$k]??null,['line_item_id','product','variant','sku','quantity','series_code','design_type','color','shopify_image']);if(!empty($value['backing_selection']))$context[]=$value['backing_selection'];return hash('sha256',json_encode($context));
 }
 public static function match(array $row,?ProductionItem $previous=null):array {
  $code=self::series($row['sku']??null,$row['product']??null);$definition=app(MaterialCatalog::class)->row($code);$type=$definition['type']??null;
  $result=['series_code'=>$code,'design_type'=>$type,'color'=>null,'is_custom'=>true,'match_status'=>'CUSTOM','confidence'=>null,'matches'=>[]];
  $context=self::context([...$row,...$result,'backing_selection'=>$previous?->backing_selection]);
  if($previous && ($previous->artwork_review['context_sha256']??null)===$context)$result['matches']=$previous->matches??[];
  $result['artwork_review']=['policy'=>self::POLICY,'context_sha256'=>$context,'issues'=>$code?[]:['Custom bag SKU does not identify a bag code in the Lucky Bags material sheet.']];return $result;
 }
 public static function validSelection($item):bool {
  if(!self::ready() || ($item->artwork_review['policy']??null)!==self::POLICY || !$item->is_custom || !in_array($item->design_type,['single','double'],true))return false;
  if(($item->artwork_review['context_sha256']??null)!==self::context($item))return false;
  $matches=$item->matches??[];$sides=array_column($matches,'side');sort($sides);if($sides!==($item->design_type==='double'?['back','front']:['single']))return false;
  $agents=[];foreach($matches as $match){
   if(($match['source']??null)!=='pc_custom' || empty($match['upload_id']))return false;
   $asset=CustomArtworkTransfer::find($match['upload_id']);
   if(!$asset || $asset->kind!=='UPLOAD' || !in_array($asset->state,['READY','APPROVED'],true) || $asset->production_item_id!==$item->id || $asset->side!==$match['side'] || ($asset->metadata['context_sha256']??null)!==($item->artwork_review['context_sha256']??null) || ($asset->metadata['sha256']??null)!==($match['sha256']??null))return false;
   $agents[]=$asset->factory_agent_id;
  }return count(array_unique($agents))===1;
 }
 public static function confirm(ProductionItem $item,array $ids,int $actor):void {
  abort_unless(self::ready(),422,'The server needs the custom-artwork database update.');
  app(MaterialCatalog::class)->rows(true);
  $expected=$item->design_type==='double'?['front','back']:['single'];$sides=array_keys($ids);sort($sides);$sorted=$expected;sort($sorted);
  abort_unless($sides===$sorted && count(array_unique($ids))===count($expected),422,'Upload and validate every required print side first.');
  abort_unless(app(ProductionService::class)->materialCompatible($item),422,'The bag type changed in the sheet. Sync and review again.');
  abort_if(app(ProductionService::class)->processed($item),409,'This order is already queued.');
  $matches=[];foreach($ids as $side=>$id){$asset=CustomArtworkTransfer::lockForUpdate()->findOrFail($id);
   abort_unless($asset->production_item_id===$item->id && $asset->side===$side && $asset->kind==='UPLOAD' && in_array($asset->state,['READY','APPROVED'],true) && ($asset->metadata['context_sha256']??null)===self::context($item),422,'An uploaded side is unavailable or belongs to an older order revision. Upload it again.');
   $matches[]=['source'=>'pc_custom','id'=>'custom-'.$asset->id,'upload_id'=>$asset->id,'side'=>$side,'name'=>$asset->metadata['filename'],'sha256'=>$asset->metadata['sha256'],'bytes'=>$asset->metadata['bytes'],'factory_agent_id'=>$asset->factory_agent_id];
  }
  abort_unless(count(array_unique(array_column($matches,'factory_agent_id')))===1,422,'All sides must be stored on the same Print PC.');
  $old=array_column($item->matches??[],'upload_id');foreach(array_diff($old,array_values($ids)) as $id)CustomArtworkTransfer::whereKey($id)->where('state','APPROVED')->update(['state'=>'REPLACED']);
  foreach($ids as $id)CustomArtworkTransfer::whereKey($id)->update(['state'=>'APPROVED','offer'=>null,'answer'=>null]);
  $item->update(['matches'=>$matches,'artwork_review'=>['policy'=>self::POLICY,'context_sha256'=>self::context($item),'confirmed_by'=>$actor,'confirmed_at'=>now()->toIso8601String()]]);
  app(ProductionService::class)->verify($item->fresh(),'CORRECT',$actor);
 }
}
