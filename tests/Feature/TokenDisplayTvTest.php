<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const TV_USER_AGENT = 'Mozilla/5.0 (Linux; Android 5.1.1; SMART_TV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.90 Safari/537.36';

beforeEach(function () {
    config()->set('display.pin', '1234');
});

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

test('chrome 93 android tv browsers are redirected from the main display to the tv display', function () {
    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Linux; Android 9; Foxbox) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.62 Safari/537.36',
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
    ]));
});

test('guests cannot call the next token on the tv display without a pin', function () {
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

test('verified users can call the next token on the tv display', function () {
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

    $this->withSession(['display_pin_verified' => true])
        ->post(route('display.tokens.tv.next'), [
            'queue' => $queue->id,
        ]);

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('verified users can call the previous token on the tv display', function () {
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

    $this->withSession(['display_pin_verified' => true])
        ->post(route('display.tokens.tv.back'), [
            'queue' => $queue->id,
        ]);

    expect($currentToken->fresh()->status)->toBe('waiting')
        ->and($previousToken->fresh()->status)->toBe('serving');
});

test('the tv display page includes an auto refresh meta tag', function () {
    $response = $this->get(route('display.tokens.tv'));

    $response->assertOk()
        ->assertSee('http-equiv="refresh"', false);
});

test('tv display shows arrived badge for the current serving token', function () {
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

    QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 5,
        'status' => 'serving',
        'origin' => 'reservation',
    ]);

    $response = $this->get(route('display.tokens.tv', ['queue' => $queue->id]));

    $response->assertOk()
        ->assertSee(__('Arrived'))
        ->assertSee($patient->name);
});

test('tv display calls the lowest waiting token number on next', function () {
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

    $this->withSession(['display_pin_verified' => true])
        ->post(route('display.tokens.tv.next'), [
            'queue' => $queue->id,
        ]);

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('entering the correct pin unlocks the tv controls', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->post(route('display.tokens.tv.verify-pin'), [
        'queue' => $queue->id,
        'pin' => '1234',
    ]);

    $response->assertRedirect(route('display.tokens.tv', [
        'queue' => $queue->id,
    ]))
        ->assertSessionHas('display_pin_verified', true);
});

test('entering an incorrect pin does not unlock the tv controls', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->post(route('display.tokens.tv.verify-pin'), [
        'queue' => $queue->id,
        'pin' => '0000',
    ]);

    $response->assertSessionHasErrors('pin')
        ->assertSessionMissing('display_pin_verified');
});

test('locking the tv controls clears the verified pin session', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->withSession(['display_pin_verified' => true])
        ->get(route('display.tokens.tv.lock', ['queue' => $queue->id]));

    $response->assertRedirect(route('display.tokens.tv', ['queue' => $queue->id]))
        ->assertSessionMissing('display_pin_verified');
});
