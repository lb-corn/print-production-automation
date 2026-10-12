<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionSync extends Model
{
    protected $fillable = [
        'mode',
        'orders_examined',
        'shopify_items',
        'added',
        'updated',
        'unchanged',
        'matched',
        'missing',
        'quantity_deltas',
        'inactive',
        'conflicts',
        'drive_files',
        'error',
        'created_by',
    ];
}
