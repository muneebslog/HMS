<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const TV_USER_AGENT = 'Mozilla/5.0 (Linux; Android 5.1.1; SMART_TV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.90 Safari/537.36';

test('guests can view the tv display page', function () {
    $response = $this->get(route('display.tokens.tv'));

    $response->assertOk();
});

test('legacy tv browsers are redirected from the main display to the tv display', function () {
    $response = $this->withHeaders([
        'User-Agent' => TV_USER_AGENT,
    ])->get(route('display.tokens'));

    $response->assertRedirect(route('display.tokens.tv'));
});

test('modern browsers are not redirected from the main display', function () {
    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ])->get(route('display.tokens'));

    $response->assertOk();
});

test('open queues for today are listed on the tv display page', function () {
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->get(route('display.tokens.tv'));

    $response->assertOk()
        ->assertSee($service->name)
        ->assertSee($doctor->name);
});

test('closed queues are not listed on the tv display page', function () {
    $service = Service::factory()->create();

    ServiceQueue::factory()->closed()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
    ]);

    $response = $this->get(route('display.tokens.tv'));

    $response->assertOk()
        ->assertDontSee($service->name);
});

test('queues from other dates are not listed on the tv display page', function () {
    $service = Service::factory()->create();

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today()->subDay(),
        'reset_type' => TokenResetType::Daily,
        'status' => 'open',
    ]);

    $response = $this->get(route('display.tokens.tv'));

    $response->assertOk()
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

    $response = $this->get(route('display.tokens.tv', ['queue' => $queue->id]));

    $response->assertOk()
        ->assertSee($token->token_number)
        ->assertSee($patient->name)
        ->assertSee($doctor->name);
});

test('upcoming waiting tokens are shown for the selected queue', function () {
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

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('display.tokens.tv', ['queue' => $queue->id, 'sidebar' => '1']));

    $response->assertOk()
        ->assertSee($firstToken->token_number)
        ->assertSee($firstPatient->name)
        ->assertSee($secondToken->token_number)
        ->assertSee($secondPatient->name);
});

test('guests can select a queue on the tv display', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->post(route('display.tokens.tv.select'), [
        'queue' => $queue->id,
    ]);

    $response->assertRedirect(route('display.tokens.tv', [
        'queue' => $queue->id,
        'sidebar' => '0',
    ]));
});

test('authenticated users can select a queue on the tv display', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->actingAs($user)
        ->post(route('display.tokens.tv.select'), [
            'queue' => $queue->id,
        ]);

    $response->assertRedirect(route('display.tokens.tv', [
        'queue' => $queue->id,
        'sidebar' => '1',
    ]));
});

test('guests cannot call the next token on the tv display', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->post(route('display.tokens.tv.next'), [
        'queue' => $queue->id,
    ]);

    $response->assertForbidden();
});

test('authenticated users can call the next token on the tv display', function () {
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

    $this->actingAs($user)
        ->post(route('display.tokens.tv.next'), [
            'queue' => $queue->id,
        ]);

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('authenticated users can skip the current token on the tv display', function () {
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

    $this->actingAs($user)
        ->post(route('display.tokens.tv.skip'), [
            'queue' => $queue->id,
        ]);

    expect($currentToken->fresh()->status)->toBe('skipped')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('guests cannot skip the current token on the tv display', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->post(route('display.tokens.tv.skip'), [
        'queue' => $queue->id,
    ]);

    $response->assertForbidden();
});

test('guests cannot recall a token on the tv display', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->post(route('display.tokens.tv.recall'), [
        'queue' => $queue->id,
    ]);

    $response->assertForbidden();
});

test('authenticated users can toggle the upcoming tokens sidebar on the tv display', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->actingAs($user)
        ->get(route('display.tokens.tv.toggle-sidebar', [
            'queue' => $queue->id,
            'sidebar' => '1',
        ]));

    $response->assertRedirect(route('display.tokens.tv', [
        'queue' => $queue->id,
        'sidebar' => '0',
    ]));
});

test('the tv display page includes an auto refresh meta tag', function () {
    $response = $this->get(route('display.tokens.tv'));

    $response->assertOk()
        ->assertSee('http-equiv="refresh"', false);
});
