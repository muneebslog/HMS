<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Services\TokenDisplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('display.pin', '1234');
});

test('start serving moves an arrived waiting token without clearing other serving tokens', function () {
    $queue = ServiceQueue::factory()->create([
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $serving = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 1,
        'status' => 'serving',
    ]);

    $waiting = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 2,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    $result = app(TokenDisplayService::class)->startServing($waiting);

    expect($result?->status)->toBe('serving')
        ->and($result?->displayed_at)->not->toBeNull()
        ->and($serving->fresh()->status)->toBe('serving')
        ->and($waiting->fresh()->status)->toBe('serving');
});

test('start serving ignores tokens that are not arrived waiting', function () {
    $queue = ServiceQueue::factory()->create([
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $reserved = QueueToken::factory()->reserved()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 5,
    ]);

    expect(app(TokenDisplayService::class)->startServing($reserved))->toBeNull()
        ->and($reserved->fresh()->status)->toBe('reserved');
});

test('mark served only updates serving tokens', function () {
    $queue = ServiceQueue::factory()->create([
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $serving = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 3,
        'status' => 'serving',
    ]);

    $waiting = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 4,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    $display = app(TokenDisplayService::class);

    expect($display->markServed($serving)?->status)->toBe('served')
        ->and($display->markServed($waiting))->toBeNull()
        ->and($waiting->fresh()->status)->toBe('waiting');
});

test('tv board lists waiting and serving tokens and supports click actions', function () {
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $waiting = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 7,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);

    $serving = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'token_number' => 3,
        'status' => 'serving',
    ]);

    $this->get(route('display.tokens.tv', ['queue' => $queue->id]))
        ->assertOk()
        ->assertSee(__('Patients waiting'))
        ->assertSee(__('Now Serving'))
        ->assertSee('7')
        ->assertSee('3')
        ->assertSee('اب باری ہے');

    $this->post(route('display.tokens.tv.start-serving'), [
        'queue' => $queue->id,
        'token' => $waiting->id,
    ])
        ->assertRedirect(route('display.tokens.tv', ['queue' => $queue->id]));

    expect($waiting->fresh()->status)->toBe('serving');

    $this->post(route('display.tokens.tv.mark-served'), [
        'queue' => $queue->id,
        'token' => $serving->id,
    ])
        ->assertRedirect(route('display.tokens.tv', ['queue' => $queue->id]));

    expect($serving->fresh()->status)->toBe('served');
});

test('tv board shows file check tokens in the file check panels', function () {
    $consultation = Service::factory()->create(['name' => 'OPD']);
    $fileCheck = Service::factory()->create(['name' => 'File Desk']);
    $doctor = Doctor::factory()->create();

    ServicePrice::factory()->create([
        'service_id' => $consultation->id,
        'doctor_id' => $doctor->id,
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

    $this->get(route('display.tokens.tv', ['queue' => $primaryQueue->id]))
        ->assertOk()
        ->assertSee(__('File check for patients'))
        ->assertSee('201')
        ->assertSee('202')
        ->assertDontSee('File Desk');
});

test('guests cannot start serving on the tv display without a pin', function () {
    $queue = ServiceQueue::factory()->create([
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

    $this->post(route('display.tokens.tv.start-serving'), [
        'queue' => $queue->id,
        'token' => $token->id,
    ])->assertForbidden();
});
