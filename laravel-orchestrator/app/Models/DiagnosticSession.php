<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosticSession extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'open';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'session_uuid',
        'vehicle_id',
        'dtc_codes',
        'odometer_km',
        'freeze_frame_data',
        'can_bus_snapshot',
        'status',
        'resolution',
        'ai_task_id',
        'tenant_id',
        'occurred_at',
    ];

    protected $casts = [
        'dtc_codes' => 'array',
        'freeze_frame_data' => 'array',
        'can_bus_snapshot' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['session_uuid'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function aiTask(): BelongsTo
    {
        return $this->belongsTo(AiTask::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
