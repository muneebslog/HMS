<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
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

test('overnight shift queues remain listed and show serving tokens after midnight', function () {
    $shift = Shift::factory()->open()->create([
        'opened_at' => now()->subDay()->setTime(18, 0),
    ]);
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();
    $patient = Patient::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'date' => $shift->opened_at->toDateString(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 12,
        'status' => 'serving',
        'displayed_at' => now()->subHour(),
    ]);

    $this->get(route('display.tokens.tv'))
        ->assertOk()
        ->assertSee($service->name)
        ->assertSee($doctor->name);

    $this->get(route('display.tokens.tv', ['queue' => $queue->id]))
        ->assertOk()
        ->assertSee((string) $token->token_number)
        ->assertSee(__('Now Serving'))
        ->assertDontSee(__('No token being served'));
});

test('selecting a queue shows waiting and serving tokens', function () {
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
        ->assertSee(__('Now Serving'))
        ->assertSee($doctor->name);
});

test('tv display uses the centered single-token layout configured for the service and doctor', function () {
    $patient = Patient::factory()->create(['name' => 'Hina Ahmed']);
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    ServicePrice::factory()->singleTokenDisplay()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
    ]);

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
        'token_number' => 27,
        'status' => 'serving',
        'displayed_at' => now(),
    ]);

    $this->get(route('display.tokens.tv', ['queue' => $queue->id]))
        ->assertOk()
        ->assertSee('27')
        ->assertSee($patient->name)
        ->assertSee('single-token-display', false)
        ->assertDontSee(__('Patients waiting'))
        ->assertDontSee(__('Enter PIN'));
});

test('the single-token layout shows next and previous token controls', function () {
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    ServicePrice::factory()->singleTokenDisplay()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
    ]);

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $current = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 1,
        'status' => 'serving',
    ]);

    $next = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    $this->get(route('display.tokens.tv', ['queue' => $queue->id]))
        ->assertOk()
        ->assertSee(__('Next Token'))
        ->assertSee(__('Previous Token'));

    $this->post(route('display.tokens.tv.next'), [
        'queue' => $queue->id,
    ])->assertRedirect(route('display.tokens.tv', ['queue' => $queue->id]));

    expect($current->fresh()->status)->toBe('served')
        ->and($next->fresh()->status)->toBe('serving');
});

test('the single-token layout hides manual controls for doctor medication services', function () {
    $service = Service::factory()->create([
        'needs_medication' => true,
    ]);
    $doctor = Doctor::factory()->create();

    ServicePrice::factory()->singleTokenDisplay()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
    ]);

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $current = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 1,
        'status' => 'serving',
    ]);

    $next = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    $this->get(route('display.tokens.tv', ['queue' => $queue->id]))
        ->assertOk()
        ->assertDontSee(__('Next Token'))
        ->assertDontSee(__('Previous Token'));

    $this->post(route('display.tokens.tv.next'), [
        'queue' => $queue->id,
    ])->assertForbidden();

    $this->post(route('display.tokens.tv.back'), [
        'queue' => $queue->id,
    ])->assertForbidden();

    expect($current->fresh()->status)->toBe('serving')
        ->and($next->fresh()->status)->toBe('waiting');
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

test('display controls can call the next token without a pin', function () {
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

    $response->assertRedirect(route('display.tokens.tv', ['queue' => $queue->id]));
});

test('display controls can call the next token', function () {
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

    $this->post(route('display.tokens.tv.next'), [
        'queue' => $queue->id,
    ]);

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('display controls can call the previous token', function () {
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

    $this->post(route('display.tokens.tv.back'), [
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

test('tv display shows waiting tokens that have arrived', function () {
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
        'status' => 'waiting',
        'origin' => 'reservation',
        'arrived_at' => now(),
    ]);

    $response = $this->get(route('display.tokens.tv', ['queue' => $queue->id]));

    $response->assertOk()
        ->assertSee(__('Patients waiting'))
        ->assertSee('5');
});

test('tv display calls the next token number in order', function () {
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

    $this->post(route('display.tokens.tv.next'), [
        'queue' => $queue->id,
    ]);

    expect($currentToken->fresh()->status)->toBe('served')
        ->and($nextToken->fresh()->status)->toBe('serving');
});

test('tv display call next still advances tokens for legacy controls', function () {
    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();
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
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'serving',
    ]);

    $nextToken = QueueToken::factory()->reserved()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
    ]);

    $this->post(route('display.tokens.tv.next'), [
        'queue' => $queue->id,
    ]);

    expect($nextToken->fresh()->status)->toBe('reserved')
        ->and($nextToken->fresh()->displayed_at)->not->toBeNull();
});
