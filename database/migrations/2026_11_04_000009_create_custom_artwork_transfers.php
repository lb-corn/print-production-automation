<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('custom_artwork_transfers',function(Blueprint $t){
  $t->uuid('id')->primary();$t->foreignId('production_item_id')->constrained()->cascadeOnDelete();$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
  $t->uuid('factory_agent_id');$t->string('kind',12);$t->uuid('parent_id')->nullable();$t->string('side',8);$t->string('state',16)->default('WAITING');
  $t->json('metadata');$t->json('receipt')->nullable();$t->text('offer')->nullable();$t->text('answer')->nullable();$t->string('error_code',40)->nullable();
  $t->timestamp('expires_at');$t->timestamps();$t->index(['factory_agent_id','state','expires_at'],'custom_transfer_pending');$t->index(['production_item_id','kind']);
 });}
 public function down():void {Schema::dropIfExists('custom_artwork_transfers');}
};
