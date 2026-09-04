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
        ->assertDontSee(__('Apply discount'))
        ->call('openChangeProcedure', $procedure->id)
        ->assertSet('showChangeProcedureModal', true)
        ->assertSet('showViewModal', false)
        ->assertDontSee(__('Apply discount'))
        ->set('changeProcedureTypeId', $lscs->id)
        ->set('changePackagePrice', '45000')
        ->call('saveChangeProcedure')
        ->assertHasNoErrors()
        ->assertSet('showChangeProcedureModal', false)
        ->assertSet('showViewModal', true)
        ->assertDontSee(__('Change history'));

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

test('admins can see procedure change history', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->for($admin)->open()->create();
    $svd = ProcedureType::factory()->create(['name' => 'SVD']);
    $lscs = ProcedureType::factory()->create(['name' => 'LSCS']);
    $procedure = Procedure::factory()->for($shift)->create([
        'procedure_type_id' => $svd->id,
        'name' => 'SVD',
        'full_amount' => 25000,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::reception.procedures')
        ->call('openChangeProcedure', $procedure->id)
        ->set('changeProcedureTypeId', $lscs->id)
        ->set('changePackagePrice', '45000')
        ->call('saveChangeProcedure')
        ->assertHasNoErrors()
        ->assertSee(__('Change history'))
        ->assertSee('SVD → LSCS')
        ->assertSee('25,000.00')
        ->assertSee('45,000.00');
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
