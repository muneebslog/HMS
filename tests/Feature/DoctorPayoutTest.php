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
    $response = $this->get(route('payout.doctor'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the doctor payout page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('payout.doctor'));

    $response->assertOk();
});

test('all doctors are listed regardless of daily payout setting', function () {
    $user = User::factory()->create();
    $dailyDoctor = Doctor::factory()->create(['payout_daily' => true, 'name' => 'Dr. Daily']);
    $regularDoctor = Doctor::factory()->create(['payout_daily' => false, 'name' => 'Dr. Regular']);

    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->assertSee('Dr. Daily')
        ->assertSee('Dr. Regular');
});

test('selecting a doctor shows services and calculated share for the date range', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create();
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
        'created_at' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->set('fromDate', now()->subDays(2)->toDateString())
        ->set('toDate', now()->toDateString())
        ->call('viewDoctor', $doctor->id)
        ->assertSet('viewingDoctorId', $doctor->id)
        ->assertSee(number_format(200.00, 2))
        ->assertSee(number_format(60.00, 2));
});

test('marking share paid creates a range payout record', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $doctor = Doctor::factory()->create();
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

    $fromDate = now()->toDateString();
    $toDate = now()->addDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->set('fromDate', $fromDate)
        ->set('toDate', $toDate)
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
    expect($payout->from_date->toDateString())->toBe($fromDate);
    expect($payout->to_date->toDateString())->toBe($toDate);
});

test('paid doctors show paid status and cannot be double paid for an overlapping range', function () {
    $user = User::factory()->create();
    $doctor = Doctor::factory()->create();
    DoctorPayout::factory()->create([
        'doctor_id' => $doctor->id,
        'from_date' => now()->toDateString(),
        'to_date' => now()->addDay()->toDateString(),
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->assertSee(__('Paid'))
        ->call('viewDoctor', $doctor->id)
        ->call('markPaid')
        ->assertHasNoErrors();

    expect(DoctorPayout::where('doctor_id', $doctor->id)->count())->toBe(1);
});

test('cannot mark paid when from date is after to date', function () {
    $user = User::factory()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::payout.doctor')
        ->set('fromDate', now()->addDay()->toDateString())
        ->set('toDate', now()->toDateString())
        ->call('viewDoctor', $doctor->id)
        ->call('markPaid')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('doctor_payouts', [
        'doctor_id' => $doctor->id,
    ]);
});
