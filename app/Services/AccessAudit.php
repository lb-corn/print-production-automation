<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class AccessAudit {
    public static function record(int $actor,string $action,string $type,string $id,?array $before,?array $after): void {
        DB::table('access_audits')->insert(['actor_id'=>$actor,'action'=>$action,'subject_type'=>$type,'subject_id'=>$id,'before'=>$before===null?null:json_encode($before),'after'=>$after===null?null:json_encode($after),'created_at'=>now()]);
    }
}
