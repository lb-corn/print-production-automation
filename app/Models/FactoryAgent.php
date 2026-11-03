<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FactoryAgent extends Model {
    protected $guarded=[];
    protected function casts():array{return ['telemetry'=>'array','activity'=>'array','last_seen_at'=>'immutable_datetime','sampled_at_ms'=>'integer','handoff_blocked'=>'boolean','processing_enabled'=>'boolean','control_audit'=>'array'];}
}
