<?php

use App\Enums\PrintJobStatus;
use App\Enums\TokenResetType;
use App\Models\AdminNotification;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\PrintJob;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.queue'));

    $response->assertRedirect(route('login'));
});

test('authenticated users with an open shift can visit the queue page', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.queue'));

    $response->assertOk();
});

test('open service queues for the current shift are listed', function () {
    $user = User::factory()->management()->create();
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
    $user = User::factory()->management()->create();
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
    $user = User::factory()->management()->create();
    $currentShift = Shift::factory()->for($user)->open()->create();
    $otherShift = Shift::factory()->closed()->create();
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
    $user = User::factory()->management()->create();
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
        'patient_id' => $patient->id,
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

test('admin can edit patient name and phone from the queue page', function () {
    [$admin, $queue, $token, $patient] = adminQueueWithReservedToken();

    Livewire::actingAs($admin)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openEditPatient', $token->id)
        ->set('editPatientName', 'Updated Patient')
        ->set('editPatientPhone', '03001234567')
        ->call('savePatientDetails')
        ->assertHasNoErrors()
        ->assertSet('showEditPatientModal', false);

    expect($patient->fresh())
        ->name->toBe('Updated Patient')
        ->phone->toBe('03001234567');

    $notification = AdminNotification::where('type', 'token_patient_updated')->first();

    expect($notification)->not->toBeNull()
        ->and($notification->metadata['before']['name'])->toBe('Reserved Patient')
        ->and($notification->metadata['after']['name'])->toBe('Updated Patient')
        ->and($notification->metadata['after']['phone'])->toBe('03001234567')
        ->and($notification->metadata['token_id'])->toBe($token->id);
});

test('management users cannot edit patient details from the queue page', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $queue = ServiceQueue::factory()->create([
        'shift_id' => $shift->id,
        'status' => 'open',
        'reset_type' => TokenResetType::Shift,
    ]);
    $patient = Patient::factory()->create(['name' => 'Locked Patient', 'phone' => '03001111111']);
    $token = QueueToken::factory()->reserved()->create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $patient->id,
        'token_number' => 3,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openEditPatient', $token->id)
        ->assertForbidden();

    expect($patient->fresh())
        ->name->toBe('Locked Patient')
        ->phone->toBe('03001111111');
});

test('admin can mark an arrived reservation as not arrived and cancel its invoice', function () {
    [$admin, $queue, $token, $patient, $shift] = adminQueueWithArrivedReservation();

    $invoice = $token->invoiceItem->invoice;
    $printJob = PrintJob::factory()->pending()->create([
        'invoice_id' => $invoice->id,
        'lab_invoice_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openConfirmAction', $token->id, 'not_arrived')
        ->call('confirmAction')
        ->assertHasNoErrors();

    $token->refresh();

    expect($token)
        ->status->toBe('reserved')
        ->arrived_at->toBeNull()
        ->invoice_item_id->toBeNull()
        ->displayed_at->toBeNull();

    expect($invoice->fresh()->status)->toBe('cancelled');
    expect($printJob->fresh()->status)->toBe(PrintJobStatus::Failed);
    expect($shift->fresh()->totalWalkInSales())->toBe(0.0);

    expect(AdminNotification::where('type', 'token_status_reversed')->count())->toBe(1);
    expect(AdminNotification::where('type', 'invoice_cancelled')->count())->toBe(1);
});

test('admin can mark a served token as not served', function () {
    [$admin, $queue, $token] = adminQueueWithArrivedReservation();

    $token->update(['status' => 'served']);

    Livewire::actingAs($admin)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openConfirmAction', $token->id, 'not_served')
        ->call('confirmAction')
        ->assertHasNoErrors();

    expect($token->fresh())
        ->status->toBe('waiting')
        ->invoice_item_id->not->toBeNull()
        ->arrived_at->not->toBeNull();

    $notification = AdminNotification::where('type', 'token_status_reversed')->first();

    expect($notification)->not->toBeNull()
        ->and($notification->metadata['before']['status'])->toBe('served')
        ->and($notification->metadata['after']['status'])->toBe('waiting');
});

test('admin can revert an unarrived reserved token and delete its unused patient', function () {
    [$admin, $queue, $token, $patient] = adminQueueWithReservedToken();

    Livewire::actingAs($admin)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openConfirmAction', $token->id, 'revert_reserved')
        ->call('confirmAction')
        ->assertHasNoErrors();

    expect(QueueToken::find($token->id))->toBeNull();
    expect(Patient::find($patient->id))->toBeNull();
    expect(AdminNotification::where('type', 'reservation_reverted')->count())->toBe(1);
});

test('reverting a reserved token keeps the patient when other records reference them', function () {
    [$admin, $queue, $token, $patient, $shift] = adminQueueWithReservedToken();

    Invoice::factory()->create([
        'patient_id' => $patient->id,
        'shift_id' => $shift->id,
        'created_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openConfirmAction', $token->id, 'revert_reserved')
        ->call('confirmAction')
        ->assertHasNoErrors();

    expect(QueueToken::find($token->id))->toBeNull();
    expect(Patient::find($patient->id))->not->toBeNull();
});

test('marking not arrived rejects tokens that are not arrived reservations', function () {
    [$admin, $queue, $token] = adminQueueWithReservedToken();

    Livewire::actingAs($admin)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openConfirmAction', $token->id, 'not_arrived')
        ->call('confirmAction');

    expect($token->fresh()->status)->toBe('reserved');
    expect(AdminNotification::where('type', 'token_status_reversed')->count())->toBe(0);
});

test('patient edit validation requires a name and an eleven digit phone when provided', function () {
    [$admin, $queue, $token] = adminQueueWithReservedToken();

    Livewire::actingAs($admin)
        ->test('pages::reception.queue')
        ->call('viewQueueTokens', $queue->id)
        ->call('openEditPatient', $token->id)
        ->set('editPatientName', '')
        ->set('editPatientPhone', '123')
        ->call('savePatientDetails')
        ->assertHasErrors(['editPatientName', 'editPatientPhone']);
});

/**
 * @return array{0: User, 1: ServiceQueue, 2: QueueToken, 3: Patient, 4: Shift}
 */
function adminQueueWithReservedToken(): array
{
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->for($admin)->open()->create();
    $queue = ServiceQueue::factory()->create([
        'shift_id' => $shift->id,
        'status' => 'open',
        'reset_type' => TokenResetType::Shift,
    ]);
    $patient = Patient::factory()->create([
        'name' => 'Reserved Patient',
        'phone' => '03009999999',
    ]);
    $token = QueueToken::factory()->reserved()->create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $patient->id,
        'token_number' => 7,
    ]);

    return [$admin, $queue, $token, $patient, $shift];
}

/**
 * @return array{0: User, 1: ServiceQueue, 2: QueueToken, 3: Patient, 4: Shift}
 */
function adminQueueWithArrivedReservation(): array
{
    [$admin, $queue, $token, $patient, $shift] = adminQueueWithReservedToken();

    $invoice = Invoice::factory()->paid()->create([
        'patient_id' => $patient->id,
        'shift_id' => $shift->id,
        'created_by' => $admin->id,
        'total' => 250.00,
    ]);
    $invoiceItem = InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $queue->service_id,
        'doctor_id' => $queue->doctor_id,
        'price' => 250.00,
    ]);

    $token->update([
        'invoice_item_id' => $invoiceItem->id,
        'status' => 'waiting',
        'arrived_at' => now(),
        'origin' => 'reservation',
    ]);

    return [$admin, $queue, $token->fresh(['invoiceItem.invoice']), $patient, $shift];
}
