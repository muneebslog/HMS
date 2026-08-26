<?php

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\EquipmentInspectionEntry;
use App\Models\HealthAide;
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

function equipmentInspectionAide(string $pin = '1234', string $name = 'Aide Sara'): HealthAide
{
    return HealthAide::factory()->create([
        'name' => $name,
        'pin' => $pin,
    ]);
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

test('incharge nurse can submit a fully ok consultation room checklist without notifying admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $aide = equipmentInspectionAide();
    $payload = equipmentInspectionAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->set('healthAideCode', '1234')
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
        ->and($entry->health_aide_id)->toBe($aide->id)
        ->and($entry->checked_by_name)->toBe('Aide Sara')
        ->and($entry->answers)->toHaveCount(count($payload['equipment']) + count($payload['checklist']))
        ->and($entry->hasFaults())->toBeFalse()
        ->and(AdminNotification::count())->toBe(0);
});

test('indoor staff can submit a fully ok consultation room checklist without notifying admins', function () {
    $staff = User::factory()->indoor()->create();
    equipmentInspectionAide();
    $payload = equipmentInspectionAllOkPayload();

    Livewire::actingAs($staff)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->set('healthAideCode', '1234')
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

test('invalid health aide code is rejected', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    equipmentInspectionAide();
    $payload = equipmentInspectionAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->set('healthAideCode', '9999')
        ->set('equipment', $payload['equipment'])
        ->set('checklist', $payload['checklist'])
        ->set('signOff', $payload['signOff'])
        ->call('submit')
        ->assertHasErrors(['healthAideCode']);

    expect(EquipmentInspectionEntry::count())->toBe(0);
});

test('equipment faults notify admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    equipmentInspectionAide();
    $payload = equipmentInspectionAllOkPayload();
    $payload['equipment']['examination_couch']['present'] = false;
    $payload['equipment']['examination_couch']['maint_req'] = true;

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'evening',
        ])
        ->set('healthAideCode', '1234')
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
    equipmentInspectionAide();
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
        ->set('healthAideCode', '1234')
        ->set('equipment', $payload['equipment'])
        ->set('checklist', $payload['checklist'])
        ->set('signOff', $payload['signOff'])
        ->call('submit');

    expect(EquipmentInspectionEntry::count())->toBe(1);
});

test('maintenance register rows are persisted', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    equipmentInspectionAide();
    $area = EquipmentInspectionArea::MaintenanceRegister;
    $payload = equipmentInspectionAllOkPayload($area);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'maintenance_register',
            'shift' => 'morning',
        ])
        ->set('healthAideCode', '1234')
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

test('wizard navigation moves between sections and shows save on the last step', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    equipmentInspectionAide();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->assertSet('activeSection', 'header')
        ->assertSee('Next')
        ->set('healthAideCode', '1234')
        ->call('verifyHealthAideCode')
        ->assertHasNoErrors()
        ->assertSet('verifiedHealthAideName', 'Aide Sara')
        ->assertSee('Continuing as Aide Sara')
        ->call('nextSection')
        ->assertSet('activeSection', 'A')
        ->call('setSection', 'signoff')
        ->assertSet('activeSection', 'signoff')
        ->assertSee('Save')
        ->assertDontSee(__('Submit Checklist'));
});

test('next section is blocked until a valid health aide code is verified', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    equipmentInspectionAide();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.equipment-inspection-form', [
            'area' => 'consultation_room',
            'shift' => 'morning',
        ])
        ->set('healthAideCode', '9999')
        ->call('nextSection')
        ->assertHasErrors(['healthAideCode'])
        ->assertSet('activeSection', 'header')
        ->set('healthAideCode', '1234')
        ->call('nextSection')
        ->assertHasNoErrors()
        ->assertSet('activeSection', 'A')
        ->assertSet('verifiedHealthAideName', 'Aide Sara');
});
