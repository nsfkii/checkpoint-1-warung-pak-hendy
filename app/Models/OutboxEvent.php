<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_type',
        'exchange_name',
        'routing_key',
        'aggregate_id',
        'payload',
        'occurred_at',
        'published_at',
        'dead_lettered_at',
        'attempt_count',
        'next_attempt_at',
        'last_error',
        'status',
        'lease_token',
        'lease_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'aggregate_id');
    }
}
