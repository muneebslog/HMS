<?php

use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('full slips are calculated per day across a date range', function () {
    $user = User::factory()->management()->create();
    $doctor = Doctor::factory()->create([
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

    // Two days, two items each
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 100.00,
        'doctor_share' => 20.00,
        'created_at' => now()->subDay()->startOfDay(),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 100.00,
        'doctor_share' => 20.00,
        'created_at' => now()->subDay()->startOfDay()->addHours(2),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 100.00,
        'doctor_share' => 20.00,
        'created_at' => now()->startOfDay(),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 100.00,
        'doctor_share' => 20.00,
        'created_at' => now()->startOfDay()->addHours(2),
    ]);

    // Each day: 1 full (100) + 1 shared (20) = 120 per day
    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->set('fromDate', now()->subDays(2)->toDateString())
        ->set('toDate', now()->toDateString())
        ->call('viewDoctor', $doctor->id)
        ->assertSet('viewingDoctorId', $doctor->id)
        ->assertSee(number_format(400.00, 2))
        ->assertSee(number_format(240.00, 2));
});

test('range payout stores full slips amount per day', function () {
    $user = User::factory()->management()->create();
    $doctor = Doctor::factory()->create([
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

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 100.00,
        'doctor_share' => 20.00,
        'created_at' => now()->subDay()->startOfDay(),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 100.00,
        'doctor_share' => 20.00,
        'created_at' => now()->subDay()->startOfDay()->addHours(2),
    ]);

    $fromDate = now()->subDays(2)->toDateString();
    $toDate = now()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->set('fromDate', $fromDate)
        ->set('toDate', $toDate)
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
