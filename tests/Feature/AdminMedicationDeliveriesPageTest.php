<?php

use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Enums\MedicineDose;
use App\Models\HealthAide;
use App\Models\MedicationOrder;
use App\Models\Patient;
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

test('medication deliveries page is restricted to admins', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();

    $this->actingAs($admin)
        ->get(route('admin.medication-deliveries'))
        ->assertSuccessful()
        ->assertSee(__('Medication Deliveries'));

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
        ->assertSee('Amina Delivery')
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
        ->assertDontSee('Hidden Patient');
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
