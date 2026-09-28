<?php

use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\User;
use App\Services\CeoLabOverview;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    config(['hospital.lab.tracking_started_at' => now()->subDays(5)->toDateString()]);

    $this->oldInvoice = LabInvoice::factory()->paid()->create(['created_at' => now()->subDays(10)]);
    $old = ['lab_invoice_id' => $this->oldInvoice->id, 'created_at' => now()->subDays(10)];

    $this->oldInHouse = LabInvoiceItem::factory()->inHouse()->create([...$old, 'test_name' => 'Old CBC', 'sample_received_at' => now()->subDays(10)]);
    $this->oldOutsourced = LabInvoiceItem::factory()->outgoing()->create([...$old, 'test_name' => 'Old TSH', 'outgoing_status' => null]);
    $this->oldButSentByRider = LabInvoiceItem::factory()->outgoing()->create([
        ...$old, 'test_name' => 'Old Culture', 'outgoing_status' => OutgoingSampleStatus::Given, 'given_at' => now()->subDays(4),
    ]);

    $this->newInHouse = LabInvoiceItem::factory()->inHouse()->create(['test_name' => 'New CBC', 'sample_received_at' => now()]);
});

test('tests billed before tracking started with no HMS activity count as done in the old software', function () {
    expect($this->oldInHouse->isLegacy())->toBeTrue()
        ->and($this->oldInHouse->isDone())->toBeTrue()
        ->and($this->oldOutsourced->isLegacy())->toBeTrue()
        ->and($this->oldButSentByRider->isLegacy())->toBeFalse()
        ->and($this->newInHouse->isLegacy())->toBeFalse();

    expect(LabInvoiceItem::query()->pending()->pluck('id')->sort()->values()->all())
        ->toBe([$this->oldButSentByRider->id, $this->newInHouse->id]);
});

test('old tests leave the lab queues, badges and late counts', function () {
    LabInvoiceItem::factory()->inHouse()->create(['created_at' => now()->subDays(10), 'lab_invoice_id' => $this->oldInvoice->id]);
    LabInvoiceItem::factory()->outgoing()->create(['created_at' => now()->subDays(10), 'lab_invoice_id' => $this->oldInvoice->id]);

    expect(LabInvoiceItem::query()->awaitingSample()->count())->toBe(0)
        ->and(LabInvoiceItem::query()->awaitingRider()->count())->toBe(0)
        ->and(LabInvoiceItem::query()->lateAtPartnerLab()->pluck('id')->all())->toBe([$this->oldButSentByRider->id]);
});

test('the lab dashboard only counts tests billed since tracking started', function () {
    $dashboard = Livewire::actingAs(User::factory()->labTechnician()->create())->test('pages::lab.dashboard');

    expect($dashboard->instance()->recentItems->pluck('id')->all())->toBe([$this->newInHouse->id])
        ->and($dashboard->instance()->awaitingResults()->pluck('id')->all())->toBe([$this->newInHouse->id]);
});

test('ceo open work and speed skip old tests', function () {
    $this->oldInHouse->update(['results_completed_at' => now()]);
    $overview = app(CeoLabOverview::class);

    expect($overview->openItems()->pluck('id')->sort()->values()->all())->toBe([$this->oldButSentByRider->id, $this->newInHouse->id])
        ->and($overview->speed(now()->subDays(30)->toImmutable())['completed'])->toBe(0);
});

test('the case page and outsourced page label old tests', function () {
    $user = User::factory()->labTechnician()->create();

    $this->actingAs($user)
        ->get(route('lab.cases.show', $this->oldInvoice))
        ->assertOk()
        ->assertSee('Done in old lab software');

    Livewire::actingAs($user)
        ->test('pages::lab.outsourced')
        ->assertDontSee('Old TSH')
        ->assertSee('Old Culture')
        ->set('fromDate', now()->subDays(30)->toDateString())
        ->set('tab', 'all')
        ->assertSee('Old TSH')
        ->assertSee('Done in old lab software');
});

test('the lab cases pending filter ignores old cases', function () {
    $this->oldButSentByRider->update(['outgoing_status' => OutgoingSampleStatus::Received]);

    Livewire::actingAs(User::factory()->labTechnician()->create())
        ->test('pages::lab.cases')
        ->set('fromDate', now()->subDays(30)->toDateString())
        ->assertSet('summary.pending', 1);
});

test('without a cutoff nothing is treated as old', function () {
    config(['hospital.lab.tracking_started_at' => null]);

    expect($this->oldInHouse->isLegacy())->toBeFalse()
        ->and(LabInvoiceItem::query()->pending()->count())->toBe(4);
});
