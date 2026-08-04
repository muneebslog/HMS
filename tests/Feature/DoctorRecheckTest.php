<?php

use App\Enums\TokenResetType;
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
 * @return array{0: User, 1: Shift, 2: Patient, 3: QueueToken}
 */
function createRecheckMedicationPatient(): array
{
    $user = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($user)->create();
    $shift = Shift::factory()->for(User::factory()->receptionist())->open()->create();
    $service = Service::factory()->needsMedication()->create([
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
    $patient = Patient::factory()->create(['name' => 'Recheck Patient']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(5),
    ]);

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => $user->id,
        'bp_systolic' => 180,
        'bp_diastolic' => 110,
    ]);

    return [$user, $shift, $patient, $token];
}

test('doctor can set a recheck timer for a patient', function () {
    [$user, , $patient, $token] = createRecheckMedicationPatient();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->assertSet('showRecheckForm', false)
        ->assertDontSee(__('Minutes'))
        ->call('openRecheckForm')
        ->assertSet('showRecheckForm', true)
        ->set('recheckMinutes', '20')
        ->set('recheckNote', 'Check BP again')
        ->call('setRecheck')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', null);

    $this->assertDatabaseHas('doctor_rechecks', [
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
        'minutes' => 20,
        'note' => 'Check BP again',
    ]);

    expect(DoctorRecheck::where('queue_token_id', $token->id)->first()?->due_at->greaterThan(now()->addMinutes(19)))->toBeTrue();
});

test('due recheck shows again on the medication list', function () {
    [$user, , $patient, $token] = createRecheckMedicationPatient();

    DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
        'note' => 'BP',
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSee($patient->name)
        ->assertSee(__('Again'));
});

test('notify due rechecks marks them notified once', function () {
    [$user, , $patient, $token] = createRecheckMedicationPatient();

    $recheck = DoctorRecheck::factory()->due()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
        'note' => 'BP',
        'notified_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('notifyDueRechecks');

    expect($recheck->fresh()->notified_at)->not->toBeNull();

    $notifiedAt = $recheck->fresh()->notified_at;

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('notifyDueRechecks');

    expect($recheck->fresh()->notified_at->equalTo($notifiedAt))->toBeTrue();
});

test('doctor can acknowledge a due recheck', function () {
    [$user, , $patient, $token] = createRecheckMedicationPatient();

    DoctorRecheck::factory()->notified()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('acknowledgeRecheck', $token->id)
        ->assertHasNoErrors();

    expect(DoctorRecheck::where('queue_token_id', $token->id)->whereNull('acknowledged_at')->exists())->toBeFalse();
});

test('setting a new recheck replaces the previous pending one', function () {
    [$user, , $patient, $token] = createRecheckMedicationPatient();

    DoctorRecheck::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'set_by' => $user->id,
        'minutes' => 10,
        'due_at' => now()->addMinutes(10),
    ]);

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $token->id)
        ->call('openRecheckForm')
        ->set('recheckMinutes', '30')
        ->set('recheckNote', 'BP again')
        ->call('setRecheck')
        ->assertHasNoErrors();

    expect(DoctorRecheck::where('queue_token_id', $token->id)->whereNull('acknowledged_at')->count())->toBe(1);
    expect(DoctorRecheck::where('queue_token_id', $token->id)->whereNull('acknowledged_at')->value('minutes'))->toBe(30);
});
