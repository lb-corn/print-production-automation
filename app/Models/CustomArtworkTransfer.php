<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomArtworkTransfer extends Model {
 public $incrementing=false;protected $keyType='string';protected $guarded=[];protected $hidden=['offer','answer'];
 protected function casts():array{return ['production_item_id'=>'integer','created_by'=>'integer','metadata'=>'array','receipt'=>'array','expires_at'=>'datetime'];}
}
