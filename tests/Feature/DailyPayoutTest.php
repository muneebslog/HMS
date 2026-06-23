<?php

use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('payout.daily'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the daily payout page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('payout.daily'));

    $response->assertOk();
});

test('only doctors with daily payout enabled are listed', function () {
    $user = User::factory()->create();
    $dailyDoctor = Doctor::factory()->create(['payout_daily' => true, 'name' => 'Dr. Daily']);
    Doctor::factory()->create(['payout_daily' => false, 'name' => 'Dr. Regular']);

    Livewire::actingAs($user)
        ->test('pages::payout.daily')
        ->assertSee('Dr. Daily')
        ->assertDontSee('Dr. Regular');
});

test('selecting a doctor shows todays services and calculated share', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create(['payout_daily' => true]);
    $service = Service::factory()->create();
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 200.00,
        'doctor_share' => 30.00,
    ]);

    $invoice = Invoice::factory()->create(['created_by' => $user->id]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'service_name' => $service->name,
        'doctor_name' => $doctor->name,
        'price' => 200.00,
        'doctor_share' => 30.00,
        'created_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::payout.daily')
        ->call('viewDoctor', $doctor->id)
        ->assertSet('viewingDoctorId', $doctor->id)
        ->assertSee(number_format(200.00, 2))
        ->assertSee(number_format(60.00, 2));
});

test('marking share paid creates a payout record', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create(['payout_daily' => true]);
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
        'created_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::payout.daily')
        ->call('viewDoctor', $doctor->id)
        ->call('markPaid')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctor_payouts', [
        'doctor_id' => $doctor->id,
        'total_amount' => 100.00,
        'share_amount' => 20.00,
        'created_by' => $user->id,
    ]);

    $payout = DoctorPayout::where('doctor_id', $doctor->id)->first();
    expect($payout->date->toDateString())->toBe(now()->toDateString());
});

test('paid doctors show paid status and cannot be double paid', function () {
    $user = User::factory()->create();
    $doctor = Doctor::factory()->create(['payout_daily' => true]);
    DoctorPayout::factory()->create([
        'doctor_id' => $doctor->id,
        'date' => now()->toDateString(),
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::payout.daily')
        ->assertSee(__('Paid'))
        ->call('viewDoctor', $doctor->id)
        ->call('markPaid')
        ->assertHasNoErrors();

    expect(DoctorPayout::where('doctor_id', $doctor->id)->count())->toBe(1);
});
