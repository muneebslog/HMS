<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('display.pin', '1234');
});

test('guests can view the display page', function () {
    $response = $this->get(route('display.tokens'));

    $response->assertOk();
});

test('open queues for today are listed on the display page', function () {
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->assertSee($service->name)
        ->assertSee($doctor->name);
});

test('closed queues are not listed on the display page', function () {
    $service = Service::factory()->create();

    ServiceQueue::factory()->closed()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
    ]);

    Livewire::test('pages::display.token-display')
        ->assertDontSee($service->name);
});

test('queues from other dates are not listed on the display page', function () {
    $service = Service::factory()->create();

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today()->subDay(),
        'reset_type' => TokenResetType::Daily,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->assertDontSee($service->name);
});

test('selecting a queue shows the current serving token', function () {
    $patient = Patient::factory()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 5,
        'status' => 'serving',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSet('selectedQueueId', $queue->id)
        ->assertSee($token->token_number)
        ->assertSee($patient->name)
        ->assertSee($doctor->name);
});

test('guests cannot call the next token without verifying the pin', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('callNext')
        ->assertStatus(403);
});

test('verified users can call the next token', function () {
    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $currentToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'serving',
        'created_at' => now()->subMinute(),
    ]);

    $nextToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
        'created_at' => now(),
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('callNext');

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('calling next with no serving token marks the oldest waiting token as serving', function () {
    $patient = Patient::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('callNext');

    expect($token->fresh()->status)->toBe('serving');
});

test('verified users can call the previous token', function () {
    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $previousToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'served',
    ]);

    $currentToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
        'status' => 'serving',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('callPrevious');

    expect($currentToken->fresh()->status)->toBe('waiting')
        ->and($previousToken->fresh()->status)->toBe('serving');
});

test('call next selects the next token number in order', function () {
    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $currentToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'serving',
    ]);

    $nextToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('callNext');

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('call next serves a reserved token in numeric order and shows not arrived badge', function () {
    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $currentToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'serving',
    ]);

    $nextToken = QueueToken::factory()->reserved()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('callNext');

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('display shows arrived badge for the current serving token', function () {
    $patient = Patient::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 5,
        'status' => 'serving',
        'origin' => 'reservation',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSee(__('Arrived'))
        ->assertSee($patient->name);
});

test('entering the correct pin unlocks the display controls', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->set('pin', '1234')
        ->call('verifyPin')
        ->assertSet('pinVerified', true)
        ->assertHasNoErrors('pin');
});

test('entering an incorrect pin does not unlock the display controls', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->set('pin', '0000')
        ->call('verifyPin')
        ->assertSet('pinVerified', false)
        ->assertHasErrors('pin');
});

test('locking the controls clears the verified pin session', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSet('pinVerified', true)
        ->call('lock')
        ->assertSet('pinVerified', false);
});
