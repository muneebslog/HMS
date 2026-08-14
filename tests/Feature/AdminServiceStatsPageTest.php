<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Shift;
use App\Models\User;
use App\Services\ServiceStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('service statistics page is restricted to admins', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();

    $this->actingAs($admin)
        ->get(route('admin.service-stats'))
        ->assertSuccessful()
        ->assertSee(__('Service Statistics'));

    $this->actingAs($receptionist)
        ->get(route('admin.service-stats'))
        ->assertForbidden();
});

test('admin can filter service usage by date and time range', function () {
    $admin = User::factory()->admin()->create();
    $operator = User::factory()->receptionist()->create();
    $shift = Shift::factory()->closed()->for($operator)->create([
        'opened_at' => '2026-08-10 08:00:00',
        'closed_at' => '2026-08-12 20:00:00',
    ]);
    $service = Service::factory()->create(['name' => 'General Consultation']);
    $otherService = Service::factory()->create(['name' => 'Ultrasound']);
    $patient = Patient::factory()->create();

    foreach ([
        ['created_at' => '2026-08-10 09:00:00', 'service' => $service, 'status' => 'paid'],
        ['created_at' => '2026-08-10 10:00:00', 'service' => $service, 'status' => 'paid'],
        ['created_at' => '2026-08-11 12:00:00', 'service' => $service, 'status' => 'paid'],
        ['created_at' => '2026-08-12 18:00:00', 'service' => $service, 'status' => 'paid'],
        ['created_at' => '2026-08-11 13:00:00', 'service' => $service, 'status' => 'cancelled'],
        ['created_at' => '2026-08-10 11:00:00', 'service' => $otherService, 'status' => 'paid'],
    ] as $usage) {
        $invoice = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'shift_id' => $shift->id,
            'total' => 100,
            'status' => $usage['status'],
            'created_at' => $usage['created_at'],
            'updated_at' => $usage['created_at'],
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'service_id' => $usage['service']->id,
            'service_name' => $usage['service']->name,
            'price' => 100,
            'created_at' => $usage['created_at'],
            'updated_at' => $usage['created_at'],
        ]);
    }

    $statistics = app(ServiceStatisticsService::class)->forDateAndTimeRange(
        $service,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-12'),
        '08:00',
        '16:00',
    );

    expect($statistics)
        ->total->toBe(3)
        ->average_per_day->toBe(1.0)
        ->highest_usage->toBe(['date' => '2026-08-10', 'total' => 2])
        ->lowest_usage->toBe(['date' => '2026-08-12', 'total' => 0])
        ->daily_usage->toBe([
            ['date' => '2026-08-10', 'total' => 2],
            ['date' => '2026-08-11', 'total' => 1],
            ['date' => '2026-08-12', 'total' => 0],
        ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.service-stats')
        ->set('dateFrom', '2026-08-10')
        ->set('dateTo', '2026-08-12')
        ->set('timeFrom', '08:00')
        ->set('timeTo', '16:00')
        ->set('selectedServiceId', $service->id)
        ->assertSee('General Consultation')
        ->assertSee(__('Total Usage'))
        ->assertSee(__('Average per Day'))
        ->assertSee(__('Highest Usage'))
        ->assertSee(__('Lowest Usage'))
        ->assertSee(__('Daily Usage'));
});

test('service statistics page rejects an inverted date range', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.service-stats')
        ->set('dateFrom', '2026-08-12')
        ->set('dateTo', '2026-08-10')
        ->assertSee(__('Enter a valid date and time range. The start date must not be after the end date.'));
});
