<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('vin', 17)->unique();           // ISO 3779 VIN
            $table->string('license_plate', 20)->nullable();
            $table->string('oem', 64);                     // Manufacturer: BMW, Stellantis, etc.
            $table->string('model', 64);
            $table->unsignedSmallInteger('model_year');
            $table->string('engine_type', 64)->nullable(); // ICE, BEV, PHEV, HEV
            $table->string('ecu_firmware_version', 32)->nullable();
            $table->string('fleet_id', 64)->nullable()->index();
            $table->string('tenant_id', 64)->index();      // Multi-tenant: OEM/dealer ID
            $table->jsonb('metadata')->nullable();          // Extensible attributes
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('oem');
        });

        Schema::create('diagnostic_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->jsonb('dtc_codes')->nullable();          // [{code: "P0301", description: "...", severity: "high"}]
            $table->string('odometer_km', 16)->nullable();
            $table->jsonb('freeze_frame_data')->nullable();  // PID snapshots at fault time
            $table->jsonb('can_bus_snapshot')->nullable();   // Raw CAN frame capture
            $table->string('status', 32)->default('open');   // open, analyzing, resolved, closed
            $table->string('resolution', 512)->nullable();
            $table->foreignId('ai_task_id')->nullable()->constrained('ai_tasks');
            $table->string('tenant_id', 64)->index();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        Schema::create('predictive_maintenance_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('alert_uuid')->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->string('component', 128);               // brake_pads, battery, oil_filter, etc.
            $table->float('confidence_score');               // 0.0 - 1.0
            $table->unsignedInteger('estimated_km_remaining')->nullable();
            $table->unsignedInteger('ttl_hours')->default(168);  // Alert validity window
            $table->string('suggested_action', 512)->nullable();
            $table->jsonb('evidence')->nullable();            // Supporting data (sensor trends, DTC history)
            $table->string('status', 32)->default('active');  // active, acknowledged, resolved, dismissed
            $table->string('tenant_id', 64)->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictive_maintenance_alerts');
        Schema::dropIfExists('diagnostic_sessions');
        Schema::dropIfExists('vehicles');
    }
};
