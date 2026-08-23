<?php

use App\Enums\MedicineDose;
use App\Models\Invoice;
use App\Models\MedicationOrder;
use App\Models\Patient;
use App\Models\QueueToken;
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
    $token = QueueToken::factory()->create([
        'patient_id' => $patient->id,
        'token_number' => 9,
    ]);
    $order = MedicationOrder::factory()->withoutDoctor()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $patient->id,
        'prescribed_by' => $user->id,
    ]);
    $order->medicines()->create([
        'medicine_id' => null,
        'dose' => MedicineDose::OneZeroOne,
        'name' => 'Paracetamol 500mg',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->set('search', $patient->mrn)
        ->call('selectPatient', $patient->id)
        ->assertSet('selectedPatientId', $patient->id)
        ->assertSee($patient->mrn)
        ->assertSee($invoice->invoice_number)
        ->assertSee('Token #9')
        ->assertSee(__('Medication slip'))
        ->assertSee('Paracetamol 500mg')
        ->assertSee(MedicineDose::OneZeroOne->label())
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

test('mr lookup allows clinical staff to edit patient name and age', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $patient = Patient::factory()->create([
        'name' => 'Original Name',
        'age' => 30,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->call('selectPatient', $patient->id)
        ->call('startEditingPatient')
        ->assertSet('editName', 'Original Name')
        ->assertSet('editAge', 30)
        ->set('editName', 'Updated Name')
        ->set('editAge', 35)
        ->call('savePatientDetails')
        ->assertSet('isEditingPatient', false)
        ->assertSee('Updated Name')
        ->assertSee('35');

    expect($patient->fresh())
        ->name->toBe('Updated Name')
        ->age->toBe(35);
})->with([
    'admin' => 'admin',
    'doctor' => 'doctor',
    'receptionist' => 'receptionist',
]);

test('mr lookup requires a patient name when saving edits', function () {
    $user = User::factory()->receptionist()->create();
    $patient = Patient::factory()->create(['name' => 'Original Name']);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->call('selectPatient', $patient->id)
        ->call('startEditingPatient')
        ->set('editName', '')
        ->call('savePatientDetails')
        ->assertHasErrors(['editName' => 'required']);

    expect($patient->fresh()->name)->toBe('Original Name');
});

test('admins see the recent patients button on mr lookup', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->assertSee(__('See patients'));
});

test('non admin users do not see the recent patients button on mr lookup', function () {
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->assertDontSee(__('See patients'));
});

test('admins can browse recent reception patients in a paginated modal', function () {
    $user = User::factory()->admin()->create();
    $olderPatient = Patient::factory()->create(['name' => 'Older Patient']);
    $recentPatient = Patient::factory()->create(['name' => 'Recent Patient']);

    QueueToken::factory()->create([
        'patient_id' => $olderPatient->id,
        'arrived_at' => now()->subDays(2),
    ]);

    Invoice::factory()->create([
        'patient_id' => $recentPatient->id,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->call('openRecentPatientsModal')
        ->assertSet('showRecentPatientsModal', true)
        ->assertSee(__('Recent reception patients'))
        ->assertSeeInOrder(['Recent Patient', 'Older Patient']);
});

test('admins can open a patient from the recent reception patients modal', function () {
    $user = User::factory()->admin()->create();
    $patient = Patient::factory()->create(['name' => 'Modal Patient']);
    Invoice::factory()->create(['patient_id' => $patient->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->call('openRecentPatientsModal')
        ->call('selectPatientFromRecentList', $patient->id)
        ->assertSet('showRecentPatientsModal', false)
        ->assertSet('selectedPatientId', $patient->id)
        ->assertSee('Modal Patient')
        ->assertSee(__('Patient details'));
});

test('non admins cannot open the recent reception patients modal', function () {
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.mr-lookup')
        ->call('openRecentPatientsModal')
        ->assertForbidden();
});
