<?php

use App\Models\Doctor;
use App\Models\LabTest;
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
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('management.crud'));

    $response->assertOk();
});

test('authenticated users can create a doctor', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('create')
        ->set('doctorName', 'Dr. Smith')
        ->set('doctorSpecialization', 'Cardiology')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'name' => 'Dr. Smith',
        'specialization' => 'Cardiology',
    ]);
});

test('authenticated users can update a doctor', function () {
    $user = User::factory()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->set('doctorName', 'Dr. Updated')
        ->set('doctorSpecialization', 'Neurology')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'id' => $doctor->id,
        'name' => 'Dr. Updated',
        'specialization' => 'Neurology',
    ]);
});

test('authenticated users can delete a doctor', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'General Checkup')
        ->set('serviceIsStandalone', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'General Checkup',
        'is_standalone' => true,
    ]);
});

test('authenticated users can create a service price', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'Complete Blood Count')
        ->set('labTestCode', 'CBC-001')
        ->set('labTestPrice', '1200.00')
        ->set('labTestTimeRequired', '1 hour')
        ->set('labTestIsInHouse', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'test_price' => 1200.00,
        'time_required' => '1 hour',
        'is_in_house' => true,
    ]);
});

test('authenticated users can update a lab test', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create();

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
    $user = User::factory()->create();
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
