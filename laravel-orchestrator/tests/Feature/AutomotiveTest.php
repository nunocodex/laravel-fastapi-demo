<?php

use App\Models\DiagnosticSession;
use App\Models\PredictiveMaintenanceAlert;
use App\Models\Vehicle;
use function Pest\Laravel\postJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

beforeEach(function () {
    Vehicle::query()->delete();
    DiagnosticSession::query()->delete();
    PredictiveMaintenanceAlert::query()->delete();
});

test('POST /api/vehicles registers a new vehicle', function () {
    $response = postJson('/api/vehicles', [
        'vin' => 'WBA3A5C5XDF123456',
        'license_plate' => 'AB-123-CD',
        'oem' => 'BMW',
        'model' => '320d',
        'model_year' => 2024,
        'engine_type' => 'ICE',
        'tenant_id' => 'oem-bmw-de',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['ok', 'vehicle_id', 'vin'])
        ->assertJson(['ok' => true, 'vin' => 'WBA3A5C5XDF123456']);

    expect(Vehicle::where('vin', 'WBA3A5C5XDF123456')->exists())->toBeTrue();
});

test('POST /api/vehicles rejects invalid VIN length', function () {
    $response = postJson('/api/vehicles', [
        'vin' => 'SHORT',
        'oem' => 'BMW',
        'model' => '320d',
        'model_year' => 2024,
        'tenant_id' => 'oem-bmw-de',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['vin']);
});

test('POST /api/diagnostics starts a diagnostic session', function () {
    Vehicle::create([
        'vin' => 'WBA3A5C5XDF123456',
        'oem' => 'BMW',
        'model' => '320d',
        'model_year' => 2024,
        'tenant_id' => 'oem-bmw-de',
    ]);

    $response = postJson('/api/diagnostics', [
        'vin' => 'WBA3A5C5XDF123456',
        'dtc_codes' => [
            ['code' => 'P0301', 'description' => 'Cylinder 1 Misfire', 'severity' => 'high'],
            ['code' => 'P0171', 'description' => 'System Too Lean', 'severity' => 'medium'],
        ],
        'odometer_km' => '45000',
        'tenant_id' => 'oem-bmw-de',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['ok', 'session_uuid', 'status', 'prompt'])
        ->assertJson(['ok' => true, 'status' => 'open']);

    $sessionUuid = $response->json('session_uuid');
    $session = DiagnosticSession::where('session_uuid', $sessionUuid)->first();

    expect($session)->not->toBeNull()
        ->and($session->dtc_codes)->toHaveCount(2)
        ->and($session->dtc_codes[0]['code'])->toBe('P0301')
        ->and($session->dtc_codes[0]['severity'])->toBe('high');
});

test('POST /api/diagnostics returns 404 for unknown vehicle', function () {
    $response = postJson('/api/diagnostics', [
        'vin' => '00000000000000000',
        'dtc_codes' => [],
        'tenant_id' => 'test',
    ]);

    $response->assertStatus(404)
        ->assertJson(['ok' => false, 'error' => 'vehicle_not_found']);
});

test('GET /api/vehicles/{vin}/alerts returns active maintenance alerts', function () {
    $vehicle = Vehicle::create([
        'vin' => 'WBA3A5C5XDF123456',
        'oem' => 'BMW',
        'model' => '320d',
        'model_year' => 2024,
        'tenant_id' => 'oem-bmw-de',
    ]);

    PredictiveMaintenanceAlert::create([
        'alert_uuid' => '550e8400-e29b-41d4-a716-446655440010',
        'vehicle_id' => $vehicle->id,
        'component' => 'brake_pads_front',
        'confidence_score' => 0.92,
        'estimated_km_remaining' => 5000,
        'suggested_action' => 'Replace front brake pads within 5000 km',
        'status' => 'active',
        'tenant_id' => 'oem-bmw-de',
        'expires_at' => now()->addDays(7),
    ]);

    $response = getJson('/api/vehicles/WBA3A5C5XDF123456/alerts');

    $response->assertStatus(200)
        ->assertJsonStructure(['ok', 'vehicle', 'alerts'])
        ->assertJsonCount(1, 'alerts')
        ->assertJsonPath('alerts.0.component', 'brake_pads_front');
});

test('PATCH /api/alerts/{uuid} updates alert status', function () {
    $vehicle = Vehicle::create([
        'vin' => 'WBA3A5C5XDF123456',
        'oem' => 'BMW',
        'model' => '320d',
        'model_year' => 2024,
        'tenant_id' => 'oem-bmw-de',
    ]);

    $alert = PredictiveMaintenanceAlert::create([
        'alert_uuid' => '550e8400-e29b-41d4-a716-446655440020',
        'vehicle_id' => $vehicle->id,
        'component' => 'battery',
        'confidence_score' => 0.75,
        'status' => 'active',
        'tenant_id' => 'oem-bmw-de',
        'expires_at' => now()->addDays(7),
    ]);

    $response = patchJson('/api/alerts/550e8400-e29b-41d4-a716-446655440020', [
        'status' => 'acknowledged',
    ]);

    $response->assertStatus(200)
        ->assertJson(['ok' => true, 'status' => 'acknowledged']);

    $alert->refresh();
    expect($alert->status)->toBe('acknowledged');
});
