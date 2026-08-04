<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
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
 * @return array{0: User, 1: Shift, 2: Service, 3: ServiceQueue, 4: Patient, 5: QueueToken}
 */
function createVitalsQueuePatient(bool $needsVitals = true, string $tokenStatus = 'waiting'): array
{
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'needs_vitals' => $needsVitals,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $doctor = Doctor::factory()->create();
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $patient = Patient::factory()->create(['name' => 'Ayesha Khan']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => $tokenStatus,
        'arrived_at' => now()->subMinutes(5),
    ]);

    return [$user, $shift, $service, $queue, $patient, $token];
}

test('receptionist can access vitals page with an open shift', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.vitals'))
        ->assertSuccessful();
});

test('admin can access vitals page with an open shift', function () {
    $user = User::factory()->admin()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.vitals'))
        ->assertSuccessful();
});

test('receptionist is redirected to shift page when accessing vitals without an open shift', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('reception.vitals'))
        ->assertRedirect(route('reception.shift'));
});

test('management cannot access vitals page', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.vitals'))
        ->assertForbidden();
});

test('vitals queue lists waiting tokens for services that need vitals', function () {
    [$user, , , , $patient, $token] = createVitalsQueuePatient();

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->assertSee($patient->name)
        ->assertSee((string) $token->token_number);
});

test('vitals queue excludes tokens for services that do not need vitals', function () {
    [$user, , , , $patient] = createVitalsQueuePatient(needsVitals: false);

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->assertDontSee($patient->name)
        ->assertSee(__('No patients need vitals'));
});

test('vitals queue excludes tokens that already have vitals recorded', function () {
    [$user, , , , $patient, $token] = createVitalsQueuePatient();

    Vital::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'recorded_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->assertDontSee($patient->name)
        ->assertSee(__('No patients need vitals'));
});

test('selecting a token opens the capture form', function () {
    [$user, , , , $patient, $token] = createVitalsQueuePatient();

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->call('selectToken', $token->id)
        ->assertSet('selectedTokenId', $token->id)
        ->assertSee($patient->name)
        ->assertSee(__('Next'));
});

test('saving vitals keeps token waiting and advances to the next patient', function () {
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->needsVitals()->create([
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $doctor = Doctor::factory()->create();
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $firstPatient = Patient::factory()->create(['name' => 'First Patient']);
    $secondPatient = Patient::factory()->create(['name' => 'Second Patient']);

    $firstToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(10),
    ]);
    $secondToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(5),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->call('selectToken', $firstToken->id)
        ->set('temperatureFahrenheit', '98.6')
        ->set('bpSystolic', '120')
        ->set('bpDiastolic', '80')
        ->call('saveAndNext')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', $secondToken->id)
        ->assertSee('Second Patient');

    $this->assertDatabaseHas('vitals', [
        'queue_token_id' => $firstToken->id,
        'patient_id' => $firstPatient->id,
        'recorded_by' => $user->id,
        'temperature' => '98.6',
        'bp_systolic' => 120,
        'bp_diastolic' => 80,
    ]);

    expect($firstToken->fresh()->status)->toBe('waiting');
});

test('saving the last patient returns to the empty queue list', function () {
    [$user, , , , $patient, $token] = createVitalsQueuePatient();

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->call('selectToken', $token->id)
        ->set('temperatureFahrenheit', '99.5')
        ->set('bpSystolic', '110')
        ->set('bpDiastolic', '70')
        ->call('saveAndNext')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', null)
        ->assertSee(__('No patients need vitals'));

    expect($token->fresh()->status)->toBe('waiting');
    expect(Vital::where('queue_token_id', $token->id)->exists())->toBeTrue();
});

test('vitals capture requires temperature and blood pressure', function () {
    [$user, , , , , $token] = createVitalsQueuePatient();

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->call('selectToken', $token->id)
        ->call('saveAndNext')
        ->assertHasErrors(['temperatureFahrenheit', 'bpSystolic', 'bpDiastolic']);
});

test('bsr is optional when saving vitals', function () {
    [$user, , , , $patient, $token] = createVitalsQueuePatient();

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->call('selectToken', $token->id)
        ->set('temperatureFahrenheit', '98.6')
        ->set('bpSystolic', '120')
        ->set('bpDiastolic', '80')
        ->set('bsr', '')
        ->call('saveAndNext')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('vitals', [
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'bsr' => null,
    ]);
});

test('bsr is saved when provided', function () {
    [$user, , , , $patient, $token] = createVitalsQueuePatient();

    Livewire::actingAs($user)
        ->test('pages::reception.vitals')
        ->call('selectToken', $token->id)
        ->set('temperatureFahrenheit', '98.6')
        ->set('bpSystolic', '120')
        ->set('bpDiastolic', '80')
        ->set('bsr', '145')
        ->call('saveAndNext')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('vitals', [
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'bsr' => 145,
    ]);
});
