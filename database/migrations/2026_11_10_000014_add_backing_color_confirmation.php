<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('production_items',fn(Blueprint $t)=>$t->json('backing_selection')->nullable());
        Schema::table('verification_decisions',fn(Blueprint $t)=>$t->string('backing_context_sha256',64)->nullable());
    }
    public function down(): void {
        Schema::table('verification_decisions',fn(Blueprint $t)=>$t->dropColumn('backing_context_sha256'));
        Schema::table('production_items',fn(Blueprint $t)=>$t->dropColumn('backing_selection'));
    }
};
