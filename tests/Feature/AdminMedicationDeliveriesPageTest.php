<?php

use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicationOrderStatus;
use App\Enums\MedicineDose;
use App\Models\DripCharge;
use App\Models\HealthAide;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MedicationOrder;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-08-18 15:30:00');
});

test('guests are redirected to the login page', function () {
    $this->get(route('admin.medication-deliveries'))
        ->assertRedirect(route('login'));
});

test('medication deliveries page is open to admins and incharge nurses only', function () {
    $admin = User::factory()->admin()->create();
    $nurse = User::factory()->inchargeNurse()->create();
    $receptionist = User::factory()->receptionist()->create();

    $this->actingAs($admin)
        ->get(route('admin.medication-deliveries'))
        ->assertSuccessful()
        ->assertSee(__('Medication Deliveries'));

    $this->actingAs($nurse)
        ->get(route('admin.medication-deliveries'))
        ->assertSuccessful()
        ->assertSee(__('Summary'));

    $this->actingAs($receptionist)
        ->get(route('admin.medication-deliveries'))
        ->assertForbidden();
});

test('admin can see delivered medicines injections and started drips with date and time', function () {
    $admin = User::factory()->admin()->create();
    $aide = HealthAide::factory()->create(['name' => 'Aide One']);
    $patient = Patient::factory()->create(['name' => 'Amina Delivery']);
    $order = MedicationOrder::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => null,
    ]);

    $order->medicines()->create([
        'medicine_id' => null,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Paracetamol',
        'delivered_at' => '2026-08-18 10:15:00',
        'delivered_by_health_aide_id' => $aide->id,
    ]);
    $order->injections()->create([
        'injection_id' => null,
        'administration_type' => InjectionAdministrationType::Im,
        'name' => 'Diclofenac',
        'delivered_at' => '2026-08-18 11:20:00',
        'delivered_by_health_aide_id' => $aide->id,
    ]);
    $drip = $order->drips()->create([
        'drip_base_id' => null,
        'name' => 'Normal Saline',
        'status' => DripLineStatus::Done,
        'started_at' => '2026-08-18 09:00:00',
        'started_by_health_aide_id' => $aide->id,
        'done_at' => '2026-08-18 09:45:00',
        'done_by_health_aide_id' => $aide->id,
    ]);
    $drip->additives()->create([
        'injection_id' => null,
        'name' => 'Vitamin C',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.medication-deliveries')
        ->assertSee('Paracetamol')
        ->assertSee('Diclofenac')
        ->assertSee('Normal Saline')
        ->assertSee('AMINA DELIVERY')
        ->assertSee('2026-08-18 10:15')
        ->assertSee('2026-08-18 11:20')
        ->assertSee('2026-08-18 09:00')
        ->assertSee('2026-08-18 09:45')
        ->assertSee('Aide One')
        ->assertSee('IM')
        ->assertSee('Vitamin C');
});

test('undelivered medicines injections and pending drips are hidden', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create(['name' => 'Hidden Patient']);
    $order = MedicationOrder::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => null,
    ]);

    $order->medicines()->create([
        'medicine_id' => null,
        'dose' => MedicineDose::OneZeroZero,
        'name' => 'Hidden Medicine',
        'delivered_at' => null,
    ]);
    $order->injections()->create([
        'injection_id' => null,
        'administration_type' => InjectionAdministrationType::Iv,
        'name' => 'Hidden Injection',
        'delivered_at' => null,
    ]);
    $order->drips()->create([
        'drip_base_id' => null,
        'name' => 'Hidden Drip',
        'status' => DripLineStatus::Pending,
        'started_at' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.medication-deliveries')
        ->assertDontSee('Hidden Medicine')
        ->assertDontSee('Hidden Injection')
        ->assertDontSee('Hidden Drip')
        ->assertDontSee('HIDDEN PATIENT');
});

