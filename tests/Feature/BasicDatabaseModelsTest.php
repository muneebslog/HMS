<?php

use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('models');

test('patients table can store patient records', function () {
    $patient = Patient::factory()->create([
        'name' => 'John Doe',
        'phone' => '1234567890',
        'age' => 30,
        'gender' => 'male',
    ]);

    expect($patient->fresh())
        ->name->toBe('John Doe')
        ->phone->toBe('1234567890')
        ->age->toBe(30)
        ->gender->toBe('male');
});

test('patient nullable fields can be null', function () {
    $patient = Patient::factory()->create([
        'phone' => null,
        'age' => null,
        'gender' => null,
    ]);

    expect($patient->fresh())
        ->phone->toBeNull()
        ->age->toBeNull()
        ->gender->toBeNull();
});

test('services table can store service records', function () {
    $service = Service::factory()->create([
        'name' => 'General Checkup',
        'is_standalone' => true,
    ]);

    expect($service->fresh())
        ->name->toBe('General Checkup')
        ->is_standalone->toBeTrue();
});

test('doctors table can store doctor records', function () {
    $doctor = Doctor::factory()->create([
        'name' => 'Dr. Smith',
        'specialization' => 'Cardiology',
    ]);

    expect($doctor->fresh())
        ->name->toBe('Dr. Smith')
        ->specialization->toBe('Cardiology');
});

test('service_prices table can store prices with doctor', function () {
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    $price = ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 150.00,
        'doctor_share' => 25.00,
    ]);

    expect($price->fresh())
        ->service_id->toBe($service->id)
        ->doctor_id->toBe($doctor->id)
        ->price->toBe(150.00)
        ->doctor_share->toBe(25.00)
        ->service->id->toBe($service->id)
        ->doctor->id->toBe($doctor->id);
});

test('service_prices table can store prices without doctor', function () {
    $service = Service::factory()->create();

    $price = ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 99.99,
        'doctor_share' => null,
    ]);

    expect($price->fresh())
        ->doctor_id->toBeNull()
        ->doctor_share->toBeNull()
        ->service->id->toBe($service->id)
        ->doctor->toBeNull();
});

test('lab_tests table can store lab test records', function () {
    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'test_price' => 1200.00,
        'time_required' => '1 hour',
        'is_in_house' => true,
    ]);

    expect($labTest->fresh())
        ->test_name->toBe('Complete Blood Count')
        ->test_code->toBe('CBC-001')
        ->test_price->toBe(1200.00)
        ->time_required->toBe('1 hour')
        ->is_in_house->toBeTrue();
});

test('lab_tests table can store send out lab test records', function () {
    $labTest = LabTest::factory()->create([
        'test_name' => 'Advanced Genetic Screening',
        'test_code' => 'AGS-002',
        'test_price' => 15000.00,
        'time_required' => '5 days',
        'is_in_house' => false,
    ]);

    expect($labTest->fresh())
        ->test_name->toBe('Advanced Genetic Screening')
        ->test_code->toBe('AGS-002')
        ->test_price->toBe(15000.00)
        ->time_required->toBe('5 days')
        ->is_in_house->toBeFalse();
});

test('invoices table can store invoice records', function () {
    $patient = Patient::factory()->create();
    $user = User::factory()->create();

    $invoice = Invoice::factory()->create([
        'patient_id' => $patient->id,
        'invoice_number' => 'INV-20260623000001',
        'total' => 250.00,
        'status' => 'paid',
        'created_by' => $user->id,
    ]);

    expect($invoice->fresh())
        ->patient_id->toBe($patient->id)
        ->invoice_number->toBe('INV-20260623000001')
        ->total->toBe(250.00)
        ->status->toBe('paid')
        ->created_by->toBe($user->id)
        ->patient->id->toBe($patient->id)
        ->creator->id->toBe($user->id);
});

test('invoice_items table can store invoice item records', function () {
    $invoice = Invoice::factory()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    $item = InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 150.00,
    ]);

    expect($item->fresh())
        ->invoice_id->toBe($invoice->id)
        ->service_id->toBe($service->id)
        ->doctor_id->toBe($doctor->id)
        ->service_name->toBe($service->name)
        ->doctor_name->toBe($doctor->name)
        ->price->toBe(150.00)
        ->invoice->id->toBe($invoice->id)
        ->service->id->toBe($service->id)
        ->doctor->id->toBe($doctor->id);
});

test('invoice_items table can store records without a doctor', function () {
    $invoice = Invoice::factory()->create();
    $service = Service::factory()->create();

    $item = InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => null,
        'service_name' => $service->name,
        'doctor_name' => null,
        'price' => 99.99,
    ]);

    expect($item->fresh())
        ->doctor_id->toBeNull()
        ->doctor_name->toBeNull()
        ->doctor->toBeNull()
        ->invoice->id->toBe($invoice->id)
        ->service->id->toBe($service->id);
});
