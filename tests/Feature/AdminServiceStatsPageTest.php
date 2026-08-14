<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\ServiceStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('admin can select a shift and service to view its statistics', function () {
    $admin = User::factory()->admin()->create();
    $operator = User::factory()->receptionist()->create();
    $shift = Shift::factory()->closed()->for($operator)->create([
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
    ]);
    $otherShift = Shift::factory()->closed()->for($operator)->create([
        'opened_at' => now()->subDay(),
        'closed_at' => now()->subDay()->addHours(8),
    ]);
    $service = Service::factory()->create(['name' => 'General Consultation']);
    $otherService = Service::factory()->create(['name' => 'Ultrasound']);
    $doctor = Doctor::factory()->create(['name' => 'Dr Stats']);
    $patient = Patient::factory()->create();
    $queue = ServiceQueue::factory()->closed()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'shift_id' => $shift->id,
        'date' => $shift->opened_at->toDateString(),
        'reset_type' => TokenResetType::Shift,
        'opened_at' => $shift->opened_at,
        'closed_at' => $shift->closed_at,
    ]);

    foreach ([
        ['price' => 150, 'status' => 'served', 'displayed_at' => now()->subMinutes(10)],
        ['price' => 200, 'status' => 'waiting', 'displayed_at' => null],
    ] as $index => $visit) {
        $invoice = Invoice::factory()->paid()->create([
            'patient_id' => $patient->id,
            'shift_id' => $shift->id,
            'total' => $visit['price'],
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'doctor_id' => $doctor->id,
            'service_name' => $service->name,
            'doctor_name' => $doctor->name,
            'price' => $visit['price'],
        ]);

        QueueToken::factory()->create([
            'service_queue_id' => $queue->id,
            'invoice_item_id' => $item->id,
            'patient_id' => $patient->id,
            'token_number' => $index + 1,
            'status' => $visit['status'],
            'arrived_at' => now()->subMinutes(30),
            'displayed_at' => $visit['displayed_at'],
        ]);
    }

    $cancelledInvoice = Invoice::factory()->cancelled()->create([
        'patient_id' => $patient->id,
        'shift_id' => $shift->id,
        'total' => 999,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $cancelledInvoice->id,
        'service_id' => $service->id,
        'service_name' => $service->name,
        'price' => 999,
    ]);

    $otherInvoice = Invoice::factory()->paid()->create([
        'patient_id' => $patient->id,
        'shift_id' => $otherShift->id,
        'total' => 500,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $otherInvoice->id,
        'service_id' => $otherService->id,
        'service_name' => $otherService->name,
        'price' => 500,
    ]);

    $statistics = app(ServiceStatisticsService::class)->forShiftAndService($shift, $service);

    expect($statistics)
        ->total_visits->toBe(2)
        ->unique_patients->toBe(1)
        ->revenue->toBe(350.0)
        ->average_wait_minutes->toBe(20)
        ->and($statistics['statuses'])->toMatchArray([
            'served' => 1,
            'waiting' => 1,
        ])
        ->and($statistics['doctor_breakdown'])->toBe([
            [
                'doctor_name' => 'Dr Stats',
                'visits' => 2,
                'revenue' => 350.0,
            ],
        ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.service-stats')
        ->set('selectedShiftId', $shift->id)
        ->assertSee('General Consultation')
        ->assertDontSee('Ultrasound')
        ->set('selectedServiceId', $service->id)
        ->assertSee(__('Total Visits'))
        ->assertSee('350.00')
        ->assertSee('Dr Stats')
        ->set('selectedShiftId', $otherShift->id)
        ->assertSet('selectedServiceId', null);
});
