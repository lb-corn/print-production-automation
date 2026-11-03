<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('factory_agents',function(Blueprint $t){$t->boolean('processing_enabled')->default(false);$t->json('control_audit')->nullable();});
        Schema::table('print_batches',function(Blueprint $t){
            $t->timestamp('automation_requested_at')->nullable();$t->uuid('factory_agent_id')->nullable()->index();
            $t->uuid('agent_operation_token')->nullable();$t->string('agent_operation_phase',16)->nullable();
            $t->timestamp('agent_operation_started_at')->nullable();$t->string('agent_error_code',40)->nullable();
        });
    }
    public function down():void {
        Schema::table('print_batches',fn(Blueprint $t)=>$t->dropColumn(['automation_requested_at','factory_agent_id','agent_operation_token','agent_operation_phase','agent_operation_started_at','agent_error_code']));
        Schema::table('factory_agents',fn(Blueprint $t)=>$t->dropColumn(['processing_enabled','control_audit']));
    }
};
