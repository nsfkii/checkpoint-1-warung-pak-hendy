<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'unit',
        'selling_price',
        'stock_quantity',
        'minimum_stock',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'integer',
            'stock_quantity' => 'integer',
            'minimum_stock' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }
}
