<?php

use App\Models\Invoice;
use App\Models\MedicationOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected from the mr lookup page', function () {
    $this->get(route('reception.mr-lookup'))
        ->assertRedirect(route('login'));
});

test('receptionists can open the mr lookup page component', function () {
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->assertSee(__('MR Lookup'))
        ->assertSee(__('Search for a patient'));
});

test('doctors can open the mr lookup page component', function () {
    $user = User::factory()->doctor()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->assertSee(__('MR Lookup'));
});

test('users without an assigned clinical role cannot access mr lookup', function () {
    $user = User::factory()->user()->create();

    $this->actingAs($user)
        ->get(route('reception.mr-lookup'))
        ->assertRedirect(route('pending-role'));
});

test('mr lookup finds patients by mrn name and phone', function () {
    $user = User::factory()->receptionist()->create();
    $matching = Patient::factory()->withPhone('03009998888')->create(['name' => 'Ayesha Khan']);
    $other = Patient::factory()->create(['name' => 'Other Patient']);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->set('search', $matching->mrn)
        ->assertSee($matching->name)
        ->assertSee($matching->mrn)
        ->assertDontSee($other->name)
        ->set('search', 'Ayesha')
        ->assertSee($matching->name)
        ->set('search', '03009998888')
        ->assertSee($matching->name);
});

test('mr lookup shows patient history after selection', function () {
    $user = User::factory()->management()->create();
    $patient = Patient::factory()->create(['name' => 'Sana Malik']);
    $invoice = Invoice::factory()->create(['patient_id' => $patient->id]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->set('search', $patient->mrn)
        ->call('selectPatient', $patient->id)
        ->assertSet('selectedPatientId', $patient->id)
        ->assertSee($patient->mrn)
        ->assertSee($invoice->invoice_number)
        ->assertSee(__('Medication orders'))
        ->assertSee($order->status->label());
});

test('mr lookup requires at least two characters before searching', function () {
    $user = User::factory()->admin()->create();
    $patient = Patient::factory()->create(['name' => 'Hidden Patient']);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->set('search', 'H')
        ->assertSee(__('Search for a patient'))
        ->assertDontSee($patient->name);
});
