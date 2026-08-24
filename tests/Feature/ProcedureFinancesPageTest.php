<?php

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedurePayment;
use App\Models\ProcedureType;
use App\Models\User;
use App\Services\ProcedureFinanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admins can visit the procedure finances page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.procedure-finances'))
        ->assertSuccessful()
        ->assertSee(__('Procedure Finances'));
});

test('non-admin users cannot visit the procedure finances page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route('admin.procedure-finances'))
        ->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
]);

test('procedure finance report totals billed collected and outstanding per procedure type in the date range', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create(['name' => 'Ayesha Khan']);
    $lscs = ProcedureType::factory()->lscs()->create(['name' => 'LSCS']);
    $nvd = ProcedureType::factory()->delivery()->create(['name' => 'NVD']);

    $lscsCase = Procedure::factory()->for($patient)->for($lscs, 'procedureType')->create([
        'name' => 'LSCS',
        'full_amount' => 50000,
        'created_by' => $admin->id,
        'created_at' => Carbon::parse('2026-08-10 09:00:00'),
    ]);

    ProcedurePayment::factory()->create([
        'procedure_id' => $lscsCase->id,
        'created_by' => $admin->id,
        'amount' => 20000,
        'created_at' => Carbon::parse('2026-08-10 10:00:00'),
    ]);

    ProcedurePayment::factory()->create([
        'procedure_id' => $lscsCase->id,
        'created_by' => $admin->id,
        'amount' => 10000,
        'created_at' => Carbon::parse('2026-08-11 11:00:00'),
    ]);

    Procedure::factory()->for($patient)->for($nvd, 'procedureType')->create([
        'name' => 'NVD',
        'full_amount' => 25000,
        'created_by' => $admin->id,
        'created_at' => Carbon::parse('2026-08-12 14:00:00'),
    ]);

    Procedure::factory()->for($patient)->for($lscs, 'procedureType')->create([
        'name' => 'LSCS',
        'full_amount' => 40000,
        'created_by' => $admin->id,
        'created_at' => Carbon::parse('2026-07-31 23:00:00'),
    ]);

    $report = app(ProcedureFinanceReportService::class)->forDateRange(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-08-31'),
    );

    expect($report['cases'])->toBe(2)
        ->and($report['billed'])->toBe(75000.0)
        ->and($report['collected'])->toBe(30000.0)
        ->and($report['outstanding'])->toBe(45000.0);

    $byType = $report['by_type']->keyBy('name');

    expect($byType['LSCS'])
        ->cases->toBe(1)
        ->billed->toBe(50000.0)
        ->collected->toBe(30000.0)
        ->outstanding->toBe(20000.0)
        ->and($byType['NVD'])
        ->cases->toBe(1)
        ->billed->toBe(25000.0)
        ->collected->toBe(0.0)
        ->outstanding->toBe(25000.0);

    Livewire::actingAs($admin)
        ->test('pages::admin.procedure-finances')
        ->set('dateFrom', '2026-08-01')
        ->set('dateTo', '2026-08-31')
        ->assertSee('LSCS')
        ->assertSee('NVD')
        ->assertSee('Ayesha Khan')
        ->assertSee(number_format(50000, 2))
        ->assertSee(number_format(30000, 2))
        ->assertSee(number_format(25000, 2));
});

test('procedure finances page rejects an inverted date range', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.procedure-finances')
        ->set('dateFrom', '2026-08-12')
        ->set('dateTo', '2026-08-10')
        ->assertSee(__('Enter a valid date range. The start date must not be after the end date.'));
});
