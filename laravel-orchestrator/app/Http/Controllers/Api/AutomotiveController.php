<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiagnosticSession;
use App\Models\PredictiveMaintenanceAlert;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutomotiveController extends Controller
{
    /**
     * Register a vehicle or update its last_seen timestamp.
     */
    public function registerVehicle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vin' => ['required', 'string', 'size:17'],
            'license_plate' => ['nullable', 'string', 'max:20'],
            'oem' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:64'],
            'model_year' => ['required', 'integer', 'min:2000', 'max:2035'],
            'engine_type' => ['nullable', 'string', 'max:64'],
            'ecu_firmware_version' => ['nullable', 'string', 'max:32'],
            'fleet_id' => ['nullable', 'string', 'max:64'],
            'tenant_id' => ['required', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        $vehicle = Vehicle::updateOrCreate(
            ['vin' => $validated['vin']],
            array_merge($validated, ['last_seen_at' => now()]),
        );

        return response()->json([
            'ok' => true,
            'vehicle_id' => $vehicle->id,
            'vin' => $vehicle->vin,
        ], 200);
    }

    /**
     * Start a diagnostic session for a vehicle.
     *
     * This is the primary automotive AI entry-point:
     * DTC codes + CAN snapshot → analyzed by the FastAPI RAG engine.
     */
    public function startDiagnostic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vin' => ['required', 'string', 'size:17'],
            'dtc_codes' => ['nullable', 'array'],
            'dtc_codes.*.code' => ['required', 'string', 'max:16'],
            'dtc_codes.*.description' => ['nullable', 'string', 'max:256'],
            'dtc_codes.*.severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'odometer_km' => ['nullable', 'string', 'max:16'],
            'freeze_frame_data' => ['nullable', 'array'],
            'can_bus_snapshot' => ['nullable', 'array'],
            'tenant_id' => ['required', 'string', 'max:64'],
        ]);

        $vehicle = Vehicle::where('vin', $validated['vin'])->first();
        if (! $vehicle) {
            return response()->json(['ok' => false, 'error' => 'vehicle_not_found'], 404);
        }

        // Build a human-readable diagnostic prompt for the RAG engine.
        $dtcSummary = collect($validated['dtc_codes'] ?? [])
            ->map(fn ($dtc) => "{$dtc['code']}: {$dtc['description']} (severity: {$dtc['severity']})")
            ->implode('; ');

        $prompt = "Diagnostica veicolo {$vehicle->oem} {$vehicle->model} ({$vehicle->model_year}). " .
                  "DTC: {$dtcSummary}. Chilometraggio: {$validated['odometer_km']} km.";

        $session = DiagnosticSession::create([
            'session_uuid' => (string) Str::uuid(),
            'vehicle_id' => $vehicle->id,
            'dtc_codes' => $validated['dtc_codes'] ?? [],
            'odometer_km' => $validated['odometer_km'],
            'freeze_frame_data' => $validated['freeze_frame_data'] ?? [],
            'can_bus_snapshot' => $validated['can_bus_snapshot'] ?? [],
            'status' => DiagnosticSession::STATUS_OPEN,
            'tenant_id' => $validated['tenant_id'],
            'occurred_at' => now(),
        ]);

        Log::info('automotive.diagnostic_started', [
            'session_uuid' => $session->session_uuid,
            'vin' => $vehicle->vin,
            'dtc_count' => count($validated['dtc_codes'] ?? []),
        ]);

        return response()->json([
            'ok' => true,
            'session_uuid' => $session->session_uuid,
            'status' => $session->status,
            'prompt' => $prompt,
        ], 201);
    }

    /**
     * List active maintenance alerts for a vehicle.
     */
    public function alertsForVehicle(Request $request, string $vin): JsonResponse
    {
        $vehicle = Vehicle::where('vin', $vin)->first();
        if (! $vehicle) {
            return response()->json(['ok' => false, 'error' => 'vehicle_not_found'], 404);
        }

        $alerts = $vehicle->maintenanceAlerts()
            ->where('status', PredictiveMaintenanceAlert::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->orderByDesc('confidence_score')
            ->get();

        return response()->json([
            'ok' => true,
            'vehicle' => $vehicle->only(['id', 'vin', 'oem', 'model', 'model_year']),
            'alerts' => $alerts,
        ]);
    }

    /**
     * Acknowledge or resolve a maintenance alert.
     */
    public function updateAlert(Request $request, string $alertUuid): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:acknowledged,resolved,dismissed'],
        ]);

        $alert = PredictiveMaintenanceAlert::where('alert_uuid', $alertUuid)->first();
        if (! $alert) {
            return response()->json(['ok' => false, 'error' => 'alert_not_found'], 404);
        }

        $alert->status = $validated['status'];
        $alert->save();

        Log::info('automotive.alert_updated', [
            'alert_uuid' => $alertUuid,
            'status' => $alert->status,
        ]);

        return response()->json(['ok' => true, 'alert_uuid' => $alertUuid, 'status' => $alert->status]);
    }
}
