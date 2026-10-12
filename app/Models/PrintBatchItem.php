<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintBatchItem extends Model
{
    protected $fillable = [
        'print_batch_id',
        'production_item_id',
        'shopify_line_item_id',
        'quantity',
        'completed_quantity',
        'failed_quantity',
        'status',
        'artworks',
        'materials',
        'operator_name',
        'created_by',
        'confirmed_at',
        'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'artworks' => 'array',
            'materials' => 'array',
            'quantity' => 'integer',
            'completed_quantity' => 'integer',
            'failed_quantity' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(PrintBatch::class, 'print_batch_id');
    }

    public function productionItem()
    {
        return $this->belongsTo(ProductionItem::class);
    }
}
