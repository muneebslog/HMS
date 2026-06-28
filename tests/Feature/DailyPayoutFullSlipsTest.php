<?php

use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('first n slips are paid at full price and remaining at share rate', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create([
        'payout_daily' => true,
        'get_full_slips' => true,
        'full_slips_count' => 2,
    ]);
    $service = Service::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 100.00,
        'doctor_share' => 20.00,
    ]);

    $invoice = Invoice::factory()->create(['created_by' => $user->id]);

    foreach (range(1, 4) as $index) {
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'doctor_id' => $doctor->id,
            'service_name' => $service->name,
            'doctor_name' => $doctor->name,
            'price' => 100.00,
            'doctor_share' => 20.00,
            'created_at' => now()->addSeconds($index),
        ]);
    }

    // 2 full (100 each) + 2 shared (20 each) = 240
    Livewire::actingAs($user)
        ->test('pages::payout.daily')
        ->call('viewDoctor', $doctor->id)
        ->assertSet('viewingDoctorId', $doctor->id)
        ->assertSee(number_format(400.00, 2))
        ->assertSee(number_format(240.00, 2));
});

test('all items use share rate when full slips is disabled', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create([
        'payout_daily' => true,
        'get_full_slips' => false,
        'full_slips_count' => 0,
    ]);
    $service = Service::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 100.00,
        'doctor_share' => 30.00,
    ]);

    $invoice = Invoice::factory()->create(['created_by' => $user->id]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 100.00,
        'doctor_share' => 30.00,
        'created_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::payout.daily')
        ->call('viewDoctor', $doctor->id)
        ->assertSee(number_format(30.00, 2));
});

test('marking share paid stores full slips amount', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create([
        'payout_daily' => true,
        'get_full_slips' => true,
        'full_slips_count' => 1,
    ]);
    $service = Service::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 100.00,
        'doctor_share' => 20.00,
    ]);

    $invoice = Invoice::factory()->create(['created_by' => $user->id]);

    foreach (range(1, 2) as $index) {
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'doctor_id' => $doctor->id,
            'service_name' => $service->name,
            'doctor_name' => $doctor->name,
            'price' => 100.00,
            'doctor_share' => 20.00,
            'created_at' => now()->addSeconds($index),
        ]);
    }

    Livewire::actingAs($user)
        ->test('pages::payout.daily')
        ->call('viewDoctor', $doctor->id)
        ->call('markPaid')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctor_payouts', [
        'doctor_id' => $doctor->id,
        'total_amount' => 200.00,
        'share_amount' => 120.00,
        'created_by' => $user->id,
    ]);
});
