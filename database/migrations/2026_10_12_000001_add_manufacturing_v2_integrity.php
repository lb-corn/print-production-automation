<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->string('shopify_order_id')->nullable()->after('line_item_id');
            $table->timestamp('order_date')->nullable()->after('order_number');
            $table->string('customer')->nullable()->after('order_date');
            $table->string('product_type')->nullable()->after('product');
            $table->string('financial_status')->nullable()->after('shopify_image');
            $table->string('fulfillment_status')->nullable()->after('financial_status');
            $table->integer('last_synced_quantity')->nullable()->after('quantity');
            $table->integer('quantity_delta')->default(0)->after('last_synced_quantity');
            $table->timestamp('last_synced_at')->nullable()->after('quantity_delta');
        });

        Schema::table('print_batches', function (Blueprint $table) {
            $table->string('type')->default('normal')->after('batch_id');
            $table->unsignedBigInteger('reprint_of_batch_id')->nullable()->after('type');
            $table->string('operator_name')->nullable()->after('created_by');
            $table->timestamp('handoff_requested_at')->nullable()->after('claimed_at');
            $table->timestamp('sent_to_rip_at')->nullable()->after('handoff_requested_at');
            $table->timestamp('failed_at')->nullable()->after('sent_to_rip_at');
            $table->text('failure_reason')->nullable()->after('failed_at');
            $table->timestamp('print_confirmed_at')->nullable()->after('completed_at');
            $table->unsignedBigInteger('print_confirmed_by')->nullable()->after('print_confirmed_at');
        });

        Schema::create('production_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->default('unfulfilled');
            $table->integer('orders_examined')->default(0);
            $table->integer('shopify_items')->default(0);
            $table->integer('added')->default(0);
            $table->integer('updated')->default(0);
            $table->integer('unchanged')->default(0);
            $table->integer('matched')->default(0);
            $table->integer('missing')->default(0);
            $table->integer('quantity_deltas')->default(0);
            $table->integer('drive_files')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('print_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_batch_id')->constrained('print_batches')->cascadeOnDelete();
            $table->foreignId('production_item_id')->constrained('production_items')->cascadeOnDelete();
            $table->string('shopify_line_item_id');
            $table->integer('quantity');
            $table->integer('completed_quantity')->default(0);
            $table->integer('failed_quantity')->default(0);
            $table->string('status')->default('ALLOCATED');
            $table->json('artworks');
            $table->json('materials')->nullable();
            $table->string('operator_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['print_batch_id', 'production_item_id']);
            $table->index(['shopify_line_item_id', 'status']);
        });

        Schema::create('print_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_batch_id')->constrained('print_batches')->cascadeOnDelete();
            $table->foreignId('print_batch_item_id')->constrained('print_batch_items')->cascadeOnDelete();
            $table->foreignId('production_item_id')->constrained('production_items')->cascadeOnDelete();
            $table->string('shopify_line_item_id');
            $table->string('result');
            $table->integer('quantity');
            $table->string('batch_type')->default('normal');
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['shopify_line_item_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_histories');
        Schema::dropIfExists('print_batch_items');
        Schema::dropIfExists('production_syncs');

        Schema::table('print_batches', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'reprint_of_batch_id',
                'operator_name',
                'handoff_requested_at',
                'sent_to_rip_at',
                'failed_at',
                'failure_reason',
                'print_confirmed_at',
                'print_confirmed_by',
            ]);
        });

        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn([
                'shopify_order_id',
                'order_date',
                'customer',
                'product_type',
                'financial_status',
                'fulfillment_status',
                'last_synced_quantity',
                'quantity_delta',
                'last_synced_at',
            ]);
        });
    }
};
