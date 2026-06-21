<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasUuids;

    protected $fillable = [
        'vin',
        'license_plate',
        'oem',
        'model',
        'model_year',
        'engine_type',
        'ecu_firmware_version',
        'fleet_id',
        'tenant_id',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'model_year' => 'integer',
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function diagnosticSessions(): HasMany
    {
        return $this->hasMany(DiagnosticSession::class);
    }

    public function maintenanceAlerts(): HasMany
    {
        return $this->hasMany(PredictiveMaintenanceAlert::class);
    }

    /**
     * Scope to filter by tenant (OEM / dealer).
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
