<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        foreach(DB::table('roles')->get() as $role) {
            $permissions=json_decode($role->permissions,true) ?? [];
            $expanded=[];
            foreach($permissions as $permission) {
                foreach(match($permission){'apparel.orders.edit'=>['apparel.tracking.edit','apparel.remarks.edit'],'apparel.orders.approve'=>['apparel.remarks.approve'],default=>[$permission]} as $value) $expanded[]=$value;
            }
            DB::table('roles')->where('id',$role->id)->update(['permissions'=>json_encode(array_values(array_unique($expanded)))]);
        }
    }
    public function down(): void {
        // Do not broaden independent permissions back into a combined edit grant.
    }
};
