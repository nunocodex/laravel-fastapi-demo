<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictiveMaintenanceAlert extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'alert_uuid',
        'vehicle_id',
        'component',
        'confidence_score',
        'estimated_km_remaining',
        'ttl_hours',
        'suggested_action',
        'evidence',
        'status',
        'tenant_id',
        'expires_at',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'estimated_km_remaining' => 'integer',
        'ttl_hours' => 'integer',
        'evidence' => 'array',
        'expires_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['alert_uuid'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
