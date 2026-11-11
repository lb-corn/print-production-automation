<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
    public function up():void {
        foreach(['production_items','jersey_orders','print_batches'] as $name)Schema::table($name,fn(Blueprint $t)=>$t->string('partner',24)->default('lucky')->index());
        Schema::table('jersey_orders',function(Blueprint $t){$t->dropUnique(['shopify_line_item_gid']);$t->unique(['partner','shopify_line_item_gid']);});
        foreach(DB::table('roles')->get() as $role){$permissions=json_decode($role->permissions,true)??[];foreach(['partners.lucky.view','partners.vibe.view'] as $permission)if(!in_array($permission,$permissions,true))$permissions[]=$permission;DB::table('roles')->where('id',$role->id)->update(['permissions'=>json_encode($permissions)]);}
    }
    public function down():void {
        if(DB::table('jersey_orders')->where('partner','!=','lucky')->exists() || DB::table('production_items')->where('partner','!=','lucky')->exists() || DB::table('print_batches')->where('partner','!=','lucky')->exists())throw new RuntimeException('Partner data exists; do not remove its ownership fields.');
        Schema::table('jersey_orders',function(Blueprint $t){$t->dropUnique(['partner','shopify_line_item_gid']);$t->unique('shopify_line_item_gid');});
        foreach(['production_items','jersey_orders','print_batches'] as $name)Schema::table($name,fn(Blueprint $t)=>$t->dropColumn('partner'));
    }
};
