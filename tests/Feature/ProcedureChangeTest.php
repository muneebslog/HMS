<?php

use App\Models\Procedure;
use App\Models\ProcedureChange;
use App\Models\ProcedurePayment;
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

test('changing a procedure updates type and package and records history', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $svd = ProcedureType::factory()->create(['name' => 'SVD']);
    $lscs = ProcedureType::factory()->create(['name' => 'LSCS']);
    $procedure = Procedure::factory()->for($shift)->create([
        'procedure_type_id' => $svd->id,
        'name' => 'SVD',
        'full_amount' => 25000,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee(__('Change procedure'))
        ->call('openChangeProcedure', $procedure->id)
        ->assertSet('showChangeProcedureModal', true)
        ->assertSet('showViewModal', false)
        ->set('changeProcedureTypeId', $lscs->id)
        ->set('changePackagePrice', '45000')
        ->call('saveChangeProcedure')
        ->assertHasNoErrors()
        ->assertSet('showChangeProcedureModal', false)
        ->assertSet('showViewModal', true)
        ->assertSee('SVD → LSCS')
        ->assertSee(__('Change history'));

    $procedure->refresh();

    expect($procedure->procedure_type_id)->toBe($lscs->id)
        ->and($procedure->name)->toBe('LSCS')
        ->and($procedure->full_amount)->toBe(45000.0);

    $change = ProcedureChange::query()->where('procedure_id', $procedure->id)->first();

    expect($change)->not->toBeNull()
        ->and($change->from_procedure_type_id)->toBe($svd->id)
        ->and($change->to_procedure_type_id)->toBe($lscs->id)
        ->and($change->from_name)->toBe('SVD')
        ->and($change->to_name)->toBe('LSCS')
        ->and($change->from_amount)->toBe(25000.0)
        ->and($change->to_amount)->toBe(45000.0)
        ->and($change->package_price)->toBe(45000.0)
        ->and($change->discount_amount)->toBe(0.0)
        ->and($change->changed_by)->toBe($user->id);
});

test('changing a procedure can apply a discount to the package price', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $svd = ProcedureType::factory()->create(['name' => 'SVD']);
    $lscs = ProcedureType::factory()->create(['name' => 'LSCS']);
    $procedure = Procedure::factory()->for($shift)->create([
        'procedure_type_id' => $svd->id,
        'name' => 'SVD',
        'full_amount' => 25000,
    ]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 10000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('openChangeProcedure', $procedure->id)
        ->set('changeProcedureTypeId', $lscs->id)
        ->set('changePackagePrice', '45000')
        ->set('changeHasDiscount', true)
        ->set('changeDiscountAmount', '5000')
        ->call('saveChangeProcedure')
        ->assertHasNoErrors()
        ->assertSee(__('Discount').': 5,000.00');

    $procedure->refresh();

    expect($procedure->full_amount)->toBe(40000.0)
        ->and($procedure->balance())->toBe(30000.0);

    $change = ProcedureChange::query()->where('procedure_id', $procedure->id)->first();

    expect($change)->not->toBeNull()
        ->and($change->package_price)->toBe(45000.0)
        ->and($change->discount_amount)->toBe(5000.0)
        ->and($change->to_amount)->toBe(40000.0);
});

test('changed package cannot go below total paid', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $svd = ProcedureType::factory()->create(['name' => 'SVD']);
    $lscs = ProcedureType::factory()->create(['name' => 'LSCS']);
    $procedure = Procedure::factory()->for($shift)->create([
        'procedure_type_id' => $svd->id,
        'name' => 'SVD',
        'full_amount' => 25000,
    ]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 20000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('openChangeProcedure', $procedure->id)
        ->set('changeProcedureTypeId', $lscs->id)
        ->set('changePackagePrice', '15000')
        ->call('saveChangeProcedure')
        ->assertHasErrors(['changePackagePrice'])
        ->assertSet('showChangeProcedureModal', true);

    $procedure->refresh();

    expect($procedure->full_amount)->toBe(25000.0)
        ->and($procedure->procedure_type_id)->toBe($svd->id)
        ->and(ProcedureChange::query()->where('procedure_id', $procedure->id)->count())->toBe(0);
});

test('discount that reduces package below total paid is rejected', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $type = ProcedureType::factory()->create(['name' => 'LSCS']);
    $procedure = Procedure::factory()->for($shift)->create([
        'procedure_type_id' => $type->id,
        'name' => 'LSCS',
        'full_amount' => 45000,
    ]);
    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 30000,
        'shift_id' => $shift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('openChangeProcedure', $procedure->id)
        ->set('changeProcedureTypeId', $type->id)
        ->set('changePackagePrice', '45000')
        ->set('changeHasDiscount', true)
        ->set('changeDiscountAmount', '20000')
        ->call('saveChangeProcedure')
        ->assertHasErrors(['changePackagePrice']);

    expect($procedure->fresh()->full_amount)->toBe(45000.0)
        ->and(ProcedureChange::count())->toBe(0);
});
