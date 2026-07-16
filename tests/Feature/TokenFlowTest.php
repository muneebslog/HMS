<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\TokenDisplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function tokenFlowConsultationService(): Service
{
    return Service::factory()->create([
        'name' => 'Consultation',
        'token_reset_type' => TokenResetType::Shift,
    ]);
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.token-flow'));

    $response->assertRedirect(route('login'));
});

test('receptionists can visit the token flow page with an open shift', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.token-flow'));

    $response->assertOk();
});

test('token flow page shows tokens for the selected doctor', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = tokenFlowConsultationService();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'date' => $shift->opened_at,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $patient = Patient::factory()->create();

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'origin' => 'walk_in',
        'arrived_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.token-flow')
        ->set('selectedDoctorId', $doctor->id)
        ->assertSee($patient->name)
        ->assertSee((string) $token->token_number)
        ->assertSee(__('Walk-in'))
        ->assertSee($token->created_at->format('Y-m-d H:i'));
});

test('token flow page shows reservation and arrival timestamps', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = tokenFlowConsultationService();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'date' => $shift->opened_at,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $patient = Patient::factory()->create();
    $reservedAt = now()->subHour();
    $arrivedAt = now()->subMinutes(30);

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'origin' => 'reservation',
        'arrived_at' => $arrivedAt,
        'created_at' => $reservedAt,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.token-flow')
        ->set('selectedDoctorId', $doctor->id)
        ->assertSee($patient->name)
        ->assertSee(__('Reservation'))
        ->assertSee($reservedAt->format('Y-m-d H:i'))
        ->assertSee($arrivedAt->format('Y-m-d H:i'));
});

test('calling the next token records the displayed at timestamp', function () {
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
        'token_number' => 1,
        'status' => 'waiting',
    ]);

    $displayService = app(TokenDisplayService::class);

    $calledToken = $displayService->callNext($queue);

    expect($calledToken)->not->toBeNull()
        ->id->toBe($token->id)
        ->status->toBe('serving');

    expect($token->fresh()->displayed_at)->not->toBeNull();
});

test('calling the previous token records the displayed at timestamp', function () {
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $previousToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 1,
        'status' => 'served',
    ]);

    $currentToken = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 2,
        'status' => 'serving',
    ]);

    $displayService = app(TokenDisplayService::class);

    $calledToken = $displayService->callPrevious($queue);

    expect($calledToken)->not->toBeNull()
        ->id->toBe($previousToken->id)
        ->status->toBe('serving');

    expect($previousToken->fresh()->displayed_at)->not->toBeNull();
});
