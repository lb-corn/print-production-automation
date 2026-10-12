<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintHistory extends Model
{
    protected $fillable = [
        'print_batch_id',
        'print_batch_item_id',
        'production_item_id',
        'shopify_line_item_id',
        'result',
        'quantity',
        'batch_type',
        'reason',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'recorded_at' => 'datetime',
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
