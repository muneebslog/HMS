<?php

use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabTest;
use App\Models\PartnerLabReport;
use App\Models\Patient;
use App\Models\User;
use App\Services\PartnerLab\PartnerLabMatcher;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePagePermissionSeeder::class);
    $this->labTechnician = User::factory()->labTechnician()->create();

    $outsourced = function (string $name, int $age, string $gender, string $test) {
        $invoice = LabInvoice::factory()->paid()->create([
            'patient_id' => Patient::factory()->create(['name' => $name, 'age' => $age, 'gender' => $gender])->id,
            'created_at' => now()->subDay(),
        ]);

        return LabInvoiceItem::factory()->outgoing()->create([
            'lab_invoice_id' => $invoice->id,
            'lab_test_id' => LabTest::factory()->create(['test_name' => $test, 'is_in_house' => false])->id,
            'test_name' => $test,
            'outgoing_status' => OutgoingSampleStatus::Given,
            'given_at' => now()->subHours(20),
            'created_at' => now()->subDay(),
        ]);
    };

    $this->kaniz = $outsourced('KANIZ BB', 26, 'female', 'HBA1C');
    $this->bushra = $outsourced('BUSHRA', 45, 'female', 'RA. FACTOR');
    $this->bushraAbbas = $outsourced('BUSHRA ABBAS', 16, 'female', 'Ferritin');

    $this->report = PartnerLabReport::factory()->create([
        'patient_name' => 'KANEEZ BIBI',
        'patient_age' => '26 Year(s)',
        'patient_gender' => 'Female',
        'test_name' => 'Hemoglobin A1C (HBA1C)',
        'registered_at' => now()->subHours(18),
        'report_url' => 'https://testzone.example.test/offlineReports/labreport.aspx?id=AAA',
    ]);
});

test('names are matched despite the partner lab retyping them', function (string $ours, string $theirs) {
    expect(app(PartnerLabMatcher::class)->nameSimilarity($ours, $theirs))->toBeGreaterThanOrEqual(80);
})->with([
    ['KANIZ BB', 'KANEEZ BIBI'],
    ['M ZAQIR KHNA', 'M ZAQIR KHAN'],
    ['AWAS', 'AWAIS'],
    ['SHAIKH SAB', 'SHAIK SAB'],
    ['EMAN FATIMA', 'EMAN'],
    ['MURTAZA ALI', 'MURTAZA'],
    ['MRS ARSHAD', 'MRS ARSHAD'],
]);

test('different people do not look alike', function (string $ours, string $theirs) {
    expect(app(PartnerLabMatcher::class)->nameSimilarity($ours, $theirs))->toBeLessThan(60);
})->with([
    ['SHAFIQ', 'FIRDOUS'],
    ['NASIR', 'LAIBA'],
    ['HALEEMA SADIA', 'AYESHA'],
]);

test('the matcher suggests the right test using name, sex, age and timing', function () {
    $matcher = app(PartnerLabMatcher::class);

    expect($matcher->suggest($this->report)['item']->id)->toBe($this->kaniz->id);

    $bushra = PartnerLabReport::factory()->create([
        'patient_name' => 'BUSHRA', 'patient_age' => '45 Year(s)', 'patient_gender' => 'Female',
        'test_name' => 'RA Factor Quantitative', 'registered_at' => now()->subHours(18),
    ]);

    expect($matcher->suggest($bushra)['item']->id)->toBe($this->bushra->id);

    $stranger = PartnerLabReport::factory()->create(['patient_name' => 'RAFIQUE', 'patient_age' => '50 Year(s)', 'patient_gender' => 'Male', 'registered_at' => now()]);

    expect($matcher->suggest($stranger))->toBeNull();
});

test('the partner reports tab lists waiting reports with their suggested match', function () {
    PartnerLabReport::factory()->pending()->create(['patient_name' => 'NOT READY']);
    PartnerLabReport::factory()->create(['patient_name' => 'HIDDEN ONE', 'ignored_at' => now()]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->assertSee('Partner reports')
        ->assertSet('waitingPartnerCount', 1)
        ->set('tab', 'partner')
        ->assertSee('KANEEZ BIBI')
        ->assertSee('KANIZ BB')
        ->assertSee($this->kaniz->labInvoice->invoice_number)
        ->assertDontSee('NOT READY')
        ->assertDontSee('HIDDEN ONE');
});

test('attaching downloads the pdf, stores it on the test and marks it received', function () {
    Http::fake(['testzone.example.test/*' => Http::response('%PDF-1.7 report', 200, ['Content-Type' => 'application/pdf'])]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->set('tab', 'partner')
        ->call('openAttachForReport', $this->report->id)
        ->assertSet('showAttachModal', true)
        ->assertSet('attachItemId', $this->kaniz->id)
        ->call('attachPartnerReport')
        ->assertHasNoErrors()
        ->assertSet('showAttachModal', false)
        ->assertSet('waitingPartnerCount', 0);

    $item = $this->kaniz->fresh();
    $report = $this->report->fresh();

    expect($item->outgoing_status)->toBe(OutgoingSampleStatus::Received)
        ->and($item->report_original_name)->toEndWith('.pdf')
        ->and(Storage::disk('local')->get($item->report_path))->toBe('%PDF-1.7 report')
        ->and($report->lab_invoice_item_id)->toBe($item->id)
        ->and($report->attached_by)->toBe($this->labTechnician->id);
});

test('an outsourced test row can pick its partner report', function () {
    Http::fake(['testzone.example.test/*' => Http::response('%PDF-1.7', 200)]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->assertSee('Attach partner report')
        ->call('openAttachForItem', $this->kaniz->id)
        ->assertSet('attachReportId', $this->report->id)
        ->call('attachPartnerReport')
        ->assertHasNoErrors();

    expect($this->kaniz->fresh()->isDone())->toBeTrue();
});

test('a failed download keeps the form open and changes nothing', function () {
    Http::fake(['testzone.example.test/*' => Http::response('<html>error</html>', 200)]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->call('openAttachForReport', $this->report->id)
        ->call('attachPartnerReport')
        ->assertHasErrors('attachReportId')
        ->assertSet('showAttachModal', true);

    expect($this->kaniz->fresh()->report_path)->toBeNull()
        ->and($this->report->fresh()->lab_invoice_item_id)->toBeNull();
});

test('removing the uploaded report puts the partner report back in the waiting list', function () {
    Http::fake(['testzone.example.test/*' => Http::response('%PDF-1.7', 200)]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->call('openAttachForReport', $this->report->id)
        ->call('attachPartnerReport')
        ->call('removeReport', $this->kaniz->id)
        ->assertSet('waitingPartnerCount', 1);

    expect($this->report->fresh()->lab_invoice_item_id)->toBeNull();
});

test('a report that is not ours can be hidden', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.outsourced')
        ->call('ignorePartnerReport', $this->report->id)
        ->assertSet('waitingPartnerCount', 0);

    expect($this->report->fresh()->ignored_by)->toBe($this->labTechnician->id);
});

test('staff without results entry can see partner reports but not attach or hide them', function () {
    $receptionist = User::factory()->receptionist()->create();

    Livewire::actingAs($receptionist)
        ->test('pages::lab.outsourced')
        ->set('tab', 'partner')
        ->assertSee('KANEEZ BIBI')
        ->assertDontSee('Attach partner report')
        ->call('openAttachForReport', $this->report->id)
        ->assertForbidden();
});
