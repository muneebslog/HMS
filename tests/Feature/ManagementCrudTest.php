<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\LabTest;
use App\Models\ProcedureType;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('management.crud'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the management page', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->get(route('management.crud'));

    $response->assertOk();
});

test('management page displays current server time', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->get(route('management.crud'));

    $response->assertOk();
    $response->assertSee(now()->format('Y-m-d'));
});

test('authenticated users can create a doctor', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('create')
        ->set('doctorName', 'Dr. Smith')
        ->set('doctorSpecialization', 'Cardiology')
        ->set('doctorPayoutDaily', true)
        ->set('doctorGetFullSlips', true)
        ->set('doctorFullSlipsCount', '5')
        ->set('doctorDutyStartTime', '18:00')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'name' => 'Dr. Smith',
        'specialization' => 'Cardiology',
        'payout_daily' => true,
        'get_full_slips' => true,
        'full_slips_count' => 5,
        'duty_start_time' => '18:00:00',
    ]);
});

test('authenticated users can update a doctor', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->set('doctorName', 'Dr. Updated')
        ->set('doctorSpecialization', 'Neurology')
        ->set('doctorPayoutDaily', true)
        ->set('doctorGetFullSlips', true)
        ->set('doctorFullSlipsCount', '3')
        ->set('doctorDutyStartTime', '19:30')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'id' => $doctor->id,
        'name' => 'Dr. Updated',
        'specialization' => 'Neurology',
        'payout_daily' => true,
        'get_full_slips' => true,
        'full_slips_count' => 3,
        'duty_start_time' => '19:30:00',
    ]);
});

test('authenticated users can delete a doctor', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('delete', $doctor->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('doctors', [
        'id' => $doctor->id,
    ]);
});

test('authenticated users can create a service', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'General Checkup')
        ->set('serviceIsStandalone', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'General Checkup',
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift->value,
    ]);
});

test('service creation defaults token reset to shift', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->assertSet('serviceTokenResetType', TokenResetType::Shift->value)
        ->set('serviceName', 'Default Shift Service')
        ->set('serviceIsStandalone', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'Default Shift Service',
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift->value,
    ]);
});

test('authenticated users can create a service price', function () {
    $user = User::factory()->admin()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'servicePrices')
        ->call('create')
        ->set('priceServiceId', $service->id)
        ->set('priceDoctorId', $doctor->id)
        ->set('priceAmount', '150.00')
        ->set('priceDoctorShare', '25.00')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('service_prices', [
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 150.00,
        'doctor_share' => 25.00,
    ]);
});

test('service price doctor share can be null', function () {
    $user = User::factory()->admin()->create();
    $service = Service::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'servicePrices')
        ->call('create')
        ->set('priceServiceId', $service->id)
        ->set('priceDoctorId', '')
        ->set('priceAmount', '99.99')
        ->set('priceDoctorShare', '')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('service_prices', [
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 99.99,
        'doctor_share' => null,
    ]);
});

test('authenticated users can create a lab test', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'Complete Blood Count')
        ->set('labTestCode', 'CBC-001')
        ->set('labTestSample', 'E.D.T.A 2cc')
        ->set('labTestPrice', '1200.00')
        ->set('labTestTimeRequired', '1 hour')
        ->set('labTestIsInHouse', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'sample' => 'E.D.T.A 2cc',
        'test_price' => 1200.00,
        'time_required' => '1 hour',
        'is_in_house' => true,
    ]);
});

test('authenticated users can create two lab tests with the same code', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'CBC In House')
        ->set('labTestCode', '1300')
        ->set('labTestSample', 'E.D.T.A 2cc')
        ->set('labTestPrice', '700.00')
        ->set('labTestTimeRequired', 'Same day')
        ->set('labTestIsInHouse', true)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'CBC Send Out')
        ->set('labTestCode', '1300')
        ->set('labTestSample', 'E.D.T.A 2cc')
        ->set('labTestPrice', '900.00')
        ->set('labTestTimeRequired', 'Next day')
        ->set('labTestIsInHouse', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(LabTest::where('test_code', '1300')->count())->toBe(2);
});

test('authenticated users can create a lab test with empty test code', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'Basic Metabolic Panel')
        ->set('labTestCode', '')
        ->set('labTestPrice', '800.00')
        ->set('labTestTimeRequired', '30 minutes')
        ->set('labTestIsInHouse', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'test_name' => 'Basic Metabolic Panel',
        'test_code' => null,
        'test_price' => 800.00,
        'time_required' => '30 minutes',
        'is_in_house' => true,
    ]);
});

