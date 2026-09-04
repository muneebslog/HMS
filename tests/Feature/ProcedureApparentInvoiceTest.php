<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureApparentInvoice;
use App\Models\ProcedureApparentInvoiceItem;
use App\Models\ProcedureType;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('guests cannot view the apparent invoice print page', function () {
    $procedure = Procedure::factory()->create();
    ProcedureApparentInvoice::factory()->create(['procedure_id' => $procedure->id]);

    $this->get(route('reception.procedures.apparent-invoice', $procedure))
        ->assertRedirect(route('login'));
});

test('apparent invoice print returns not found when no invoice is saved', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->get(route('reception.procedures.apparent-invoice', $procedure))
        ->assertNotFound();
});

test('a receptionist can save and print an apparent invoice with custom fees', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $patient = Patient::factory()->create([
        'name' => 'Saba Amir',
        'husband_name' => 'Amir Sohail',
    ]);
    $doctor = Doctor::factory()->create(['name' => 'Dr. Sadia Sohail']);
    $procedureType = ProcedureType::factory()->create(['name' => 'LSCS']);

    $procedure = Procedure::factory()
        ->for($patient)
        ->for($procedureType)
        ->for($doctor)
        ->discharged()
        ->create([
            'name' => 'LSCS',
            'full_amount' => 80000,
            'created_by' => $user->id,
        ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('openApparentInvoice', $procedure->id)
        ->assertSet('showApparentInvoiceModal', true)
        ->assertCount('apparentInvoiceItems', 6)
        ->set('apparentInvoiceItems.0.amount', '75000')
        ->set('apparentInvoiceItems.1.amount', '25763')
        ->set('apparentInvoiceItems.2.amount', '21000')
        ->set('apparentInvoiceItems.3.amount', '6000')
        ->set('apparentInvoiceItems.4.amount', '5000')
        ->set('apparentInvoiceItems.5.amount', '5000')
        ->set('newApparentFeeName', 'Misc Charges')
        ->set('newApparentFeeAmount', '1000')
        ->call('addApparentInvoiceItem')
        ->assertCount('apparentInvoiceItems', 7)
        ->call('saveApparentInvoice', false)
        ->assertSet('showApparentInvoiceModal', false);

    $invoice = $procedure->fresh()->apparentInvoice;

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->total)->toBe(138763.0)
        ->and($invoice->items)->toHaveCount(7)
        ->and($invoice->items->last()->name)->toBe('Misc Charges');

    $this->actingAs($user)
        ->get(route('reception.procedures.apparent-invoice', $procedure))
        ->assertOk()
        ->assertSee(__('Payment Receipt'))
        ->assertSee('Saba Amir')
        ->assertSee('Amir Sohail')
        ->assertSee('Dr. Sadia Sohail')
        ->assertSee('LSCS')
        ->assertSee('Surgeon Fee')
        ->assertSee('Misc Charges')
        ->assertSee('138,763')
        ->assertSee('window.print()', false);
});

test('saving an apparent invoice again replaces previous fee lines', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->create(['created_by' => $user->id]);

    $invoice = ProcedureApparentInvoice::factory()->create([
        'procedure_id' => $procedure->id,
        'total' => 10000,
        'created_by' => $user->id,
    ]);

    ProcedureApparentInvoiceItem::factory()->create([
        'procedure_apparent_invoice_id' => $invoice->id,
        'name' => 'Old Fee',
        'amount' => 10000,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('openApparentInvoice', $procedure->id)
        ->assertSee('Old Fee')
        ->set('apparentInvoiceItems', [
            ['name' => 'Surgeon Fee', 'amount' => '50000'],
            ['name' => 'Anesthesia Fee', 'amount' => '6000'],
        ])
        ->call('saveApparentInvoice', false);

    $invoice->refresh();

    expect((float) $invoice->total)->toBe(56000.0)
        ->and($invoice->items()->pluck('name')->all())->toBe(['Surgeon Fee', 'Anesthesia Fee']);
});

test('the procedure view modal includes an apparent invoice button', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->create(['created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee(__('Apparent Invoice'));
});
