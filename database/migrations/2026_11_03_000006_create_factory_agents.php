<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('factory_agents',function(Blueprint $table){
            $table->id();$table->uuid('agent_id')->unique();$table->unsignedBigInteger('sampled_at_ms');
            $table->timestamp('last_seen_at')->index();$table->json('telemetry');$table->json('activity');$table->timestamps();
        });
    }
    public function down():void {Schema::dropIfExists('factory_agents');}
};