test('admin can filter deliveries by type', function () {
    $admin = User::factory()->admin()->create();
    $aide = HealthAide::factory()->create();
    $patient = Patient::factory()->create(['name' => 'Filter Patient']);
    $order = MedicationOrder::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => null,
    ]);

    $order->medicines()->create([
        'medicine_id' => null,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Only Medicine',
        'delivered_at' => now(),
        'delivered_by_health_aide_id' => $aide->id,
    ]);
    $order->injections()->create([
        'injection_id' => null,
        'administration_type' => InjectionAdministrationType::Im,
        'name' => 'Only Injection',
        'delivered_at' => now(),
        'delivered_by_health_aide_id' => $aide->id,
    ]);
    $order->drips()->create([
        'drip_base_id' => null,
        'name' => 'Only Drip',
        'status' => DripLineStatus::Started,
        'started_at' => now(),
        'started_by_health_aide_id' => $aide->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.medication-deliveries')
        ->set('typeFilter', 'medicine')
        ->assertSee('Only Medicine')
        ->assertDontSee('Only Injection')
        ->assertDontSee('Only Drip');
});

test('admin can filter deliveries by date range', function () {
    $admin = User::factory()->admin()->create();
    $aide = HealthAide::factory()->create();
    $recentPatient = Patient::factory()->create(['name' => 'Recent Patient']);
    $oldPatient = Patient::factory()->create(['name' => 'Old Patient']);

    $recentOrder = MedicationOrder::factory()->create([
        'patient_id' => $recentPatient->id,
        'doctor_id' => null,
    ]);
    $recentOrder->medicines()->create([
        'medicine_id' => null,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Recent Medicine',
        'delivered_at' => '2026-08-18 10:00:00',
        'delivered_by_health_aide_id' => $aide->id,
    ]);

    $oldOrder = MedicationOrder::factory()->create([
        'patient_id' => $oldPatient->id,
        'doctor_id' => null,
    ]);
    $oldOrder->medicines()->create([
        'medicine_id' => null,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Old Medicine',
        'delivered_at' => '2026-08-01 10:00:00',
        'delivered_by_health_aide_id' => $aide->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.medication-deliveries')
        ->assertSee('Recent Medicine')
        ->assertDontSee('Old Medicine')
        ->set('dateFrom', '2026-08-01')
        ->set('dateTo', '2026-08-02')
        ->assertSee('Old Medicine')
        ->assertDontSee('Recent Medicine');
});

/**
 * Bill one reception slip for a service in the given shift.
 */
function billMedicationDeliverySlip(Service $service, Shift $shift, string $status = 'paid', ?string $createdAt = null): Invoice
{
    $invoice = Invoice::factory()->create([
        'shift_id' => $shift->id,
        'status' => $status,
        'created_at' => $createdAt ?? now(),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'service_name' => $service->name,
    ]);

    return $invoice;
}

test('summary counts slips made and returned per service with short stay first', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->create(['opened_at' => '2026-08-18 08:00:00', 'closed_at' => '2026-08-18 14:00:00']);
    $shortStay = Service::factory()->drip()->create(['name' => 'Short Stay']);
    $checkup = Service::factory()->create(['name' => 'General Checkup']);

    billMedicationDeliverySlip($shortStay, $shift);
    billMedicationDeliverySlip($shortStay, $shift, 'returned');
    billMedicationDeliverySlip($checkup, $shift);
    billMedicationDeliverySlip($checkup, $shift);
    billMedicationDeliverySlip($checkup, $shift);
    billMedicationDeliverySlip($checkup, $shift, 'cancelled');

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.medication-deliveries')
        ->assertSee('Short Stay')
        ->assertSee('1 returned');

    expect($component->instance()->summary['slips'])->toBe([
        ['service_id' => $shortStay->id, 'name' => 'Short Stay', 'is_drip' => true, 'made' => 2, 'returned' => 1],
        ['service_id' => $checkup->id, 'name' => 'General Checkup', 'is_drip' => false, 'made' => 3, 'returned' => 0],
    ]);
});

test('summary compares short stay slips with drips ordered started and done', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->create(['opened_at' => '2026-08-18 08:00:00', 'closed_at' => '2026-08-18 16:00:00']);
    $shortStay = Service::factory()->drip()->create(['name' => 'Short Stay']);

    $order = MedicationOrder::factory()->create(['doctor_id' => null]);
    $order->drips()->createMany([
        ['name' => 'Normal Saline', 'status' => DripLineStatus::Done, 'started_at' => now(), 'done_at' => now()],
        ['name' => 'Ringer Lactate', 'status' => DripLineStatus::Started, 'started_at' => now()],
        ['name' => 'Dextrose', 'status' => DripLineStatus::Pending],
        ['name' => 'Cancelled Drip', 'status' => DripLineStatus::Cancelled],
    ]);

    $billedFromOrder = billMedicationDeliverySlip($shortStay, $shift);
    DripCharge::factory()->paid()->create([
        'service_id' => $shortStay->id,
        'medication_order_id' => $order->id,
        'invoice_id' => $billedFromOrder->id,
    ]);
    billMedicationDeliverySlip($shortStay, $shift);
    billMedicationDeliverySlip($shortStay, $shift);
    billMedicationDeliverySlip($shortStay, $shift, 'returned');

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.medication-deliveries')
        ->assertSee('2 short stay slips have no drip order entered in the system.');

    expect($component->instance()->summary['drips'])->toBe([
        'slips' => 4,
        'returned' => 1,
        'without_order' => 2,
        'orders' => 1,
        'ordered' => 3,
        'started' => 2,
        'done' => 1,
        'left' => 2,
    ]);
});

