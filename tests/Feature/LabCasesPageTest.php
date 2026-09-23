<?php

use App\Enums\OutgoingSampleStatus;
use App\Enums\UserRole;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\Patient;
use App\Models\RolePagePermission;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->labTechnician = User::factory()->labTechnician()->create();
});

/**
 * Create a paid lab case for a patient with the given items.
 *
 * @param  list<array<string, mixed>>  $items
 */
function labCase(Patient $patient, array $items, array $attributes = []): LabInvoice
{
    $invoice = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id, ...$attributes]);

    foreach ($items as $item) {
        LabInvoiceItem::factory()->create(['lab_invoice_id' => $invoice->id, ...$item]);
    }

    return $invoice->load('items');
}

test('an in-house test is done only once its results are completed in the HMS', function () {
    $item = LabInvoiceItem::factory()->inHouse()->make(['results_completed_at' => null]);
    expect($item->isDone())->toBeFalse();

    $item->results_completed_at = now();
    expect($item->isDone())->toBeTrue();
});

test('the old lab software ready flag does not mark a case done', function () {
    $case = labCase(Patient::factory()->create(['name' => 'Synced Patient']), [
        ['is_in_house' => true, 'outgoing_status' => null, 'lab_result_ready' => true, 'results_completed_at' => null],
    ]);

    expect($case->items->first()->isDone())->toBeFalse()
        ->and(LabInvoiceItem::pending()->count())->toBe(1);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.cases')
        ->assertSee('SYNCED PATIENT')
        ->assertSee('0/1')
        ->assertSee('Awaiting results')
        ->assertDontSee('Complete');
});

test('a send-out test is done once received or its report is uploaded', function () {
    $item = LabInvoiceItem::factory()->outgoing()->make();
    expect($item->isDone())->toBeFalse();

    $item->outgoing_status = OutgoingSampleStatus::Given;
    expect($item->isDone())->toBeFalse();

    $item->outgoing_status = OutgoingSampleStatus::Received;
    expect($item->isDone())->toBeTrue();

    $uploaded = LabInvoiceItem::factory()->outgoing()->make(['report_path' => 'lab-reports/1.pdf']);
    expect($uploaded->isDone())->toBeTrue();
});

