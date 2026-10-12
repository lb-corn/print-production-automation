<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->string('shopify_shop')->nullable()->after('line_item_id');
            $table->boolean('is_active')->default(true)->after('last_synced_at');
            $table->string('inactive_reason')->nullable()->after('is_active');
            $table->timestamp('inactivated_at')->nullable()->after('inactive_reason');
            $table->text('sync_conflict')->nullable()->after('inactivated_at');
            $table->timestamp('last_shopify_seen_at')->nullable()->after('sync_conflict');

            $table->unique(
                ['shopify_shop', 'shopify_order_id', 'line_item_id'],
                'production_items_shop_order_line_unique'
            );
            $table->index(['is_active', 'match_status']);
        });

        Schema::table('production_syncs', function (Blueprint $table) {
            $table->integer('inactive')->default(0)->after('quantity_deltas');
            $table->integer('conflicts')->default(0)->after('inactive');
        });
    }

    public function down(): void
    {
        Schema::table('production_syncs', function (Blueprint $table) {
            $table->dropColumn(['inactive', 'conflicts']);
        });

        Schema::table('production_items', function (Blueprint $table) {
            $table->dropUnique('production_items_shop_order_line_unique');
            $table->dropIndex(['is_active', 'match_status']);
            $table->dropColumn([
                'shopify_shop',
                'is_active',
                'inactive_reason',
                'inactivated_at',
                'sync_conflict',
                'last_shopify_seen_at',
            ]);
        });
    }
};
