<?php

use App\Enums\EmergencyDepartmentEquipmentStatus;
use App\Enums\EmergencyDepartmentShift;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\EmergencyDepartmentLogEntry;
use App\Models\HealthAide;
use App\Models\User;
use App\Services\EmergencyDepartmentLogDefinition;
use App\Services\EmergencyDepartmentLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     handover: array<string, array{count: int, remarks: string}>,
 *     equipment: array<string, array{status: string, remarks: string}>,
 *     crashCart: array<string, array{adequate: bool, remarks: string}>,
 *     cleaning: array<string, bool>
 * }
 */
function emergencyDepartmentLogAllOkPayload(): array
{
    $service = app(EmergencyDepartmentLogService::class);
    $definition = app(EmergencyDepartmentLogDefinition::class);

    $handover = [];
    foreach (array_keys($definition->handoverMetrics()) as $itemKey) {
        $handover[$itemKey] = [
            'count' => 0,
            'remarks' => '',
        ];
    }

    $equipment = [];
    foreach (array_keys($definition->equipmentItems()) as $itemKey) {
        $equipment[$itemKey] = [
            'status' => EmergencyDepartmentEquipmentStatus::Ok->value,
            'remarks' => '',
        ];
    }

    $crashCart = [];
    foreach ($definition->crashCartItems() as $item) {
        $crashCart[$item['item_key']] = [
            'adequate' => true,
            'remarks' => '',
        ];
    }

    $cleaning = [];
    foreach ($definition->cleaningItems() as $item) {
        $cleaning[$service->cleaningKey($item['section'], $item['item_key'])] = true;
    }

    return compact('handover', 'equipment', 'crashCart', 'cleaning');
}

function emergencyDepartmentLogAide(string $pin = '1234', string $name = 'Aide Sara'): HealthAide
{
    return HealthAide::factory()->create([
        'name' => $name,
        'pin' => $pin,
    ]);
}

test('guests are redirected from emergency department log pages', function () {
    $this->get(route('incharge.emergency-department-log'))->assertRedirect(route('login'));
    $this->get(route('incharge.emergency-department-log.form', ['shift' => 'morning']))->assertRedirect(route('login'));
});

test('incharge nurses can visit the emergency department log pages', function () {
    $nurse = User::factory()->inchargeNurse()->create();

    $this->actingAs($nurse)->get(route('incharge.emergency-department-log'))->assertOk();
    $this->actingAs($nurse)->get(route('incharge.emergency-department-log.form', ['shift' => 'morning']))->assertOk();
});

test('unauthorized users cannot visit emergency department log pages', function (UserRole $role, string $expected) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get(route('incharge.emergency-department-log'))->{$expected}();
})->with([
    'admin' => [UserRole::Admin, 'assertOk'],
    'indoor' => [UserRole::Indoor, 'assertOk'],
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
]);

test('incharge nurse can submit a fully ok log without notifying admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $aide = emergencyDepartmentLogAide();
    $payload = emergencyDepartmentLogAllOkPayload();
    $definition = app(EmergencyDepartmentLogDefinition::class);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'morning'])
        ->set('healthAideCode', '1234')
        ->set('handover', $payload['handover'])
        ->set('equipment', $payload['equipment'])
        ->set('crashCart', $payload['crashCart'])
        ->set('cleaning', $payload['cleaning'])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = EmergencyDepartmentLogEntry::query()->first();
    $expectedAnswers = count($payload['handover'])
        + count($payload['equipment'])
        + count($payload['crashCart'])
        + count($definition->cleaningItems());

    expect($entry)->not->toBeNull()
        ->and($entry->shift)->toBe(EmergencyDepartmentShift::Morning)
        ->and($entry->user_id)->toBe($nurse->id)
        ->and($entry->health_aide_id)->toBe($aide->id)
        ->and($entry->completed_by_name)->toBe('Aide Sara')
        ->and($entry->answers)->toHaveCount($expectedAnswers)
        ->and($entry->hasFaults())->toBeFalse()
        ->and(AdminNotification::count())->toBe(0);
});

