<?php

use App\Enums\OutgoingSampleStatus;
use App\Models\Doctor;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\Patient;
use App\Models\User;
use App\Services\CeoLabOverview;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->travelTo(today()->setTime(15, 0));
    $this->ceo = User::factory()->ceo()->create(['name' => 'Owner Son']);
});

/**
 * Make a paid case with the given items.
 *
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $invoice
 */
function ceoCase(array $items, array $invoice = []): LabInvoice
{
    $case = LabInvoice::factory()->paid()->create(array_merge([
        'patient_id' => Patient::factory()->create(['name' => 'Ceo Patient'])->id,
        'total' => 1000,
        'discount_amount' => 0,
    ], $invoice));

    foreach ($items as $item) {
        LabInvoiceItem::factory()->create(array_merge([
            'lab_invoice_id' => $case->id,
            'is_in_house' => true,
            'outgoing_status' => null,
            'time_required' => 'Same day',
        ], $item));
    }

    return $case;
}

test('the CEO lands on the lab overview and has read-only lab access', function () {
    $this->actingAs($this->ceo)->get(route('dashboard'))->assertRedirect(route('ceo.lab'));
    $this->actingAs($this->ceo)->get(route('ceo.lab'))->assertOk()->assertSee('Lab Overview')->assertSee('Where every open test is');
    $this->actingAs($this->ceo)->get(route('lab.cases'))->assertOk();
    $this->actingAs($this->ceo)->get(route('lab.samples'))->assertForbidden();

    expect($this->ceo->canAccessRoute('lab.results.entry'))->toBeFalse();
});

test('other staff cannot open the CEO overview', function () {
    $this->actingAs(User::factory()->receptionist()->create())->get(route('ceo.lab'))->assertForbidden();
    $this->actingAs(User::factory()->labTechnician()->create())->get(route('ceo.lab'))->assertForbidden();
});

test('every open test is placed in the stage of whoever holds it', function () {
    $case = ceoCase([
        ['test_name' => 'Rider pending', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Pending],
        ['test_name' => 'Rider called', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Asked],
        ['test_name' => 'At partner', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Given, 'given_at' => now()->subHour()],
        ['test_name' => 'No sample'],
        ['test_name' => 'In lab', 'sample_received_at' => now()],
        ['test_name' => 'Done', 'sample_received_at' => now(), 'results_completed_at' => now()],
        ['test_name' => 'Clotted one'],
    ]);
    LabSampleRetake::factory()->create(['lab_invoice_item_id' => $case->items()->where('test_name', 'Clotted one')->value('id')]);

    $stages = collect(app(CeoLabOverview::class)->stages())
        ->mapWithKeys(fn (array $stage) => [$stage['key'] => $stage['items']->pluck('test_name')->all()]);

    expect($stages->all())->toBe([
        'rider_not_called' => ['Rider pending'],
        'waiting_rider' => ['Rider called'],
        'partner_lab' => ['At partner'],
        'not_received' => ['No sample'],
        'retake' => ['Clotted one'],
        'results_pending' => ['In lab'],
    ]);
});

test('tests past their promised time, or over two days at the partner lab, are late', function () {
    ceoCase([
        ['test_name' => 'Yesterday same-day', 'created_at' => now()->subDay(), 'sample_received_at' => now()->subDay()],
        ['test_name' => 'Today same-day', 'sample_received_at' => now()],
        ['test_name' => 'Three day test', 'created_at' => now()->subDay(), 'time_required' => 'After 3 days', 'sample_received_at' => now()],
        ['test_name' => 'Partner 3 days', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Given, 'given_at' => now()->subDays(3)],
        ['test_name' => 'Partner 1 day', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Given, 'given_at' => now()->subDay()],
    ]);

    $late = app(CeoLabOverview::class)->lateTests()->map(fn (array $row) => $row['item']->test_name)->all();

    expect($late)->toEqualCanonicalizing(['Yesterday same-day', 'Partner 3 days']);
});

test('the promised time follows the time required on the test', function () {
    $billed = today()->setTime(10, 0);
    $due = fn (?string $timeRequired) => (new LabInvoiceItem(['time_required' => $timeRequired]))
        ->forceFill(['created_at' => $billed])
        ->dueAt()
        ->format('Y-m-d H:i');

    expect($due('Same day'))->toBe($billed->format('Y-m-d').' 23:59')
        ->and($due('Next day'))->toBe($billed->copy()->addDay()->format('Y-m-d').' 23:59')
        ->and($due('After 3 days'))->toBe($billed->copy()->addDays(3)->format('Y-m-d').' 23:59')
        ->and($due('2'))->toBe($billed->copy()->addDays(2)->format('Y-m-d').' 23:59')
        ->and($due(null))->toBe($billed->format('Y-m-d').' 23:59');
});

test('money shows revenue, discounts, returns and doctor shares for the period', function () {
    $doctor = Doctor::factory()->create(['name' => 'Dr Referrer']);
    ceoCase([['price' => 1200]], ['total' => 1000, 'discount_amount' => 200, 'referred_by_doctor_id' => $doctor->id, 'doctor_share' => 10]);
    ceoCase([['price' => 500, 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Pending]], ['total' => 500]);
    ceoCase([['price' => 700]], ['total' => 700, 'status' => 'returned']);
    ceoCase([['price' => 900]], ['total' => 900, 'created_at' => now()->subDays(3)]);

    $today = app(CeoLabOverview::class)->money(today()->toImmutable());
    $week = app(CeoLabOverview::class)->money(today()->subDays(6)->toImmutable());

    expect($today)->toMatchArray([
        'revenue' => 1500.0,
        'cases' => 2,
        'discounts' => 200.0,
        'returns_count' => 1,
        'returns_amount' => 700.0,
        'doctor_shares' => 100.0,
        'net' => 1400.0,
        'in_house' => 1200.0,
        'outsourced' => 500.0,
    ])
        ->and($today['top_doctors']->first())->toMatchArray(['name' => 'Dr Referrer', 'cases' => 1, 'revenue' => 1000.0, 'share' => 100.0])
        ->and($week['revenue'])->toBe(2400.0);
});

test('the overview page renders with data and switches period', function () {
    ceoCase([
        ['test_name' => 'Late CBC', 'created_at' => now()->subDay(), 'sample_received_at' => now()->subDay()],
        ['test_name' => 'TSH', 'is_in_house' => false, 'outgoing_status' => OutgoingSampleStatus::Given, 'given_at' => now()->subDays(3)],
    ]);

    Livewire::actingAs($this->ceo)
        ->test('pages::ceo.lab')
        ->assertSee('Late CBC')
        ->assertSee('With partner lab · over 2 days')
        ->assertSee('Rs 1,000')
        ->call('showStage', 'partner_lab')
        ->assertSet('showStageModal', true)
        ->assertSee('TSH')
        ->set('period', '30d')
        ->assertSee('30 days')
        ->set('period', 'bogus')
        ->assertSet('period', 'today');
});

test('an empty lab still renders', function () {
    Livewire::actingAs($this->ceo)->test('pages::ceo.lab')->assertOk()->assertSee('Nothing is late');
});
