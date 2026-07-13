<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests cannot view the token control page', function () {
    $response = $this->get(route('display.tokens.control'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view the token control page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('display.tokens.control'));

    $response->assertOk();
});

test('open queues for today are listed on the token control page', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::actingAs($user)
        ->test('pages::display.token-control')
        ->assertSee($service->name)
        ->assertSee($doctor->name);
});

test('selecting a queue shows the current serving token', function () {
    $user = User::factory()->create();
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

    Livewire::actingAs($user)
        ->test('pages::display.token-control')
        ->call('selectQueue', $queue->id)
        ->assertSet('selectedQueueId', $queue->id)
        ->assertSee($token->token_number)
        ->assertSee($patient->name)
        ->assertSee($doctor->name);
});

test('authenticated users can call the next token from the control page', function () {
    $user = User::factory()->create();
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

    Livewire::actingAs($user)
        ->test('pages::display.token-control')
        ->call('selectQueue', $queue->id)
        ->call('callNext');

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('authenticated users can skip the current token from the control page', function () {
    $user = User::factory()->create();
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

    Livewire::actingAs($user)
        ->test('pages::display.token-control')
        ->call('selectQueue', $queue->id)
        ->call('skipCurrent');

    expect($currentToken->fresh()->status)->toBe('skipped')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('recalling a token does not change its status on the control page', function () {
    $user = User::factory()->create();
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

    Livewire::actingAs($user)
        ->test('pages::display.token-control')
        ->call('selectQueue', $queue->id)
        ->call('recallCurrent');

    expect($token->fresh()->status)->toBe('serving');
});
