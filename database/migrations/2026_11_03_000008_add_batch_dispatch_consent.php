<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {Schema::table('print_batches',fn(Blueprint $t)=>$t->boolean('operator_dispatch_enabled')->default(false));Schema::table('factory_agents',fn(Blueprint $t)=>$t->boolean('handoff_blocked')->default(false));}
    public function down():void {Schema::table('print_batches',fn(Blueprint $t)=>$t->dropColumn('operator_dispatch_enabled'));Schema::table('factory_agents',fn(Blueprint $t)=>$t->dropColumn('handoff_blocked'));}
};
