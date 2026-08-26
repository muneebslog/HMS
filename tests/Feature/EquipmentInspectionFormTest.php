<?php

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\EquipmentInspectionEntry;
use App\Models\User;
use App\Services\EquipmentInspectionChecklistDefinition;
use App\Services\EquipmentInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{equipment: array<string, array{present: bool, functional: bool, clean: bool, maint_req: bool, remarks: string}>, checklist: array<string, bool>, signOff: array<string, string>}
 */
function equipmentInspectionAllOkPayload(EquipmentInspectionArea $area = EquipmentInspectionArea::ConsultationRoom): array
{
    $service = app(EquipmentInspectionService::class);
    $definition = app(EquipmentInspectionChecklistDefinition::class);

    $equipment = [];
    foreach (array_keys($definition->equipmentItems($area)) as $itemKey) {
        $equipment[$itemKey] = [
            'present' => true,
            'functional' => true,
            'clean' => true,
            'maint_req' => false,
            'remarks' => '',
        ];
    }

    $checklist = [];
    foreach ($definition->checklistSections($area) as $section) {
        foreach ($definition->checklistItems($area, $section) as $itemKey => $label) {
            $checklist[$service->checklistKey($section, $itemKey)] = true;
        }
    }

    $signOff = [];
    foreach ($definition->signOffFields($area) as $fieldKey => $field) {
        $signOff[$fieldKey] = match ($field['type']) {
            'yes_no' => $fieldKey === 'equip_issues' || $fieldKey === 'equip_defect' || $fieldKey === 'faults_identified'
                ? 'no'
                : 'yes',
            'choice' => $field['choices'][0],
            default => 'QA Lead',
        };
    }

    return compact('equipment', 'checklist', 'signOff');
}

test('guests are redirected from equipment inspection pages', function () {
    $this->get(route('incharge.equipment-inspections'))->assertRedirect(route('login'));
    $this->get(route('incharge.equipment-inspections.area', ['area' => 'consultation_room']))->assertRedirect(route('login'));
    $this->get(route('incharge.equipment-inspections.form', [
        'area' => 'consultation_room',
        'shift' => 'morning',
    ]))->assertRedirect(route('login'));
});

test('incharge nurses can visit the equipment inspection pages', function () {
    $nurse = User::factory()->inchargeNurse()->create();

    $this->actingAs($nurse)->get(route('incharge.equipment-inspections'))->assertOk();
    $this->actingAs($nurse)->get(route('incharge.equipment-inspections.area', ['area' => 'consultation_room']))->assertOk();
    $this->actingAs($nurse)->get(route('incharge.equipment-inspections.form', [
        'area' => 'consultation_room',
        'shift' => 'morning',
    ]))->assertOk();
});

test('indoor staff can visit the equipment inspection pages', function () {
    $staff = User::factory()->indoor()->create();

    $this->actingAs($staff)->get(route('incharge.equipment-inspections'))->assertOk();
    $this->actingAs($staff)->get(route('incharge.equipment-inspections.area', ['area' => 'consultation_room']))->assertOk();
    $this->actingAs($staff)->get(route('incharge.equipment-inspections.form', [
        'area' => 'consultation_room',
        'shift' => 'morning',
    ]))->assertOk();
});

test('unauthorized users cannot visit equipment inspection pages', function (UserRole $role, string $expected) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get(route('incharge.equipment-inspections'))->{$expected}();
})->with([
    'admin' => [UserRole::Admin, 'assertOk'],
    'indoor' => [UserRole::Indoor, 'assertOk'],
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
]);

test('indoor staff can submit a fully ok consultation room checklist without notifying admins', function () {
    $staff = User::factory()->indoor()->create();
    $payload = equipmentInspectionAllOkPayload();

    Livewire::actingAs($staff)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->set('checkedByName', $staff->name)
        ->set('equipment', $payload['equipment'])
        ->set('checklist', $payload['checklist'])
        ->set('signOff', $payload['signOff'])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = EquipmentInspectionEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($staff->id)
        ->and($entry->hasFaults())->toBeFalse()
        ->and(AdminNotification::count())->toBe(0);
});

test('incharge nurse can submit a fully ok consultation room checklist without notifying admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $payload = equipmentInspectionAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->set('checkedByName', $nurse->name)
        ->set('equipment', $payload['equipment'])
        ->set('checklist', $payload['checklist'])
        ->set('signOff', $payload['signOff'])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = EquipmentInspectionEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->area)->toBe(EquipmentInspectionArea::ConsultationRoom)
        ->and($entry->shift)->toBe(EquipmentInspectionShift::Morning)
        ->and($entry->user_id)->toBe($nurse->id)
        ->and($entry->answers)->toHaveCount(count($payload['equipment']) + count($payload['checklist']))
        ->and($entry->hasFaults())->toBeFalse()
        ->and(AdminNotification::count())->toBe(0);
});

test('equipment faults notify admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $payload = equipmentInspectionAllOkPayload();
    $payload['equipment']['examination_couch']['present'] = false;
    $payload['equipment']['examination_couch']['maint_req'] = true;

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'evening',
        ])
        ->set('checkedByName', $nurse->name)
        ->set('equipment', $payload['equipment'])
        ->set('checklist', $payload['checklist'])
        ->set('signOff', $payload['signOff'])
        ->call('submit')
        ->assertHasNoErrors();

    expect(AdminNotification::count())->toBe(1)
        ->and(AdminNotification::first()->type)->toBe('equipment_inspection_faults');
});

test('a shift can only be submitted once per area', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    EquipmentInspectionEntry::factory()->create([
        'user_id' => $nurse->id,
        'area' => EquipmentInspectionArea::ConsultationRoom,
        'checklist_date' => now()->toDateString(),
        'shift' => EquipmentInspectionShift::Morning,
    ]);

    $payload = equipmentInspectionAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->assertSee('Submitted Checklist')
        ->set('checkedByName', $nurse->name)
        ->set('equipment', $payload['equipment'])
        ->set('checklist', $payload['checklist'])
        ->set('signOff', $payload['signOff'])
        ->call('submit');

    expect(EquipmentInspectionEntry::count())->toBe(1);
});

test('maintenance register rows are persisted', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $area = EquipmentInspectionArea::MaintenanceRegister;
    $payload = equipmentInspectionAllOkPayload($area);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'maintenance_register',
            'shift' => 'morning',
        ])
        ->set('checkedByName', $nurse->name)
        ->set('signOff', $payload['signOff'])
        ->set('registerRows', [[
            'item_date' => now()->toDateString(),
            'department' => 'OT',
            'equipment' => 'Anaesthesia machine',
            'problem' => 'Leak detected',
            'action_taken' => 'Sealed and recalibrated',
            'technician' => 'Tech Ali',
            'completed_date' => now()->toDateString(),
            'signed' => 'TA',
        ]])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = EquipmentInspectionEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->area)->toBe(EquipmentInspectionArea::MaintenanceRegister)
        ->and($entry->registerRows)->toHaveCount(1)
        ->and($entry->registerRows->first()->equipment)->toBe('Anaesthesia machine')
        ->and(AdminNotification::count())->toBe(1);
});

test('mark equipment ok fills equipment cells', function () {
    $nurse = User::factory()->inchargeNurse()->create();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->call('markEquipmentOk')
        ->assertSet('equipment.examination_couch.present', true)
        ->assertSet('equipment.examination_couch.functional', true)
        ->assertSet('equipment.examination_couch.clean', true)
        ->assertSet('equipment.examination_couch.maint_req', false);
});
