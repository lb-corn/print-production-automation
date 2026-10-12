<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionItem extends Model
{
    protected $fillable = [
        'line_item_id',
        'shopify_shop',
        'shopify_order_id',
        'order_number',
        'order_date',
        'customer',
        'product',
        'product_type',
        'variant',
        'sku',
        'quantity',
        'last_synced_quantity',
        'quantity_delta',
        'last_synced_at',
        'shopify_image',
        'financial_status',
        'fulfillment_status',
        'series_code',
        'design_type',
        'design',
        'color',
        'is_custom',
        'match_status',
        'confidence',
        'matches',
        'is_active',
        'inactive_reason',
        'inactivated_at',
        'sync_conflict',
        'last_shopify_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'matches' => 'array',
            'is_custom' => 'boolean',
            'is_active' => 'boolean',
            'quantity' => 'integer',
            'last_synced_quantity' => 'integer',
            'quantity_delta' => 'integer',
            'order_date' => 'datetime',
            'last_synced_at' => 'datetime',
            'inactivated_at' => 'datetime',
            'last_shopify_seen_at' => 'datetime',
        ];
    }

    public function decision()
    {
        return $this->hasOne(VerificationDecision::class, 'line_item_id', 'line_item_id');
    }

    public function batchItems()
    {
        return $this->hasMany(PrintBatchItem::class);
    }

    public function printHistories()
    {
        return $this->hasMany(PrintHistory::class);
    }
}