test('authenticated users can update a lab test', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestName', 'Updated Blood Count')
        ->set('labTestCode', 'UBC-002')
        ->set('labTestSample', 'Clotted 2cc')
        ->set('labTestPrice', '1500.00')
        ->set('labTestTimeRequired', '2 hours')
        ->set('labTestIsInHouse', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'test_name' => 'Updated Blood Count',
        'test_code' => 'UBC-002',
        'sample' => 'Clotted 2cc',
        'test_price' => 1500.00,
        'time_required' => '2 hours',
        'is_in_house' => false,
    ]);
});

test('management lab tests table displays specimen values', function () {
    $user = User::factory()->admin()->create();
    LabTest::factory()->create([
        'test_name' => 'CBC',
        'sample' => 'E.D.T.A 2cc',
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->assertSee('E.D.T.A 2cc');
});

test('authenticated users can update a lab test with null test code', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create(['test_code' => null]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestName', 'Updated Blood Count')
        ->set('labTestCode', 'UBC-002')
        ->set('labTestPrice', '1500.00')
        ->set('labTestTimeRequired', '2 hours')
        ->set('labTestIsInHouse', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'test_name' => 'Updated Blood Count',
        'test_code' => 'UBC-002',
        'test_price' => 1500.00,
        'time_required' => '2 hours',
        'is_in_house' => false,
    ]);
});

test('authenticated users can delete a lab test', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('delete', $labTest->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('lab_tests', [
        'id' => $labTest->id,
    ]);
});
test('authenticated users can deactivate and reactivate a doctor', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->set('doctorIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'id' => $doctor->id,
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->set('doctorIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'id' => $doctor->id,
        'is_active' => true,
    ]);
});

test('authenticated users can deactivate and reactivate a service', function () {
    $user = User::factory()->admin()->create();
    $service = Service::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('edit', $service->id)
        ->set('serviceIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'id' => $service->id,
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('edit', $service->id)
        ->set('serviceIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'id' => $service->id,
        'is_active' => true,
    ]);
});

test('authenticated users can deactivate and reactivate a lab test', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'is_active' => true,
    ]);
});

test('authenticated users can create a procedure type', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('create')
        ->set('procedureTypeName', 'Normal Delivery')
        ->set('procedureTypeIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('procedure_types', [
        'name' => 'Normal Delivery',
        'is_active' => true,
    ]);
});

test('authenticated users can update a procedure type', function () {
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('edit', $procedureType->id)
        ->set('procedureTypeName', 'Updated Delivery')
        ->set('procedureTypeIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('procedure_types', [
        'id' => $procedureType->id,
        'name' => 'Updated Delivery',
        'is_active' => false,
    ]);
});

test('authenticated users can delete a procedure type', function () {
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('delete', $procedureType->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('procedure_types', [
        'id' => $procedureType->id,
    ]);
});

test('procedure type names must be unique', function () {
    $user = User::factory()->admin()->create();
    ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('create')
        ->set('procedureTypeName', 'Normal Delivery')
        ->call('save')
        ->assertHasErrors(['procedureTypeName']);

    expect(ProcedureType::where('name', 'Normal Delivery')->count())->toBe(1);
});

test('authenticated users can create a room', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('create')
        ->set('roomNumber', 'Room 101')
        ->set('roomIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('rooms', [
        'number' => 'Room 101',
        'is_active' => true,
    ]);
});

test('authenticated users can update a room', function () {
    $user = User::factory()->admin()->create();
    $room = Room::factory()->create(['number' => 'Room 1']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('edit', $room->id)
        ->set('roomNumber', 'Room 2')
        ->set('roomIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('rooms', [
        'id' => $room->id,
        'number' => 'Room 2',
        'is_active' => false,
    ]);
});

test('authenticated users can delete a room', function () {
    $user = User::factory()->admin()->create();
    $room = Room::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('delete', $room->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
});

test('room numbers must be unique', function () {
    $user = User::factory()->admin()->create();
    Room::factory()->create(['number' => 'Room 101']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('create')
        ->set('roomNumber', 'Room 101')
        ->call('save')
        ->assertHasErrors(['roomNumber']);

    expect(Room::where('number', 'Room 101')->count())->toBe(1);
});
