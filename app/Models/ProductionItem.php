<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionItem extends Model
{
    protected $fillable = [
        'line_item_id',
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
    ];

    protected function casts(): array
    {
        return [
            'matches' => 'array',
            'is_custom' => 'boolean',
            'quantity' => 'integer',
            'last_synced_quantity' => 'integer',
            'quantity_delta' => 'integer',
            'order_date' => 'datetime',
            'last_synced_at' => 'datetime',
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
