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

test('upcoming tokens are shown for the selected queue', function () {
    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $firstToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'created_at' => now()->subMinute(),
    ]);

    $secondToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
        'created_at' => now(),
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSee(__('Upcoming'))
        ->assertSee($firstToken->token_number)
        ->assertSee($firstPatient->name)
        ->assertSee($secondToken->token_number)
        ->assertSee($secondPatient->name);
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

test('verified users can skip the current token', function () {
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
        ->call('skipCurrent');

    expect($currentToken->fresh()->status)->toBe('skipped')
        ->and($nextToken->fresh()->status)->toBe('serving');
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

test('recalling a token does not change its status', function () {
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
        'status' => 'serving',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('recallCurrent');

    expect($token->fresh()->status)->toBe('serving');
});

test('guests cannot skip the current token', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('skipCurrent')
        ->assertStatus(403);
});

test('guests cannot recall a token', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('recallCurrent')
        ->assertStatus(403);
});

test('upcoming tokens sidebar is hidden until the pin is verified', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSet('sidebarOpen', false)
        ->assertDontSee(__('Upcoming'));
});

test('guests cannot toggle the upcoming tokens sidebar', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('toggleSidebar')
        ->assertStatus(403);
});

test('verified users can collapse and reopen the upcoming tokens sidebar', function () {
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
        ->assertSet('sidebarOpen', true)
        ->assertSee(__('Upcoming'))
        ->call('toggleSidebar')
        ->assertSet('sidebarOpen', false)
        ->call('toggleSidebar')
        ->assertSet('sidebarOpen', true)
        ->assertSee(__('Upcoming'));
});

test('call next selects the lowest waiting token number regardless of creation order', function () {
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
        'token_number' => 6,
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

test('arrived reservation tokens are shown in the upcoming section', function () {
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
        'token_number' => 5,
        'status' => 'waiting',
        'origin' => 'reservation',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSee(__('Upcoming'))
        ->assertSee(__('Arrived'))
        ->assertSee($token->token_number)
        ->assertSee($patient->name);
});

test('walk-in tokens are shown in the upcoming section', function () {
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
        'token_number' => 3,
        'status' => 'waiting',
        'origin' => 'walk_in',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSee(__('Upcoming'))
        ->assertSee(__('Arrived'))
        ->assertSee($token->token_number)
        ->assertSee($patient->name);
});

test('reserved tokens are shown in the upcoming section with not arrived badge', function () {
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
        'token_number' => 4,
        'status' => 'reserved',
        'origin' => 'reservation',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSee(__('Upcoming'))
        ->assertSee(__('Not Arrived'))
        ->assertSee($token->token_number)
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
