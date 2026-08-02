<?php

use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.walkin'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the walk-in page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.walkin'));

    $response->assertOk();
});

test('a standalone service can be added without a doctor', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 75.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) use ($service) {
            return count($items) === 1
                && $items[0]['service_id'] === $service->id
                && $items[0]['doctor_id'] === null
                && $items[0]['price'] == 75.00;
        });
});

test('a non-standalone service requires a related doctor', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => false]);
    $doctor = Doctor::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 150.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Jane Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->set('selectedDoctorId', '')
        ->call('add')
        ->assertHasErrors(['selectedDoctorId']);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Jane Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->set('selectedDoctorId', $doctor->id)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) use ($service, $doctor) {
            return count($items) === 1
                && $items[0]['service_id'] === $service->id
                && $items[0]['doctor_id'] === $doctor->id
                && $items[0]['price'] == 150.00;
        });
});

test('the reset button clears the form and services', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('clear')
        ->assertSet('patientName', '')
        ->assertSet('selectedServiceId', null)
        ->assertCount('items', 0);
});

test('a service can be removed from the list', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('remove', 0)
        ->assertCount('items', 0);
});

test('a service price can be edited from the table', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 100.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('editPrice', 0)
        ->assertSet('editingItemPrice', '100')
        ->set('editingItemPrice', '250.50')
        ->call('updatePrice')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) {
            return count($items) === 1 && $items[0]['price'] == 250.50;
        })
        ->assertSet('totalPrice', 250.50);
});

test('price edits must be a non-negative number', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('editPrice', 0)
        ->set('editingItemPrice', '-10')
        ->call('updatePrice')
        ->assertHasErrors(['editingItemPrice']);
});

test('a walk-in invoice can be saved with items', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 75.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $patient = Patient::where('name', 'John Doe')->first();
    expect($patient)->not->toBeNull();

    $invoice = Invoice::where('patient_id', $patient->id)->first();
    expect($invoice)->not->toBeNull()
        ->total->toBe(75.00)
        ->status->toBe('paid')
        ->payment_mode->value->toBe('cash')
        ->created_by->toBe($user->id);

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first())
        ->service_id->toBe($service->id)
        ->service_name->toBe($service->name)
        ->doctor_id->toBeNull()
        ->doctor_name->toBeNull()
        ->price->toBe(75.00);
});

test('a walk-in invoice can be saved with online payment mode', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 75.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Online Patient')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->set('paymentMode', 'online')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::whereHas('patient', fn ($q) => $q->where('name', 'Online Patient'))->first();

    expect($invoice)->not->toBeNull()
        ->payment_mode->value->toBe('online');
});

test('a walk-in invoice can be saved with a doctor service', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => false]);
    $doctor = Doctor::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 150.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Jane Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->set('selectedDoctorId', $doctor->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $patient = Patient::where('name', 'Jane Doe')->first();
    $invoice = Invoice::where('patient_id', $patient->id)->first();

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first())
        ->service_id->toBe($service->id)
        ->doctor_id->toBe($doctor->id)
        ->doctor_name->toBe($doctor->name)
        ->price->toBe(150.00);
});

test('saving a walk-in invoice requires a patient name', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->set('patientName', '')
        ->call('saveInvoice')
        ->assertHasErrors(['patientName']);

    expect(Invoice::count())->toBe(0);
});

test('saving a walk-in invoice requires at least one item', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->call('saveInvoice')
        ->assertHasErrors(['items']);

    expect(Invoice::count())->toBe(0)
        ->and(Patient::count())->toBe(0);
});

test('saving a walk-in invoice clears the form', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 50.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')->set('hasNoPhone', true)
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertSet('patientName', '')
        ->assertCount('items', 0);
});
test('inactive services are not available in walk-in', function () {
    $user = User::factory()->create();
    $activeService = Service::factory()->create(['is_standalone' => true, 'is_active' => true]);
    $inactiveService = Service::factory()->create(['is_standalone' => true, 'is_active' => false]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->assertSet('services', function ($services) use ($activeService, $inactiveService) {
            return $services->contains('id', $activeService->id)
                && ! $services->contains('id', $inactiveService->id);
        });
});

test('inactive doctors are not available for non-standalone services', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => false]);
    $activeDoctor = Doctor::factory()->create(['is_active' => true]);
    $inactiveDoctor = Doctor::factory()->create(['is_active' => false]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $activeDoctor->id,
        'price' => 150.00,
    ]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $inactiveDoctor->id,
        'price' => 200.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('selectedServiceId', $service->id)
        ->assertSet('availableDoctors', function ($doctors) use ($activeDoctor, $inactiveDoctor) {
            return $doctors->contains('id', $activeDoctor->id)
                && ! $doctors->contains('id', $inactiveDoctor->id);
        });
});
