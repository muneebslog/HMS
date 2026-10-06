<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\TokenDisplayService;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);

    $this->doctor = Doctor::factory()->create();
    $service = Service::factory()->followsDoctorToken()->create([
        'needs_medication' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);

    $oldShift = Shift::factory()->closed()->create(['opened_at' => now()->subHours(9)]);
    $newShift = Shift::factory()->open()->create(['opened_at' => now()->subHour()]);

    $this->oldQueue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $this->doctor->id,
        'shift_id' => $oldShift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'closed',
        'opened_at' => now()->subHours(9),
    ]);
    $this->newQueue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $this->doctor->id,
        'shift_id' => $newShift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now()->subMinutes(30),
    ]);

    $this->oldTokens = collect([25, 26, 27, 28, 29, 30])->mapWithKeys(fn (int $number) => [
        $number => QueueToken::factory()->create([
            'service_queue_id' => $this->oldQueue->id,
            'token_number' => $number,
            'status' => $number === 25 ? 'serving' : 'waiting',
            'arrived_at' => now()->subHours(2),
            'displayed_at' => $number === 25 ? now()->subMinutes(5) : null,
        ]),
    ]);
    $this->newTokens = collect([1, 2, 3])->mapWithKeys(fn (int $number) => [
        $number => QueueToken::factory()->create([
            'service_queue_id' => $this->newQueue->id,
            'token_number' => $number,
            'status' => 'waiting',
            'arrived_at' => now()->subMinutes(10),
        ]),
    ]);

    Shift::query()
        ->whereKeyNot([$oldShift->id, $newShift->id])
        ->update(['opened_at' => now()->subDays(10)]);
});

test('next finishes the previous shift leftovers before starting the new shift at token 1', function () {
    $display = app(TokenDisplayService::class);

    $called = collect(range(1, 7))->map(fn () => $display->callNext($this->newQueue)?->token_number);

    expect($called->all())->toBe([26, 27, 28, 29, 30, 1, 2])
        ->and($this->oldTokens[30]->fresh()->status)->toBe('served')
        ->and($this->newTokens[2]->fresh()->status)->toBe('serving');
});

test('back from the new shift token 1 returns to the previous shift last token', function () {
    $display = app(TokenDisplayService::class);
    $display->callTokenNumber($this->newQueue, 1);

    expect($display->callPrevious($this->newQueue)?->id)->toBe($this->oldTokens[30]->id)
        ->and($this->newTokens[1]->fresh()->status)->toBe('waiting');
});

test('typing a number prefers the queue currently on screen then the newest queue', function () {
    $display = app(TokenDisplayService::class);

    expect($display->callTokenNumber($this->oldQueue, 28)?->id)->toBe($this->oldTokens[28]->id)
        ->and($display->callTokenNumber($this->oldQueue, 2)?->id)->toBe($this->newTokens[2]->id)
        ->and($this->oldTokens[28]->fresh()->status)->toBe('waiting');
});

test('the tv follows the doctor from the previous shift queue into the new shift queue', function () {
    $display = app(TokenDisplayService::class);

    $this->get(route('display.tokens.tv', ['queue' => $this->newQueue->id]))
        ->assertOk()
        ->assertViewHas('currentToken', fn (?QueueToken $token) => $token?->is($this->oldTokens[25]));

    foreach (range(1, 6) as $press) {
        $display->callNext($this->newQueue);
    }

    $this->get(route('display.tokens.tv', ['queue' => $this->oldQueue->id]))
        ->assertOk()
        ->assertViewHas('selectedQueue', fn (ServiceQueue $queue) => $queue->is($this->newQueue))
        ->assertViewHas('currentToken', fn (?QueueToken $token) => $token?->is($this->newTokens[1]));
});

test('token control page follows the doctor into the new shift queue', function () {
    app(TokenDisplayService::class)->callTokenNumber($this->newQueue, 1);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::display.token-control', ['selectedQueueId' => $this->oldQueue->id])
        ->assertSet('selectedQueue.id', $this->newQueue->id)
        ->call('callPrevious');

    expect($this->oldTokens[30]->fresh()->status)->toBe('serving');
});

test('the doctor floating menu walks from 25 through 30 into the new shift token 1', function () {
    $user = User::factory()->doctor()->create();
    $this->doctor->update(['user_id' => $user->id]);

    $component = Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->assertSet('tokenControlNumber', '25');

    foreach ([26, 27, 28, 29, 30, 1] as $expected) {
        $component->call('tokenControlNext')->assertSet('tokenControlNumber', (string) $expected);
    }

    $component->call('tokenControlBack')->assertSet('tokenControlNumber', '30');
});

test('save and next still works for a previous shift patient after the new queue opens', function () {
    $user = User::factory()->doctor()->create();

    Livewire::actingAs($user)
        ->test('pages::doctor.medication')
        ->call('selectToken', $this->oldTokens[25]->id)
        ->assertSee(__('Save & Next Patient'));
});
