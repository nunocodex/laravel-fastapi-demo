<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'metadata',
        'status',
        'attempts',
        'max_attempts',
        'last_error',
        'dispatched_at',
        'locked_until',
    ];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'dispatched_at' => 'datetime',
        'locked_until' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['event_id'];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_DISPATCHED, self::STATUS_FAILED], true);
    }
}
