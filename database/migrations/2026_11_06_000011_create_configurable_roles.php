<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('roles',function(Blueprint $t){$t->id();$t->string('slug')->unique();$t->string('name');$t->json('permissions');$t->timestamps();});
        Schema::create('access_audits',function(Blueprint $t){$t->id();$t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();$t->string('action');$t->string('subject_type');$t->string('subject_id');$t->json('before')->nullable();$t->json('after')->nullable();$t->timestamp('created_at');});
        foreach(App\Services\AccessCatalog::defaults() as $slug=>[$name,$permissions]) DB::table('roles')->insert(['slug'=>$slug,'name'=>$name,'permissions'=>json_encode($permissions),'created_at'=>now(),'updated_at'=>now()]);
        Schema::table('users',fn(Blueprint $t)=>$t->string('role')->default('manager')->change());
        DB::table('users')->where('role','operator')->update(['role'=>'manager']);
    }
    public function down(): void {
        if(DB::table('users')->whereNotIn('role',['admin','manager','demo'])->exists()) throw new RuntimeException('Reassign custom and production roles before rolling back.');
        DB::table('users')->where('role','manager')->update(['role'=>'operator']);
        Schema::table('users',fn(Blueprint $t)=>$t->string('role')->default('operator')->change());
        Schema::dropIfExists('access_audits');
        Schema::dropIfExists('roles');
    }
};
