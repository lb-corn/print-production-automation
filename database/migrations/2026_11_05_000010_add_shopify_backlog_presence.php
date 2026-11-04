<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void {Schema::table('production_items',function(Blueprint $t){$t->boolean('shopify_active')->default(true)->index();$t->timestamp('shopify_seen_at')->nullable();});}public function down():void {Schema::table('production_items',fn(Blueprint $t)=>$t->dropColumn(['shopify_active','shopify_seen_at']));}};
