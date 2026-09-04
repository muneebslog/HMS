<?php

use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a procedure can be marked paid with the remaining balance and no shift', function () {
    $user = User::factory()->create();
    $openShift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->create(['full_amount' => 5000]);

    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 2000,
        'shift_id' => $openShift->id,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('markPaid', $procedure->id)
        ->assertHasNoErrors();

    $procedure->refresh();

    expect($procedure->payments)->toHaveCount(2)
        ->and($procedure->isPaid())->toBeTrue()
        ->and($procedure->balance())->toBe(0.0);

    $settlement = $procedure->payments()->latest('id')->first();

    expect($settlement)->not->toBeNull()
        ->amount->toBe(3000.0)
        ->shift_id->toBeNull()
        ->and($openShift->fresh()->totalProcedureSales())->toBe(2000.0);
});

test('mark paid does nothing when the procedure is already fully paid', function () {
    $user = User::factory()->create();
    $procedure = Procedure::factory()->create(['full_amount' => 1000]);

    ProcedurePayment::factory()->for($procedure)->create([
        'amount' => 1000,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('markPaid', $procedure->id)
        ->assertHasNoErrors();

    expect($procedure->fresh()->payments)->toHaveCount(1);
});

test('admin can delete a procedure and its payments', function () {
    $admin = User::factory()->admin()->create();
    $procedure = Procedure::factory()->create(['full_amount' => 5000]);

    ProcedurePayment::factory()->for($procedure)->count(2)->create([
        'created_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSeeHtml('wire:click="deleteProcedure('.$procedure->id.')"')
        ->call('deleteProcedure', $procedure->id)
        ->assertHasNoErrors()
        ->assertSet('showViewModal', false);

    expect(Procedure::find($procedure->id))->toBeNull()
        ->and(ProcedurePayment::where('procedure_id', $procedure->id)->count())->toBe(0);
});

test('non-admins cannot delete a procedure', function () {
    $user = User::factory()->receptionist()->create();
    $procedure = Procedure::factory()->create(['full_amount' => 5000]);

    ProcedurePayment::factory()->for($procedure)->count(2)->create([
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertDontSeeHtml('wire:click="deleteProcedure('.$procedure->id.')"')
        ->call('deleteProcedure', $procedure->id)
        ->assertForbidden();

    expect(Procedure::find($procedure->id))->not->toBeNull()
        ->and(ProcedurePayment::where('procedure_id', $procedure->id)->count())->toBe(2);
});
