<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Services\TokenDisplayService;
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

test('file check queues are not listed in the primary queue picker', function () {
    $service = Service::factory()->create(['name' => 'File Check Service']);
    $doctor = Doctor::factory()->create();

    ServicePrice::factory()->fileCheck()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
    ]);

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::test('pages::display.token-display')
        ->assertDontSee('File Check Service');
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

    QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 5,
        'status' => 'serving',
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 7,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->assertSet('selectedQueueId', $queue->id)
        ->assertSee('5')
        ->assertSee('7')
        ->assertSee(__('Patients waiting'))
        ->assertSee(__('Now Serving'));
});

test('guests cannot start serving without verifying the pin', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 1,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('startServing', $token->id)
        ->assertStatus(403);
});

test('verified users can move a waiting token to serving', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $waiting = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    $alreadyServing = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 1,
        'status' => 'serving',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('startServing', $waiting->id);

    expect($waiting->fresh()->status)->toBe('serving')
        ->and($alreadyServing->fresh()->status)->toBe('serving');
});

test('verified users can mark a serving token as served', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $serving = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 3,
        'status' => 'serving',
    ]);

    $this->withSession(['display_pin_verified' => true]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $queue->id)
        ->call('markServed', $serving->id);

    expect($serving->fresh()->status)->toBe('served');
});

test('waiting list only shows arrived waiting tokens', function () {
    $service = Service::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 10,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    QueueToken::factory()->reserved()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 11,
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 12,
        'status' => 'served',
        'arrived_at' => now(),
    ]);

    expect(app(TokenDisplayService::class)->waitingTokens($queue)->pluck('token_number')->all())
        ->toBe([10]);
});

test('file check tokens appear in the file check panels', function () {
    $consultation = Service::factory()->create(['name' => 'Consultation']);
    $fileCheck = Service::factory()->create(['name' => 'File Check']);
    $doctor = Doctor::factory()->create();

    ServicePrice::factory()->create([
        'service_id' => $consultation->id,
        'doctor_id' => $doctor->id,
        'is_file_check' => false,
    ]);

    ServicePrice::factory()->fileCheck()->create([
        'service_id' => $fileCheck->id,
        'doctor_id' => null,
        'token_starts_from' => 201,
    ]);

    $primaryQueue = ServiceQueue::factory()->create([
        'service_id' => $consultation->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $fileQueue = ServiceQueue::factory()->create([
        'service_id' => $fileCheck->id,
        'doctor_id' => null,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $primaryQueue->id,
        'token_number' => 3,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $fileQueue->id,
        'token_number' => 201,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    QueueToken::factory()->create([
        'service_queue_id' => $fileQueue->id,
        'token_number' => 202,
        'status' => 'serving',
    ]);

    Livewire::test('pages::display.token-display')
        ->call('selectQueue', $primaryQueue->id)
        ->assertSee('3')
        ->assertSee('201')
        ->assertSee('202')
        ->assertSee(__('File check for patients'));
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
