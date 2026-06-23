<?php

use App\Models\LabTest;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.lab-entry'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the lab entry page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.lab-entry'));

    $response->assertOk();
});

test('a lab test can be added to the bill', function () {
    $user = User::factory()->create();
    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'test_price' => 1200.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) use ($labTest) {
            return count($items) === 1
                && $items[0]['lab_test_id'] === $labTest->id
                && $items[0]['test_name'] === 'Complete Blood Count'
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
        ->set('patientPhone', '1234567890')
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
        ->set('patientPhone', '1234567890')
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
        ->set('patientPhone', '1234567890')
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
