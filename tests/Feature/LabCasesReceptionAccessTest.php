<?php

use App\Enums\UserRole;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabResult;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RolePagePermission;
use App\Models\User;
use App\Services\PageAccessService;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->receptionist = User::factory()->receptionist()->create();
    $this->labTechnician = User::factory()->labTechnician()->create();

    $labTest = LabTest::factory()->create(['test_name' => 'CBC', 'is_in_house' => true]);
    $this->hb = LabField::factory()->create(['name' => 'HB']);
    $labTest->fields()->attach($this->hb->id, ['display_order' => 1]);

    $this->patient = Patient::factory()->create(['name' => 'Reception Patient', 'gender' => 'female', 'age' => 30]);
    $this->invoice = LabInvoice::factory()->paid()->create(['patient_id' => $this->patient->id, 'invoice_number' => '928700']);

    $this->done = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'lab_test_id' => $labTest->id, 'test_name' => 'CBC', 'results_completed_at' => now(),
    ]);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->done->id, 'lab_field_id' => $this->hb->id, 'value' => '13']);

    $this->pending = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'lab_test_id' => $labTest->id, 'test_name' => 'CBC Repeat',
    ]);
});

test('reception can search lab cases and see their status without the edit button', function () {
    Livewire::actingAs($this->receptionist)
        ->test('pages::lab.cases')
        ->set('search', '928700')
        ->assertSee('RECEPTION PATIENT')
        ->assertSee('1/2')
        ->assertSee('Open')
        ->assertDontSeeHtml('openEditPatientModal');
});

test('reception can open a case and print completed reports but cannot manage results', function () {
    $this->actingAs($this->receptionist)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertOk()
        ->assertSee('Show report')
        ->assertDontSee('Add results')
        ->assertDontSee('Edit results')
        ->assertDontSee('No fields set up for this test');

    $this->actingAs($this->receptionist)
        ->get(route('lab.cases.report', ['labInvoice' => $this->invoice, 'item' => $this->done->id]))
        ->assertOk()
        ->assertSee('RECEPTION PATIENT');
});

test('reception cannot add, save or discard results even by calling the actions directly', function () {
    $component = fn () => Livewire::actingAs($this->receptionist)->test('pages::lab.case', ['labInvoice' => $this->invoice]);

    $component()->call('openResults', $this->pending->id)->assertForbidden();
    $component()->set('editingItemId', $this->done->id)->call('saveResults', true)->assertForbidden();
    $component()->set('editingItemId', $this->done->id)->call('discardResults')->assertForbidden();

    expect($this->done->results()->count())->toBe(1)
        ->and($this->done->fresh()->isDone())->toBeTrue()
        ->and($this->pending->results()->count())->toBe(0);
});

test('reception cannot edit patient details from the cases list', function () {
    Livewire::actingAs($this->receptionist)
        ->test('pages::lab.cases')
        ->call('openEditPatientModal', $this->patient->id)
        ->assertForbidden();

    Livewire::actingAs($this->receptionist)
        ->test('pages::lab.cases')
        ->set('editingPatientId', $this->patient->id)
        ->set('editPatientName', 'Changed')
        ->call('savePatientDetails')
        ->assertForbidden();

    expect($this->patient->fresh()->name)->toBe('RECEPTION PATIENT');
});

test('lab technicians keep full results entry and patient editing', function () {
    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertSee('Add results')
        ->assertSee('Edit results');

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->pending->id)
        ->assertSet('showResultsModal', true);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.cases')
        ->assertSeeHtml('openEditPatientModal');
});

test('the migration keeps results entry for current lab roles and gives reception view access', function () {
    RolePagePermission::query()->whereIn('route_name', ['lab.results.entry'])->delete();
    RolePagePermission::query()->where('role', UserRole::Receptionist)->where('route_name', 'lab.cases')->delete();
    foreach (UserRole::cases() as $role) {
        app(PageAccessService::class)->clearCacheForRole($role);
    }

    $migration = require database_path('migrations/2026_09_24_012959_grant_lab_results_entry_and_reception_lab_cases_access.php');
    $migration->up();

    $roles = fn (string $route) => RolePagePermission::query()->where('route_name', $route)->pluck('role')
        ->map(fn ($role) => $role instanceof UserRole ? $role->value : $role)->sort()->values()->all();

    expect($roles('lab.results.entry'))->toContain(UserRole::LabTechnician->value)->not->toContain(UserRole::Receptionist->value)
        ->and($roles('lab.cases'))->toContain(UserRole::Receptionist->value)
        ->and($this->receptionist->fresh()->canAccessRoute('lab.cases.report'))->toBeTrue()
        ->and($this->receptionist->fresh()->canAccessRoute('lab.results.entry'))->toBeFalse();
});
