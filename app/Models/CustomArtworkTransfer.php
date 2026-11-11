<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomArtworkTransfer extends Model {
 protected static function booted():void {
  static::addGlobalScope('partner',function($query){$ctx=\App\Services\PartnerContext::get();if($ctx->scoped){if($ctx->denied){$query->whereRaw('1=0');return;}$query->whereIn('production_item_id',\App\Models\ProductionItem::query()->select('id'));}});
 }

 public $incrementing=false;protected $keyType='string';protected $guarded=[];protected $hidden=['offer','answer'];
 protected function casts():array{return ['production_item_id'=>'integer','created_by'=>'integer','metadata'=>'array','receipt'=>'array','expires_at'=>'datetime'];}
}
