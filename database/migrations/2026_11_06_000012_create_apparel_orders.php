<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jersey_orders', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_order_gid')->index();
            $table->string('shopify_order_number')->index();
            $table->string('shopify_line_item_gid')->unique();
            $table->string('shopify_product_gid')->nullable()->index();
            $table->string('shopify_variant_gid')->nullable()->index();
            $table->dateTime('ordered_at')->index();
            $table->string('product_title')->index();
            $table->string('product_handle')->nullable()->index();
            $table->string('variant_title')->nullable()->index();
            $table->string('style')->nullable()->index();
            $table->string('color')->nullable()->index();
            $table->string('size')->nullable()->index();
            $table->string('display_name')->nullable()->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('image_url')->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('selected_options')->nullable();
            $table->json('product_tags')->nullable();
            $table->string('shopify_fulfillment_status')->index();
            $table->string('shopify_financial_status')->nullable()->index();
            $table->dateTime('shopify_cancelled_at')->nullable();
            $table->dateTime('shopify_closed_at')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->dateTime('tracking_updated_at')->nullable();
            $table->foreignId('tracking_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remark')->nullable();
            $table->dateTime('remark_updated_at')->nullable();
            $table->foreignId('remark_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('remark_processed_at')->nullable();
            $table->foreignId('remark_processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['shopify_fulfillment_status', 'ordered_at'], 'jersey_orders_status_ordered_idx');
            $table->index(['product_title', 'shopify_fulfillment_status'], 'jersey_orders_product_status_idx');
            $table->index(['product_handle', 'shopify_fulfillment_status'], 'jersey_orders_handle_status_idx');
            $table->index(['color', 'size'], 'jersey_orders_color_size_idx');
            $table->index(['ordered_at', 'id'], 'jersey_orders_ordered_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jersey_orders');
    }
};
