<?php

use App\Enums\OutgoingSampleStatus;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePagePermissionSeeder::class);
    $this->labTechnician = User::factory()->labTechnician()->create();

    $this->tsh = LabTest::factory()->create(['test_name' => 'TSH', 'is_in_house' => false]);
    $this->tsh->fields()->attach(LabField::factory()->create(['name' => 'TSH'])->id, ['display_order' => 1]);

    $invoice = fn (string $name) => LabInvoice::factory()->paid()->create([
        'patient_id' => Patient::factory()->create(['name' => $name])->id,
    ]);
    $outsourced = fn (string $name, array $attributes) => LabInvoiceItem::factory()->outgoing()->create([
        'lab_invoice_id' => $invoice($name)->id,
        'lab_test_id' => $this->tsh->id,
        'test_name' => 'TSH',
        ...$attributes,
    ]);

    $this->notCalled = $outsourced('Reception Patient', ['outgoing_status' => OutgoingSampleStatus::Pending]);
    $this->withLab = $outsourced('Fresh Patient', ['outgoing_status' => OutgoingSampleStatus::Given, 'given_at' => now()->subHours(5)]);
    $this->late = $outsourced('Late Patient', ['outgoing_status' => OutgoingSampleStatus::Given, 'given_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);
    $this->received = $outsourced('Done Patient', ['outgoing_status' => OutgoingSampleStatus::Received, 'report_path' => 'lab-reports/done.pdf']);

    LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $this->withLab->lab_invoice_id, 'test_name' => 'CBC']);
});

test('roles with lab cases can open the outsourced tests page', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create())
        ->get(route('lab.outsourced'))
        ->assertOk()
        ->assertSee('Outsourced Tests');
})->with(['labTechnician', 'receptionist']);

test('the sidebar links the page and badges tests late at the partner lab', function () {
    $this->actingAs($this->labTechnician)
        ->get(route('lab.outsourced'))
        ->assertSee(route('lab.outsourced'), false);

    expect(LabInvoiceItem::query()->lateAtPartnerLab()->pluck('id')->all())->toBe([$this->late->id]);
});

test('open tests are listed by default with a count on every tab', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->assertSee('RECEPTION PATIENT')
        ->assertSee('FRESH PATIENT')
        ->assertSee('LATE PATIENT')
        ->assertDontSee('DONE PATIENT')
        ->assertDontSee('CBC')
        ->assertSet('counts', ['open' => 3, 'reception' => 1, 'partner_lab' => 2, 'late' => 1, 'received' => 1, 'all' => 4]);
});

test('each tab narrows the list', function (string $tab, array $seen, array $hidden) {
    $component = Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->set('tab', $tab);

    foreach ($seen as $name) {
        $component->assertSee($name);
    }

    foreach ($hidden as $name) {
        $component->assertDontSee($name);
    }
})->with([
    'reception' => ['reception', ['RECEPTION PATIENT'], ['FRESH PATIENT', 'LATE PATIENT', 'DONE PATIENT']],
    'partner lab' => ['partner_lab', ['FRESH PATIENT', 'LATE PATIENT'], ['RECEPTION PATIENT', 'DONE PATIENT']],
    'late' => ['late', ['LATE PATIENT', 'Late at partner lab'], ['FRESH PATIENT', 'RECEPTION PATIENT', 'DONE PATIENT']],
    'received' => ['received', ['DONE PATIENT'], ['FRESH PATIENT', 'LATE PATIENT', 'RECEPTION PATIENT']],
]);

test('search matches patient names', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->set('search', 'Fresh')
        ->assertSee('FRESH PATIENT')
        ->assertDontSee('LATE PATIENT');
});

test('a report can be uploaded from the page, moving the test to received', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->call('openUpload', $this->late->id)
        ->assertSet('showUploadModal', true)
        ->set('reportUpload', UploadedFile::fake()->create('late.pdf', 100, 'application/pdf'))
        ->call('uploadReport')
        ->assertHasNoErrors()
        ->assertDontSee('LATE PATIENT')
        ->assertSet('counts.late', 0)
        ->assertSet('counts.received', 2);

    $item = $this->late->fresh();

    expect($item->outgoing_status)->toBe(OutgoingSampleStatus::Received)
        ->and($item->report_original_name)->toBe('late.pdf');
    Storage::disk('local')->assertExists($item->report_path);
});

test('staff without results entry see the list but cannot upload', function () {
    Livewire::actingAs(User::factory()->receptionist()->create())
        ->test('pages::lab.outsourced')
        ->assertDontSee('Upload report')
        ->assertDontSee('Add results')
        ->call('openUpload', $this->late->id)
        ->assertForbidden();
});

test('add results links to the case page, which opens the results form for that test', function () {
    $url = route('lab.cases.show', ['labInvoice' => $this->late->lab_invoice_id, 'results' => $this->late->id]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->assertSee($url, false);

    $this->actingAs($this->labTechnician)
        ->get($url)
        ->assertOk()
        ->assertSee('Save &amp; complete', false);
});
