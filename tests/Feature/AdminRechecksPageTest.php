<?php

use App\Enums\TokenResetType;
use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\DoctorRecheck;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: User, 2: Patient, 3: QueueToken, 4: Shift}
 */
function createAdminRecheckContext(): array
{
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $doctorUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($doctorUser)->create();
    $shift = Shift::factory()->for(User::factory()->receptionist())->open()->create();
    $service = Service::factory()->needsMedication()->needsVitals()->create([
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $patient = Patient::factory()->create(['name' => 'Timer Patient', 'mrn' => 'MRN-TIMER-1']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 7,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(20),
    ]);

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => User::factory()->receptionist()->create()->id,
        'bp_systolic' => 170,
        'bp_diastolic' => 100,
    ]);

    return [$admin, $doctorUser, $patient, $token, $shift];
}

test('admin can visit the recheck timers page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.rechecks'))
        ->assertSuccessful();
});

test('non admin cannot visit the recheck timers page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.rechecks'))
        ->assertForbidden();
});

test('admin rechecks page lists people on timer and vitals redo status', function () {
    [$admin, $doctorUser, $patient, $token] = createAdminRecheckContext();

    DoctorRecheck::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $doctorUser->id,
        'minutes' => 15,
        'note' => 'Check BP again',
        'due_at' => now()->addMinutes(10),
    ]);

    $duePatient = Patient::factory()->create(['name' => 'Due Patient']);
    $dueToken = QueueToken::factory()->create([
        'service_queue_id' => $token->service_queue_id,
        'patient_id' => $duePatient->id,
        'token_number' => 8,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(30),
    ]);
    Vital::factory()->create([
        'queue_token_id' => $dueToken->id,
        'patient_id' => $duePatient->id,
        'recorded_by' => $doctorUser->id,
    ]);
    DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $dueToken->id,
        'patient_id' => $duePatient->id,
        'set_by' => $doctorUser->id,
        'note' => 'BP',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.rechecks')
        ->assertSee('Timer Patient')
        ->assertSee('Due Patient')
        ->assertSee(__('On timer'))
        ->assertSee(__('Awaiting vitals'))
        ->assertSee(__('No'))
        ->assertSee(__('min left'));
});

test('admin can filter rechecks awaiting vitals', function () {
    [$admin, $doctorUser, $patient, $token] = createAdminRecheckContext();

    DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $doctorUser->id,
        'note' => 'BP',
    ]);

    $redonePatient = Patient::factory()->create(['name' => 'Redone Patient']);
    $redoneToken = QueueToken::factory()->create([
        'service_queue_id' => $token->service_queue_id,
        'patient_id' => $redonePatient->id,
        'token_number' => 9,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);
    DoctorRecheck::factory()->vitalsRedone()->create([
        'queue_token_id' => $redoneToken->id,
        'patient_id' => $redonePatient->id,
        'set_by' => $doctorUser->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.rechecks')
        ->set('statusFilter', 'awaiting_vitals')
        ->assertSee('Timer Patient')
        ->assertDontSee('Redone Patient');
});

test('vitals page includes due recheck patients and marks vitals redone', function () {
    [, $doctorUser, $patient, $token] = createAdminRecheckContext();
    $receptionist = User::factory()->receptionist()->create();

    DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $doctorUser->id,
        'note' => 'Check BP again',
    ]);

    Livewire::actingAs($receptionist)
        ->test('pages::reception.vitals')
        ->assertSee($patient->name)
        ->assertSee(__('Again'))
        ->call('selectToken', $token->id)
        ->set('temperatureFahrenheit', '98.6')
        ->set('bpSystolic', '130')
        ->set('bpDiastolic', '85')
        ->set('bsr', '110')
        ->call('saveAndNext')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('vitals', [
        'queue_token_id' => $token->id,
        'bp_systolic' => 130,
        'bp_diastolic' => 85,
        'bsr' => 110,
    ]);

    $this->assertDatabaseHas('vitals', [
        'queue_token_id' => $token->id,
        'bp_systolic' => 170,
        'bp_diastolic' => 100,
    ]);

    expect(Vital::where('queue_token_id', $token->id)->count())->toBe(2);
    expect(DoctorRecheck::where('queue_token_id', $token->id)->value('vitals_redone_at'))->not->toBeNull();
});
