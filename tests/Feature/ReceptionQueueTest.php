<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.queue'));

    $response->assertRedirect(route('login'));
});

test('authenticated users with an open shift can visit the queue page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.queue'));

    $response->assertOk();
});

test('open service queues for the current shift are listed', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.queue')
        ->assertSee($service->name)
        ->assertSee($doctor->name)
        ->assertSee($queue->reset_type->label());
});

test('closed service queues are not listed', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create();

    ServiceQueue::factory()->closed()->create([
        'service_id' => $service->id,
        'shift_id' => $shift->id,
        'reset_type' => TokenResetType::Shift,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.queue')
        ->assertDontSee($service->name);
});

test('queues from other shifts are not listed for shift reset services', function () {
    $user = User::factory()->create();
    $currentShift = Shift::factory()->for($user)->open()->create();
    $otherShift = Shift::factory()->open()->create();
    $service = Service::factory()->create();

    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'shift_id' => $otherShift->id,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.queue')
        ->assertDontSee($service->name);
});

test('tokens belonging to a queue can be viewed', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = Invoice::factory()->create([
        'patient_id' => $patient->id,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);
    $invoiceItem = InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'shift_id' => $shift->id,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
    ]);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => $invoiceItem->id,
        'token_number' => 5,
        'status' => 'waiting',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->assertSet('viewingQueueId', $queue->id)
        ->assertSet('showTokensModal', true)
        ->assertSee($token->token_number)
        ->assertSee($patient->name)
        ->assertSee(__('Waiting'));
});
