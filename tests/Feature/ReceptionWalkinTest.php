<?php

use App\Models\Doctor;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.walkin'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the walk-in page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('reception.walkin'));

    $response->assertOk();
});

test('a standalone service can be added without a doctor', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 75.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) use ($service) {
            return count($items) === 1
                && $items[0]['service_id'] === $service->id
                && $items[0]['doctor_id'] === null
                && $items[0]['price'] == 75.00;
        });
});

test('a non-standalone service requires a related doctor', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => false]);
    $doctor = Doctor::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 150.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Jane Doe')
        ->set('selectedServiceId', $service->id)
        ->set('selectedDoctorId', '')
        ->call('add')
        ->assertHasErrors(['selectedDoctorId']);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Jane Doe')
        ->set('selectedServiceId', $service->id)
        ->set('selectedDoctorId', $doctor->id)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) use ($service, $doctor) {
            return count($items) === 1
                && $items[0]['service_id'] === $service->id
                && $items[0]['doctor_id'] === $doctor->id
                && $items[0]['price'] == 150.00;
        });
});

test('the reset button clears the form and services', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('clear')
        ->assertSet('patientName', '')
        ->assertSet('selectedServiceId', null)
        ->assertCount('items', 0);
});

test('a service can be removed from the list', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('remove', 0)
        ->assertCount('items', 0);
});

test('a service price can be edited from the table', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 100.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->assertCount('items', 1)
        ->call('editPrice', 0)
        ->assertSet('editingItemPrice', '100')
        ->set('editingItemPrice', '250.50')
        ->call('updatePrice')
        ->assertHasNoErrors()
        ->assertSet('items', function ($items) {
            return count($items) === 1 && $items[0]['price'] == 250.50;
        })
        ->assertSet('totalPrice', 250.50);
});

test('price edits must be a non-negative number', function () {
    $user = User::factory()->create();
    $service = Service::factory()->create(['is_standalone' => true]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('editPrice', 0)
        ->set('editingItemPrice', '-10')
        ->call('updatePrice')
        ->assertHasErrors(['editingItemPrice']);
});