test('summary counts injections and medicines ordered given and left', function () {
    $admin = User::factory()->admin()->create();
    $order = MedicationOrder::factory()->create(['doctor_id' => null]);
    $draft = MedicationOrder::factory()->create(['doctor_id' => null, 'status' => MedicationOrderStatus::Draft]);

    $order->injections()->createMany([
        ['administration_type' => InjectionAdministrationType::Im, 'name' => 'Diclofenac', 'delivered_at' => now()],
        ['administration_type' => InjectionAdministrationType::Iv, 'name' => 'Ondansetron'],
    ]);
    $order->medicines()->createMany([
        ['dose' => MedicineDose::OneZeroOne, 'name' => 'Paracetamol', 'delivered_at' => now()],
        ['dose' => MedicineDose::OneZeroOne, 'name' => 'Omeprazole', 'delivered_at' => now()],
        ['dose' => MedicineDose::OneZeroOne, 'name' => 'Cetirizine'],
    ]);
    $draft->medicines()->create(['dose' => MedicineDose::OneZeroOne, 'name' => 'Draft Medicine']);

    $summary = Livewire::actingAs($admin)
        ->test('pages::admin.medication-deliveries')
        ->instance()
        ->summary;

    expect($summary['injections'])->toBe(['ordered' => 2, 'given' => 1, 'left' => 1])
        ->and($summary['medicines'])->toBe(['ordered' => 3, 'given' => 2, 'left' => 1]);
});

test('selecting a shift limits slips to that shift and deliveries to its open hours', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $aide = HealthAide::factory()->create();
    $shortStay = Service::factory()->drip()->create(['name' => 'Short Stay']);
    $morning = Shift::factory()->create(['opened_at' => '2026-08-18 08:00:00', 'closed_at' => '2026-08-18 14:00:00']);
    $evening = Shift::factory()->create(['opened_at' => '2026-08-18 14:00:01', 'closed_at' => '2026-08-18 20:00:00']);

    billMedicationDeliverySlip($shortStay, $morning, createdAt: '2026-08-18 09:00:00');
    billMedicationDeliverySlip($shortStay, $evening, createdAt: '2026-08-18 15:00:00');
    billMedicationDeliverySlip($shortStay, $evening, createdAt: '2026-08-18 15:10:00');

    $order = MedicationOrder::factory()->create(['doctor_id' => null]);
    $order->medicines()->createMany([
        ['dose' => MedicineDose::OneZeroOne, 'name' => 'Morning Medicine', 'delivered_at' => '2026-08-18 10:00:00', 'delivered_by_health_aide_id' => $aide->id],
        ['dose' => MedicineDose::OneZeroOne, 'name' => 'Evening Medicine', 'delivered_at' => '2026-08-18 15:20:00', 'delivered_by_health_aide_id' => $aide->id],
    ]);

    $component = Livewire::actingAs($nurse)
        ->test('pages::admin.medication-deliveries')
        ->assertSee('Morning Medicine')
        ->assertSee('Evening Medicine');

    expect($component->instance()->summary['drips']['slips'])->toBe(3);

    $component->set('shiftId', (string) $morning->id)
        ->assertSee('Morning Medicine')
        ->assertDontSee('Evening Medicine');

    expect($component->instance()->summary['drips']['slips'])->toBe(1);

    $component->set('dateFrom', '2026-08-01')
        ->set('dateTo', '2026-08-02')
        ->assertSet('shiftId', '');
});
