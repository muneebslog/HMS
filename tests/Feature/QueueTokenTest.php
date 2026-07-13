<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createWalkInInvoice(User $user, Shift $shift, Service $service, ?Doctor $doctor = null): Invoice
{
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor?->id,
        'price' => 100.00,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Test Patient')
        ->set('selectedServiceId', $service->id);

    if ($doctor !== null) {
        $component->set('selectedDoctorId', $doctor->id);
    }

    $component->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    return Invoice::orderByDesc('id')->first();
}

test('saving a walk-in invoice creates a queue token for each item', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);

    $invoice = createWalkInInvoice($user, $shift, $service);

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first()->queueToken)->not->toBeNull()
        ->token_number->toBe(1);
});

test('tokens increment sequentially within the same queue', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $doctor = Doctor::factory()->create();

    $firstInvoice = createWalkInInvoice($user, $shift, $service, $doctor);
    $secondInvoice = createWalkInInvoice($user, $shift, $service, $doctor);

    expect($firstInvoice->items->first()->queueToken->token_number)->toBe(1)
        ->and($secondInvoice->items->first()->queueToken->token_number)->toBe(2)
        ->and(ServiceQueue::count())->toBe(1);
});

test('shift reset services start a new queue on the next shift', function () {
    $user = User::factory()->create();
    $firstShift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);

    $firstInvoice = createWalkInInvoice($user, $firstShift, $service);

    $firstShift->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $secondShift = Shift::factory()->for($user)->open()->create();

    $secondInvoice = createWalkInInvoice($user, $secondShift, $service);

    expect($firstInvoice->items->first()->queueToken->token_number)->toBe(1)
        ->and($secondInvoice->items->first()->queueToken->token_number)->toBe(1)
        ->and(ServiceQueue::count())->toBe(2);
});

test('daily reset services continue the same queue across shifts on the same day', function () {
    $user = User::factory()->create();
    $today = now()->startOfDay();
    $firstShift = Shift::factory()->for($user)->open()->create([
        'opened_at' => $today,
    ]);
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Daily,
    ]);

    $firstInvoice = createWalkInInvoice($user, $firstShift, $service);

    $firstShift->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $secondShift = Shift::factory()->for($user)->open()->create([
        'opened_at' => $today,
    ]);

    $secondInvoice = createWalkInInvoice($user, $secondShift, $service);

    expect($firstInvoice->items->first()->queueToken->token_number)->toBe(1)
        ->and($secondInvoice->items->first()->queueToken->token_number)->toBe(2)
        ->and(ServiceQueue::count())->toBe(1);
});

test('daily reset services start a new queue on the next day', function () {
    $user = User::factory()->create();
    $yesterdayShift = Shift::factory()->for($user)->open()->create([
        'opened_at' => now()->subDay(),
    ]);
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Daily,
    ]);

    $invoiceItem = InvoiceItem::factory()->create([
        'invoice_id' => Invoice::factory()->create([
            'shift_id' => $yesterdayShift->id,
            'created_by' => $user->id,
        ]),
        'service_id' => $service->id,
        'doctor_id' => null,
    ]);

    app(QueueService::class)->generateToken($invoiceItem);

    $yesterdayShift->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $todayShift = Shift::factory()->for($user)->open()->create([
        'opened_at' => now(),
    ]);

    $todayInvoice = createWalkInInvoice($user, $todayShift, $service);

    expect($invoiceItem->queueToken->token_number)->toBe(1)
        ->and($todayInvoice->items->first()->queueToken->token_number)->toBe(1)
        ->and(ServiceQueue::count())->toBe(2);
});

test('different doctors for the same service have separate queues', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $firstDoctor = Doctor::factory()->create();
    $secondDoctor = Doctor::factory()->create();

    $firstInvoice = createWalkInInvoice($user, $shift, $service, $firstDoctor);
    $secondInvoice = createWalkInInvoice($user, $shift, $service, $secondDoctor);

    expect($firstInvoice->items->first()->queueToken->token_number)->toBe(1)
        ->and($secondInvoice->items->first()->queueToken->token_number)->toBe(1)
        ->and(ServiceQueue::count())->toBe(2);
});

test('management can set token reset type when creating a service', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'Daily Service')
        ->set('serviceIsStandalone', true)
        ->set('serviceTokenResetType', TokenResetType::Daily->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'Daily Service',
        'token_reset_type' => TokenResetType::Daily->value,
    ]);
});

test('management can update token reset type for a service', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create([
        'token_reset_type' => TokenResetType::Shift,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('edit', $service->id)
        ->set('serviceTokenResetType', TokenResetType::Daily->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($service->fresh()->token_reset_type)->toBe(TokenResetType::Daily);
});

test('queue token is created with waiting status and walk-in origin', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);

    $invoice = createWalkInInvoice($user, $shift, $service);

    expect($invoice->items->first()->queueToken)
        ->status->toBe('waiting')
        ->origin->toBe('walk_in');
});

test('walk-in page shows expected token number before saving', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 100.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Test Patient')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertSee('1');
});

test('walk-in page shows next expected token when a queue already exists', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'shift_id' => $shift->id,
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'last_token_number' => 3,
    ]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 100.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Test Patient')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertSee('4');
});
