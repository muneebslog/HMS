<?php

use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

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

    $patient = Patient::where('name', 'JOHN DOE')->first();
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

    $invoice = Invoice::whereHas('patient', fn ($q) => $q->where('name', 'ONLINE PATIENT'))->first();

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

    $patient = Patient::where('name', 'JANE DOE')->first();
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

test('walk-in shows the recent patients button', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->assertSee(__('Recent Patients'));
});

test('recent patients modal lists only patients from the current shift', function () {
    $user = User::factory()->create();
    $currentShift = Shift::factory()->for($user)->open()->create();
    $otherShift = Shift::factory()->for($user)->closed()->create([
        'opened_at' => now()->subDay(),
        'closed_at' => now()->subHours(12),
    ]);

    $currentPatient = Patient::factory()->withPhone('03001112233')->create([
        'name' => 'Current Shift Patient',
        'age' => 32,
    ]);
    $otherPatient = Patient::factory()->withPhone('03004445566')->create([
        'name' => 'Other Shift Patient',
        'age' => 40,
    ]);

    Invoice::factory()->create([
        'patient_id' => $currentPatient->id,
        'shift_id' => $currentShift->id,
        'created_by' => $user->id,
    ]);
    Invoice::factory()->create([
        'patient_id' => $otherPatient->id,
        'shift_id' => $otherShift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->call('openRecentPatientsModal')
        ->assertSet('showRecentPatientsModal', true)
        ->assertSet('showDripPayModal', false)
        ->assertSet('showDripPriceModal', false)
        ->assertSet('showPriceModal', false)
        ->assertSee('CURRENT SHIFT PATIENT')
        ->assertSee('32')
        ->assertSee('03001112233')
        ->assertDontSee('OTHER SHIFT PATIENT');
});

test('recent patients modal search filters by name or phone', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    $ali = Patient::factory()->withPhone('03001234567')->create(['name' => 'Ali Khan', 'age' => 25]);
    $sara = Patient::factory()->withPhone('03007654321')->create(['name' => 'Sara Ahmed', 'age' => 28]);

    Invoice::factory()->create([
        'patient_id' => $ali->id,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);
    Invoice::factory()->create([
        'patient_id' => $sara->id,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->call('openRecentPatientsModal')
        ->set('recentPatientsSearch', 'Sara')
        ->assertSee('SARA AHMED')
        ->assertDontSee('ALI KHAN')
        ->set('recentPatientsSearch', '03001234567')
        ->assertSee('ALI KHAN')
        ->assertDontSee('SARA AHMED');
});

test('selecting a recent patient fills the walk-in intake form', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->withPhone('03009876543')->create([
        'name' => 'Selected Patient',
        'age' => 45,
    ]);

    Invoice::factory()->create([
        'patient_id' => $patient->id,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->call('openRecentPatientsModal')
        ->call('selectPatientFromRecentList', $patient->id)
        ->assertSet('showRecentPatientsModal', false)
        ->assertSet('selectedPatientId', $patient->id)
        ->assertSet('patientName', 'SELECTED PATIENT')
        ->assertSet('patientPhone', '03009876543')
        ->assertSet('hasNoPhone', false);
});

test('opening recent patients without an open shift does not open the modal', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->call('openRecentPatientsModal')
        ->assertSet('showRecentPatientsModal', false);
});
