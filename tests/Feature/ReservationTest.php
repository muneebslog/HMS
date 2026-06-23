<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function consultationService(): Service
{
    return Service::factory()->create([
        'name' => 'Consultation',
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift,
    ]);
}

function consultationPrice(Service $service, Doctor $doctor): ServicePrice
{
    return ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 250.00,
    ]);
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.reservation'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the reservation page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.reservation'));

    $response->assertOk();
});

test('a token can be reserved for a doctor', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5)
        ->set('patientName', 'Reserved Patient')
        ->call('reserve')
        ->assertHasNoErrors();

    $queue = ServiceQueue::first();
    expect($queue)->not->toBeNull()
        ->last_token_number->toBe(5);

    $token = QueueToken::first();
    expect($token)->not->toBeNull()
        ->token_number->toBe(5)
        ->status->toBe('reserved')
        ->patient->name->toBe('Reserved Patient')
        ->invoice_item_id->toBeNull();
});

test('reserving an already used token fails', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $queue = app(QueueService::class)->queueFor($service, $doctor->id, $shift);
    QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => Patient::factory()->create()->id,
        'token_number' => 3,
        'status' => 'reserved',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 3)
        ->set('patientName', 'Another Patient')
        ->call('reserve');

    expect(QueueToken::where('token_number', 3)->count())->toBe(1);
});

test('walk-in tokens skip reserved numbers', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 1)
        ->set('patientName', 'Reserved Patient')
        ->call('reserve');

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Walk-in Patient')
        ->set('selectedServiceId', $service->id)
        ->set('selectedDoctorId', $doctor->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::whereHas('patient', fn ($q) => $q->where('name', 'Walk-in Patient'))->first();
    expect($invoice->items->first()->queueToken->token_number)->toBe(2);
});

test('marking a reservation arrived creates an invoice and links the token', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $component = Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 4)
        ->set('patientName', 'Phone Patient')
        ->call('reserve');

    $token = QueueToken::first();

    $component->call('selectToken', 4)
        ->call('markArrived')
        ->assertDispatched('open-print-window');

    $invoice = Invoice::first();
    expect($invoice)->not->toBeNull()
        ->patient->name->toBe('Phone Patient')
        ->total->toBe(250.00)
        ->status->toBe('paid');

    $token->refresh();
    expect($token)
        ->status->toBe('waiting')
        ->invoice_item_id->not->toBeNull()
        ->invoiceItem->invoice_id->toBe($invoice->id);
});

test('management cannot create a second consultation service', function () {
    $user = User::factory()->create();
    Service::factory()->create(['name' => 'Consultation']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'consultation')
        ->set('serviceIsStandalone', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasErrors(['serviceName']);

    expect(Service::whereRaw('LOWER(name) = ?', ['consultation'])->count())->toBe(1);
});
