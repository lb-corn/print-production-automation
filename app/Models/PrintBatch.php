<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintBatch extends Model
{
    protected $fillable = [
        'batch_id',
        'type',
        'reprint_of_batch_id',
        'status',
        'order_count',
        'artwork_file_count',
        'total_yards',
        'payload',
        'created_by',
        'operator_name',
        'claimed_at',
        'handoff_requested_at',
        'sent_to_rip_at',
        'failed_at',
        'failure_reason',
        'completed_at',
        'print_confirmed_at',
        'print_confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'claimed_at' => 'datetime',
            'handoff_requested_at' => 'datetime',
            'sent_to_rip_at' => 'datetime',
            'failed_at' => 'datetime',
            'completed_at' => 'datetime',
            'print_confirmed_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(PrintBatchItem::class);
    }

    public function histories()
    {
        return $this->hasMany(PrintHistory::class);
    }
}