test('invalid health aide code is rejected', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    emergencyDepartmentLogAide();
    $payload = emergencyDepartmentLogAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'morning'])
        ->set('healthAideCode', '9999')
        ->set('handover', $payload['handover'])
        ->set('equipment', $payload['equipment'])
        ->set('crashCart', $payload['crashCart'])
        ->set('cleaning', $payload['cleaning'])
        ->call('submit')
        ->assertHasErrors(['healthAideCode']);

    expect(EmergencyDepartmentLogEntry::count())->toBe(0);
});

test('equipment issues notify admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    emergencyDepartmentLogAide();
    $payload = emergencyDepartmentLogAllOkPayload();
    $payload['equipment']['defibrillator']['status'] = EmergencyDepartmentEquipmentStatus::Issue->value;
    $payload['equipment']['defibrillator']['remarks'] = 'Battery fault';

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'evening'])
        ->set('healthAideCode', '1234')
        ->set('handover', $payload['handover'])
        ->set('equipment', $payload['equipment'])
        ->set('crashCart', $payload['crashCart'])
        ->set('cleaning', $payload['cleaning'])
        ->call('submit')
        ->assertHasNoErrors();

    expect(AdminNotification::count())->toBe(1)
        ->and(AdminNotification::first()->type)->toBe('emergency_department_log_faults');
});

test('short crash cart stock notifies admins', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    emergencyDepartmentLogAide();
    $payload = emergencyDepartmentLogAllOkPayload();
    $payload['crashCart']['adrenaline']['adequate'] = false;

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'night'])
        ->set('healthAideCode', '1234')
        ->set('handover', $payload['handover'])
        ->set('equipment', $payload['equipment'])
        ->set('crashCart', $payload['crashCart'])
        ->set('cleaning', $payload['cleaning'])
        ->call('submit')
        ->assertHasNoErrors();

    $entry = EmergencyDepartmentLogEntry::query()->first();

    expect($entry->hasFaults())->toBeTrue()
        ->and(AdminNotification::count())->toBe(1);
});

test('a shift can only be submitted once', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    emergencyDepartmentLogAide();
    EmergencyDepartmentLogEntry::factory()->create([
        'user_id' => $nurse->id,
        'checklist_date' => now()->toDateString(),
        'shift' => EmergencyDepartmentShift::Morning,
    ]);

    $payload = emergencyDepartmentLogAllOkPayload();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'morning'])
        ->assertSee('Submitted Log')
        ->set('healthAideCode', '1234')
        ->set('handover', $payload['handover'])
        ->set('equipment', $payload['equipment'])
        ->set('crashCart', $payload['crashCart'])
        ->set('cleaning', $payload['cleaning'])
        ->call('submit');

    expect(EmergencyDepartmentLogEntry::count())->toBe(1);
});

test('mark helpers fill section answers', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $service = app(EmergencyDepartmentLogService::class);
    $cleaningKey = $service->cleaningKey('D1', 'floors_clean');

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'morning'])
        ->call('markEquipmentOk')
        ->assertSet('equipment.monitor.status', EmergencyDepartmentEquipmentStatus::Ok->value)
        ->call('markCrashCartAdequate')
        ->assertSet('crashCart.adrenaline.adequate', true)
        ->call('markCleaningDone')
        ->assertSet("cleaning.{$cleaningKey}", true);
});

test('wizard navigation moves between sections and shows save on the last step', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    emergencyDepartmentLogAide();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'morning'])
        ->assertSet('activeSection', 'header')
        ->assertSee('Next')
        ->set('healthAideCode', '1234')
        ->call('verifyHealthAideCode')
        ->assertHasNoErrors()
        ->assertSet('verifiedHealthAideName', 'Aide Sara')
        ->assertSee('Continuing as Aide Sara')
        ->call('nextSection')
        ->assertSet('activeSection', 'A')
        ->call('setSection', 'D')
        ->assertSet('activeSection', 'D')
        ->assertSee('Save')
        ->assertDontSee(__('Submit Log'));
});

test('next section is blocked until a valid health aide code is verified', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    emergencyDepartmentLogAide();

    Livewire::actingAs($nurse)
        ->test('pages::incharge.emergency-department-log-form', ['shift' => 'morning'])
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
