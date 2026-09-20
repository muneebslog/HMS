<?php

use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\Patient;
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
    $response = $this->get(route('reception.lab-entry'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the lab entry page', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.lab-entry'));

    $response->assertOk();
});

test('a lab test can be added to the bill', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'sample' => 'E.D.T.A 2cc',
        'test_price' => 1200.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) use ($labTest) {
            return count($items) === 1
                && $items[0]['lab_test_id'] === $labTest->id
                && $items[0]['test_name'] === 'Complete Blood Count'
                && $items[0]['sample'] === 'E.D.T.A 2cc'
                && $items[0]['test_price'] == 1200.00;
        })
        ->assertSet('subtotal', 1200.00);
});

test('a discount can be applied to the whole bill', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create(['test_price' => 1000.00]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->set('discountPercentage', '10')
        ->call('applyDiscount')
        ->assertHasNoErrors()
        ->assertSet('discountAmount', 100.00)
        ->assertSet('total', 900.00);
});

test('a lab test can be removed from the list', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('remove', 0)
        ->assertCount('items', 0);
});

test('the clear button resets the form and selected tests', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('clear')
        ->assertSet('patientName', '')
        ->assertSet('patientPhone', '')
        ->assertSet('patientGender', '')
        ->assertSet('patientAge', null)
        ->assertCount('items', 0);
});

test('patient details are required to add a test', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->assertHasErrors(['patientName', 'patientPhone', 'patientGender', 'patientAge']);
});

test('lab test options expose name and code for searching', function () {
    $user = User::factory()->create();
    LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
    ]);
    LabTest::factory()->create([
        'test_name' => 'Liver Function Test',
        'test_code' => 'LFT-001',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->assertCount('labTestOptions', 2)
        ->assertSet('labTestOptions', function (array $options) {
            return collect($options)->contains(fn (array $option) => $option['label'] === 'Complete Blood Count (CBC-001)'
                && $option['keywords'] === 'Complete Blood Count CBC-001');
        })
        ->assertSee('Complete Blood Count');
});

test('time required is shown for added tests', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'time_required' => '1 day',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->assertSee('1 day');
});

test('gender other is not allowed for lab entry', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '03001234567')
        ->set('patientGender', 'other')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->assertHasErrors(['patientGender']);
});
test('inactive lab tests are not available in lab entry', function () {
    $user = User::factory()->create();
    $activeLabTest = LabTest::factory()->create(['is_active' => true]);
    $inactiveLabTest = LabTest::factory()->create(['is_active' => false]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->assertSet('labTests', function ($labTests) use ($activeLabTest, $inactiveLabTest) {
            return $labTests->contains('id', $activeLabTest->id)
                && ! $labTests->contains('id', $inactiveLabTest->id);
        });
});

test('lab entry shows the recent patients button', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->assertSee(__('Recent Patients'));
});

test('lab entry recent patients modal lists only patients from the current shift', function () {
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

    LabInvoice::factory()->create([
        'patient_id' => $currentPatient->id,
        'shift_id' => $currentShift->id,
        'created_by' => $user->id,
    ]);
    LabInvoice::factory()->create([
        'patient_id' => $otherPatient->id,
        'shift_id' => $otherShift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->call('openRecentPatientsModal')
        ->assertSet('showRecentPatientsModal', true)
        ->assertSee('CURRENT SHIFT PATIENT')
        ->assertSee('32')
        ->assertSee('03001112233')
        ->assertDontSee('OTHER SHIFT PATIENT');
});

test('lab entry recent patients search filters by name or phone', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    $ali = Patient::factory()->withPhone('03001234567')->create(['name' => 'Ali Khan', 'age' => 25]);
    $sara = Patient::factory()->withPhone('03007654321')->create(['name' => 'Sara Ahmed', 'age' => 28]);

    LabInvoice::factory()->create([
        'patient_id' => $ali->id,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);
    LabInvoice::factory()->create([
        'patient_id' => $sara->id,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->call('openRecentPatientsModal')
        ->set('recentPatientsSearch', 'Sara')
        ->assertSee('SARA AHMED')
        ->assertDontSee('ALI KHAN')
        ->set('recentPatientsSearch', '03001234567')
        ->assertSee('ALI KHAN')
        ->assertDontSee('SARA AHMED');
});

test('selecting a recent patient fills the lab entry intake form', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $patient = Patient::factory()->withPhone('03009876543')->create([
        'name' => 'Selected Patient',
        'age' => 45,
        'gender' => 'female',
    ]);

    LabInvoice::factory()->create([
        'patient_id' => $patient->id,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->call('openRecentPatientsModal')
        ->call('selectPatientFromRecentList', $patient->id)
        ->assertSet('showRecentPatientsModal', false)
        ->assertSet('selectedPatientId', $patient->id)
        ->assertSet('patientName', 'SELECTED PATIENT')
        ->assertSet('patientPhone', '03009876543')
        ->assertSet('patientAge', 45)
        ->assertSet('patientGender', 'female')
        ->assertSet('hasNoPhone', false);
});

test('opening lab entry recent patients without an open shift does not open the modal', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->call('openRecentPatientsModal')
        ->assertSet('showRecentPatientsModal', false);
});
