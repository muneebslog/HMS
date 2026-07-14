<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const LEGACY_TV_USER_AGENT = 'Mozilla/5.0 (Linux; Android 9; Foxbox) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.62 Safari/537.36';

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.queue.tv'));

    $response->assertRedirect(route('login'));
});

test('authenticated management users with an open shift can visit the tv queue page', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.queue.tv'));

    $response->assertOk();
});

test('legacy tv browsers are redirected from the main queue to the tv queue', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['User-Agent' => LEGACY_TV_USER_AGENT])
        ->get(route('reception.queue'));

    $response->assertRedirect(route('reception.queue.tv'));
});

test('modern browsers are not redirected from the main queue', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'])
        ->get(route('reception.queue'));

    $response->assertOk();
});

test('open service queues for the current shift are listed', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $response = $this->actingAs($user)->get(route('reception.queue.tv'));

    $response->assertOk()
        ->assertSee($service->name)
        ->assertSee($doctor->name);
});

test('closed service queues are not listed', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create();

    ServiceQueue::factory()->closed()->create([
        'service_id' => $service->id,
        'shift_id' => $shift->id,
        'reset_type' => TokenResetType::Shift,
    ]);

    $response = $this->actingAs($user)->get(route('reception.queue.tv'));

    $response->assertOk()
        ->assertDontSee($service->name);
});

test('selecting a queue shows its tokens', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();
    $patient = Patient::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 7,
        'status' => 'waiting',
    ]);

    $response = $this->actingAs($user)->get(route('reception.queue.tv', ['queue' => $queue->id]));

    $response->assertOk()
        ->assertSee($service->name)
        ->assertSee($token->token_number)
        ->assertSee($patient->name);
});
