<?php

use App\Enums\TokenResetType;
use App\Enums\UltrasoundBiophysicalProfile;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\UltrasoundReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: Service, 1: Doctor, 2: ServiceQueue}
 */
function createConsultationQueue(User $user, Shift $shift): array
{
    $service = Service::factory()->create([
        'name' => 'consultation',
        'is_standalone' => false,
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

    return [$service, $doctor, $queue];
}

test('ultrasound form lists open consultation queues', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    [, , $queue] = createConsultationQueue($user, $shift);

    Livewire::actingAs($user)
        ->test('pages::reception.ultrasound')
        ->assertSee($queue->doctor->name);
});

test('selecting a queue loads its waiting tokens', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    [, , $queue] = createConsultationQueue($user, $shift);
    $patient = Patient::factory()->create();
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.ultrasound')
        ->set('selectedQueueId', $queue->id)
        ->assertSee($patient->name)
        ->assertSee((string) $token->token_number);
});

test('selecting a token fills patient details', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    [, , $queue] = createConsultationQueue($user, $shift);
    $patient = Patient::factory()->create([
        'name' => 'Jane Doe',
        'age' => 28,
    ]);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.ultrasound')
        ->set('selectedQueueId', $queue->id)
        ->set('selectedTokenId', $token->id)
        ->assertSet('name', 'Jane Doe')
        ->assertSet('age', 28);
});

test('saving an ultrasound report stores data and marks token served', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    [, , $queue] = createConsultationQueue($user, $shift);
    $patient = Patient::factory()->create([
        'name' => 'Jane Doe',
        'age' => 28,
    ]);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.ultrasound')
        ->set('selectedQueueId', $queue->id)
        ->set('selectedTokenId', $token->id)
        ->set('reportDate', '2026-07-22')
        ->set('name', 'Jane Doe')
        ->set('age', 28)
        ->set('fetusStatus', 'intrauterine')
        ->set('bpdMeas', '85')
        ->set('bpdAge', '34')
        ->set('femurMeas', '65')
        ->set('femurAge', '33')
        ->set('acMeas', '300')
        ->set('acAge', '34')
        ->set('crlMeas', '55')
        ->set('crlAge', '12')
        ->set('gestAge', '34')
        ->set('edd', '15-02-2027')
        ->set('heartMotion', 'Present')
        ->set('placenta', 'Anterior')
        ->set('placentaGrade', 'Grade II')
        ->set('amnioticFluid', 'Adequate')
        ->set('presentation', 'Cephalic')
        ->set('ltVentricular', true)
        ->set('bpdLevel', true)
        ->set('feralStomach', true)
        ->set('kidneys', false)
        ->set('bladder', true)
        ->set('spine', true)
        ->set('bpp', UltrasoundBiophysicalProfile::Good->value)
        ->set('conclusionLine1', 'Single live intrauterine fetus.')
        ->set('conclusionLine2', 'Follow up as advised.')
        ->call('save')
        ->assertHasNoErrors();

    $report = UltrasoundReport::first();

    expect($report)->not->toBeNull()
        ->and($report->queue_token_id)->toBe($token->id)
        ->and($report->patient_id)->toBe($patient->id)
        ->and($report->service_queue_id)->toBe($queue->id)
        ->and($report->name)->toBe('Jane Doe')
        ->and($report->age)->toBe(28)
        ->and($report->bpd_meas)->toBe('85')
        ->and($report->bpp)->toBe(UltrasoundBiophysicalProfile::Good)
        ->and($report->kidneys)->toBeFalse()
        ->and($report->spine)->toBeTrue();

    expect($token->fresh()->status)->toBe('served');
});

test('print view renders report data at expected positions', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    [, $doctor, $queue] = createConsultationQueue($user, $shift);
    $patient = Patient::factory()->create();
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'served',
    ]);

    $report = UltrasoundReport::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'service_queue_id' => $queue->id,
        'name' => 'Jane Doe',
        'age' => 28,
        'bpp' => UltrasoundBiophysicalProfile::Normal,
        'spine' => true,
        'kidneys' => false,
    ]);

    $response = $this->actingAs($user)->get(route('reception.ultrasound.print', $report));

    $response->assertOk()
        ->assertSee('Jane Doe')
        ->assertSee('28')
        ->assertSee('X');
});
