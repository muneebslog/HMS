<?php

use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a lab invoice can be saved with items', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'test_price' => 1200.00,
        'time_required' => '1 hour',
        'is_in_house' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $patient = Patient::where('name', 'John Doe')->first();
    expect($patient)->not->toBeNull()
        ->phone->toBe('1234567890')
        ->age->toBe(30)
        ->gender->toBe('male');

    $invoice = LabInvoice::where('patient_id', $patient->id)->first();
    expect($invoice)->not->toBeNull()
        ->subtotal->toBe(1200.00)
        ->discount_percentage->toBe(0.0)
        ->discount_amount->toBe(0.0)
        ->total->toBe(1200.00)
        ->status->toBe('paid')
        ->created_by->toBe($user->id);

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first())
        ->lab_test_id->toBe($labTest->id)
        ->test_name->toBe('Complete Blood Count')
        ->test_code->toBe('CBC-001')
        ->time_required->toBe('1 hour')
        ->is_in_house->toBeTrue()
        ->price->toBe(1200.00);
});

test('a lab invoice can be saved with a discount', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create(['test_price' => 1000.00]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'Jane Doe')
        ->set('patientPhone', '0987654321')
        ->set('patientGender', 'female')
        ->set('patientAge', 25)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->set('discountPercentage', '10')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();
    expect($invoice)->not->toBeNull()
        ->subtotal->toBe(1000.00)
        ->discount_percentage->toBe(10.0)
        ->discount_amount->toBe(100.00)
        ->total->toBe(900.00);
});

test('saving a lab invoice requires patient details', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->set('patientName', '')
        ->set('patientPhone', '')
        ->set('patientGender', '')
        ->set('patientAge', null)
        ->call('save')
        ->assertHasErrors(['patientName', 'patientPhone', 'patientGender', 'patientAge']);

    expect(LabInvoice::count())->toBe(0);
});

test('saving a lab invoice requires at least one item', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->call('save')
        ->assertHasErrors(['items']);

    expect(LabInvoice::count())->toBe(0)
        ->and(Patient::count())->toBe(0);
});

test('saving a lab invoice clears the form', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertSet('patientName', '')
        ->assertSet('patientPhone', '')
        ->assertSet('patientGender', '')
        ->assertSet('patientAge', null)
        ->assertCount('items', 0);
});
