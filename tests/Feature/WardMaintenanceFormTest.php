<?php

use App\Enums\UserRole;
use App\Enums\WardMaintenanceShift;
use App\Enums\WardMaintenanceStatus;
use App\Models\AdminNotification;
use App\Models\User;
use App\Models\WardMaintenanceEntry;
use App\Services\WardMaintenanceChecklistDefinition;
use App\Services\WardMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{statuses: array<string, string>, equipment: array<string, array{available: bool, functional: bool, remarks: string}>}
 */
function wardMaintenanceAllOkPayload(): array
{
    $service = app(WardMaintenanceService::class);
    $definition = app(WardMaintenanceChecklistDefinition::class);

    $statuses = [];
    foreach ($definition->statusCells() as $cell) {
        $key = $service->statusKey($cell['section'], $cell['item_key'], $cell['location_key']);
        $statuses[$key] = WardMaintenanceStatus::Ok->value;
    }

    $equipment = [];
    foreach (array_keys($definition->sectionEItems()) as $itemKey) {
        $equipment[$itemKey] = [
            'available' => true,
            'functional' => true,
            'remarks' => '',
        ];
    }

    return compact('statuses', 'equipment');
}

test('guests are redirected from ward maintenance pages', function () {
    $this->get(route('incharge.ward-maintenance'))->assertRedirect(route('login'));
    $this->get(route('incharge.ward-maintenance.form', ['shift' => 'morning']))->assertRedirect(route('login'));
});

test('incharge nurses can visit the ward maintenance pages', function () {
    $nurse = User::factory()->inchargeNurse()->create();

    $this->actingAs($nurse)->get(route('incharge.ward-maintenance'))->assertOk();
    $this->actingAs($nurse)->get(route('incharge.ward-maintenance.form', ['shift' => 'morning']))->assertOk();
});

test('indoor staff can visit the ward maintenance pages', function () {
    $staff = User::factory()->indoor()->create();

    $this->actingAs($staff)->get(route('incharge.ward-maintenance'))->assertOk();
    $this->actingAs($staff)->get(route('incharge.ward-maintenance.form', ['shift' => 'morning']))->assertOk();
});

test('unauthorized users cannot visit ward maintenance pages', function (UserRole $role, string $expected) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get(route('incharge.ward-maintenance'))->{$expected}();
})->with([
    'admin' => [UserRole::Admin, 'assertOk'],
    'indoor' => [UserRole::Indoor, 'assertOk'],
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
]);

test('indoor staff can submit a fully ok checklist without notifying admins', function () {
    $staff = User::factory()->indoor()->create();
    $payload = wardMaintenanceAllOkPayload();

    Livewire::actingAs($staff)
        ->test('pages::incharge.ward-maintenance-form', ['shift' => 'morning'])
        ->set('checkedByName', $staff->name)
        ->set('patientSafetyFault', 'no')
        ->set('patientSafetyReported', 'na')
        ->set('roomUnavailable', 'no')
        ->set('statuses', $payload['statuses'])
        ->set('equipment', $payload['equipment'])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = WardMaintenanceEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($staff->id)
        ->and(AdminNotification::count())->toBe(0);
});

test('incharge nurse can submit a fully ok checklist without notifying admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $payload = wardMaintenanceAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.ward-maintenance-form', ['shift' => 'morning'])
        ->set('checkedByName', $nurse->name)
        ->set('patientSafetyFault', 'no')
        ->set('patientSafetyReported', 'na')
        ->set('roomUnavailable', 'no')
        ->set('statuses', $payload['statuses'])
        ->set('equipment', $payload['equipment'])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = WardMaintenanceEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->shift)->toBe(WardMaintenanceShift::Morning)
        ->and($entry->user_id)->toBe($nurse->id)
        ->and($entry->answers)->toHaveCount(count($payload['statuses']) + count($payload['equipment']))
        ->and(AdminNotification::count())->toBe(0);
});

test('fault answers notify admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $payload = wardMaintenanceAllOkPayload();
    $service = app(WardMaintenanceService::class);
    $faultKey = $service->statusKey('A', 'bed_condition', 'B1');
    $payload['statuses'][$faultKey] = WardMaintenanceStatus::Fault->value;

    Livewire::actingAs($nurse)
        ->test('pages::incharge.ward-maintenance-form', ['shift' => 'evening'])
        ->set('checkedByName', $nurse->name)
        ->set('patientSafetyFault', 'no')
        ->set('patientSafetyReported', 'na')
        ->set('roomUnavailable', 'no')
        ->set('statuses', $payload['statuses'])
        ->set('equipment', $payload['equipment'])
        ->call('submit')
        ->assertHasNoErrors();

    expect(AdminNotification::count())->toBe(1)
        ->and(AdminNotification::first()->type)->toBe('ward_maintenance_faults');
});

test('fault report rows notify admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $payload = wardMaintenanceAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.ward-maintenance-form', ['shift' => 'night'])
        ->set('checkedByName', $nurse->name)
        ->set('patientSafetyFault', 'no')
        ->set('patientSafetyReported', 'na')
        ->set('roomUnavailable', 'no')
        ->set('statuses', $payload['statuses'])
        ->set('equipment', $payload['equipment'])
        ->set('faultRows', [[
            'fault_time' => '14:00',
            'bed_room' => 'B3',
            'description' => 'Side rail broken',
            'priority' => 'urgent',
            'reported_to' => 'Maintenance',
            'action_taken' => 'Tagged bed',
            'resolved' => false,
        ]])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = WardMaintenanceEntry::query()->first();

    expect($entry->faults)->toHaveCount(1)
        ->and(AdminNotification::count())->toBe(1);
});

test('a shift can only be submitted once', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    WardMaintenanceEntry::factory()->create([
        'user_id' => $nurse->id,
        'checklist_date' => now()->toDateString(),
        'shift' => WardMaintenanceShift::Morning,
    ]);

    $payload = wardMaintenanceAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.ward-maintenance-form', ['shift' => 'morning'])
        ->assertSee('Submitted Checklist')
        ->set('checkedByName', $nurse->name)
        ->set('patientSafetyFault', 'no')
        ->set('patientSafetyReported', 'na')
        ->set('roomUnavailable', 'no')
        ->set('statuses', $payload['statuses'])
        ->set('equipment', $payload['equipment'])
        ->call('submit');

    expect(WardMaintenanceEntry::count())->toBe(1);
});

test('mark section ok fills status cells', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $service = app(WardMaintenanceService::class);
    $key = $service->statusKey('F', 'nurses_station', '');

    Livewire::actingAs($nurse)
        ->test('pages::incharge.ward-maintenance-form', ['shift' => 'morning'])
        ->call('markSectionOk', 'F')
        ->assertSet("statuses.{$key}", WardMaintenanceStatus::Ok->value);
});