test('the pending scope matches exactly the tests that are not done', function () {
    $patient = Patient::factory()->create();
    $case = labCase($patient, [
        ['is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => null],
        ['is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => now()],
        ['is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Asked],
        ['is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Received],
        ['is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Pending, 'report_path' => 'lab-reports/2.pdf'],
    ]);

    $pendingIds = LabInvoiceItem::pending()->pluck('id')->sort()->values()->all();
    $notDoneIds = $case->items->reject->isDone()->pluck('id')->sort()->values()->all();

    expect($pendingIds)->toBe($notDoneIds)->toHaveCount(2);
});

test('lab technicians can see cases with their progress and status', function () {
    $pending = labCase(Patient::factory()->create(['name' => 'Ramzan Ali']), [
        ['is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => now()],
        ['is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => null],
    ], ['invoice_number' => '928554']);
    $complete = labCase(Patient::factory()->create(['name' => 'Meerab Khan']), [
        ['is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => now()],
    ], ['invoice_number' => '928550']);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.cases')
        ->assertSee('RAMZAN ALI')
        ->assertSee('928554')
        ->assertSee('1/2')
        ->assertSee('MEERAB KHAN')
        ->assertSee('1/1')
        ->assertSee('Awaiting results')
        ->assertSee('Complete');
});

test('the pending filter hides finished cases', function () {
    labCase(Patient::factory()->create(['name' => 'Pending Patient']), [
        ['is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => null],
    ]);
    labCase(Patient::factory()->create(['name' => 'Finished Patient']), [
        ['is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => now()],
    ]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.cases')
        ->assertSee('FINISHED PATIENT')
        ->set('pendingOnly', true)
        ->assertSee('PENDING PATIENT')
        ->assertDontSee('FINISHED PATIENT');
});

test('cases can be searched by name, receipt number and phone', function () {
    labCase(Patient::factory()->withPhone('03001234567')->create(['name' => 'Shiza Noor']), [['is_in_house' => true, 'outgoing_status' => null]], ['invoice_number' => '928553']);
    labCase(Patient::factory()->create(['name' => 'Azeem Raza']), [['is_in_house' => true, 'outgoing_status' => null]], ['invoice_number' => '928551']);

    $component = Livewire::actingAs($this->labTechnician)->test('pages::lab.cases');

    $component->set('search', 'shiza')->assertSee('SHIZA NOOR')->assertDontSee('AZEEM RAZA');
    $component->set('search', '928551')->assertSee('AZEEM RAZA')->assertDontSee('SHIZA NOOR');
    $component->set('search', '0300123')->assertSee('SHIZA NOOR')->assertDontSee('AZEEM RAZA');
});

test('the date filter and returned invoices limit the list', function () {
    labCase(Patient::factory()->create(['name' => 'Recent Patient']), [['is_in_house' => true, 'outgoing_status' => null]]);
    labCase(Patient::factory()->create(['name' => 'Old Patient']), [['is_in_house' => true, 'outgoing_status' => null]], ['created_at' => now()->subDays(10)]);
    $returned = LabInvoice::factory()->returned()->create(['patient_id' => Patient::factory()->create(['name' => 'Returned Patient'])->id]);
    LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $returned->id]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.cases')
        ->assertSee('RECENT PATIENT')
        ->assertDontSee('OLD PATIENT')
        ->assertDontSee('RETURNED PATIENT')
        ->set('fromDate', now()->subDays(15)->toDateString())
        ->assertSee('OLD PATIENT');
});

test('patient details can be corrected from the cases list', function () {
    $patient = Patient::factory()->create(['name' => 'Wrong Name', 'age' => 5, 'gender' => 'male']);
    labCase($patient, [['is_in_house' => true, 'outgoing_status' => null]]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.cases')
        ->call('openEditPatientModal', $patient->id)
        ->assertSet('editPatientName', 'WRONG NAME')
        ->set('editPatientName', 'Right Name')
        ->set('editPatientAge', 41)
        ->set('editPatientGender', 'female')
        ->call('savePatientDetails')
        ->assertHasNoErrors();

    expect($patient->fresh())
        ->name->toBe('RIGHT NAME')
        ->age->toBe(41)
        ->gender->toBe('female');
});

test('the case page lists each test with its status', function () {
    $case = labCase(Patient::factory()->create(['name' => 'Case Patient']), [
        ['test_name' => 'CBC', 'is_in_house' => true, 'outgoing_status' => null, 'results_completed_at' => null],
        ['test_name' => 'TSH', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Given],
    ]);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $case))
        ->assertOk()
        ->assertSee('CASE PATIENT')
        ->assertSee('CBC')
        ->assertSee('Awaiting results')
        ->assertSee('TSH')
        ->assertSee('Send-out: Given')
        ->assertSee('0 of 2 tests done');
});

test('doctors cannot open the lab cases pages', function () {
    $doctor = User::factory()->doctor()->create();
    $case = labCase(Patient::factory()->create(), [['is_in_house' => true, 'outgoing_status' => null]]);

    $this->actingAs($doctor)->get(route('lab.cases'))->assertForbidden();
    $this->actingAs($doctor)->get(route('lab.cases.show', $case))->assertForbidden();
});

test('the access migration grants lab cases to roles that have lab tests', function () {
    RolePagePermission::query()->where('route_name', 'lab.cases')->delete();

    $migration = require database_path('migrations/2026_09_23_221232_grant_lab_cases_page_access.php');
    $migration->up();

    $rolesWithTests = RolePagePermission::query()->where('route_name', 'lab.tests')->pluck('role')->map(fn ($role) => $role instanceof UserRole ? $role->value : $role)->sort()->values()->all();
    $rolesWithCases = RolePagePermission::query()->where('route_name', 'lab.cases')->pluck('role')->map(fn ($role) => $role instanceof UserRole ? $role->value : $role)->sort()->values()->all();

    expect($rolesWithCases)->toBe($rolesWithTests)->toContain(UserRole::LabTechnician->value);
});
