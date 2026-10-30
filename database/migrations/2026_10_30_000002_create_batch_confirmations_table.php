<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('batch_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_batch_id')->constrained('print_batches')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_name');
            $table->string('signer_name', 100);
            $table->string('action', 30);
            $table->unsignedInteger('order_count');
            $table->unsignedInteger('total_sets');
            $table->unsignedInteger('artwork_file_count');
            $table->string('payload_sha256', 64);
            $table->timestamp('confirmed_at');
            $table->unique(['print_batch_id','action']);
        });
    }
    public function down(): void { Schema::dropIfExists('batch_confirmations'); }
};
