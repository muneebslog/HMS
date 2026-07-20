<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
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

test('receptionists can visit the token flow page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('reception.token-flow'));

    $response->assertOk();
});

test('token flow page does not require an open shift', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('reception.token-flow'));

    $response->assertOk();
});

test('token flow page shows tokens for the selected doctor', function () {
    $user = User::factory()->create();
    $service = tokenFlowConsultationService();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $patient = Patient::factory()->create();

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'waiting',
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
    $service = tokenFlowConsultationService();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
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

test('token flow page filters tokens by selected date', function () {
    $user = User::factory()->create();
    $service = tokenFlowConsultationService();
    $doctor = Doctor::factory()->create();

    $todayQueue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $yesterdayQueue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today()->subDay(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'closed',
    ]);

    $todayPatient = Patient::factory()->create();
    $yesterdayPatient = Patient::factory()->create();

    QueueToken::factory()->create([
        'service_queue_id' => $todayQueue->id,
        'patient_id' => $todayPatient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $yesterdayQueue->id,
        'patient_id' => $yesterdayPatient->id,
        'token_number' => 1,
        'status' => 'served',
        'arrived_at' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.token-flow')
        ->set('selectedDoctorId', $doctor->id)
        ->set('selectedDate', today()->toDateString())
        ->assertSee($todayPatient->name)
        ->assertDontSee($yesterdayPatient->name);
});

test('clicking arrived at header sorts tokens by arrival time', function () {
    $user = User::factory()->create();
    $service = tokenFlowConsultationService();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();

    $tokenTwo = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(10),
    ]);

    $tokenOne = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(30),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.token-flow')
        ->set('selectedDoctorId', $doctor->id)
        ->call('sortBy', 'arrived_at')
        ->assertSeeInOrder([
            (string) $tokenOne->token_number,
            (string) $tokenTwo->token_number,
        ]);
});

test('clicking token number header sorts tokens by token number', function () {
    $user = User::factory()->create();
    $service = tokenFlowConsultationService();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $firstPatient = Patient::factory()->create();
    $secondPatient = Patient::factory()->create();

    $tokenTwo = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $secondPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(10),
    ]);

    $tokenOne = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $firstPatient->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(30),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.token-flow')
        ->set('selectedDoctorId', $doctor->id)
        ->call('sortBy', 'arrived_at')
        ->call('sortBy', 'token_number')
        ->assertSeeInOrder([
            (string) $tokenOne->token_number,
            (string) $tokenTwo->token_number,
        ]);
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
