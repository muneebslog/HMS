<?php

use App\Enums\ProcedureDocumentKind;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\Doctor;
use App\Models\Procedure;
use App\Models\ProcedureDocument;
use App\Models\ProcedureType;
use App\Models\ProcedureVital;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('doctor portal lists their assigned ward procedures', function () {
    $doctorUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($doctorUser)->create();
    $mine = Procedure::factory()->admitted()->create(['doctor_id' => $doctor->id]);
    Procedure::factory()->admitted()->create();

    $this->actingAs($doctorUser)
        ->get(route('doctor.procedures'))
        ->assertOk()
        ->assertSee($mine->patient->name);
});

test('discharge and birth certificates are tracked when opened', function () {
    $user = User::factory()->indoor()->create();
    $type = ProcedureType::factory()->delivery()->create();
    $procedure = Procedure::factory()->admitted()->for($type)->discharged()->create([
        'discharged_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('indoor.procedures.discharge-certificate', $procedure))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('indoor.procedures.birth-certificate', $procedure))
        ->assertOk();

    expect(ProcedureDocument::where('procedure_id', $procedure->id)->where('kind', ProcedureDocumentKind::DischargeCertificate)->exists())->toBeTrue()
        ->and(ProcedureDocument::where('procedure_id', $procedure->id)->where('kind', ProcedureDocumentKind::BirthCertificate)->exists())->toBeTrue();
});

test('missing vitals command notifies admins once per hour block', function () {
    User::factory()->admin()->create();
    $procedure = Procedure::factory()->admitted()->create([
        'admitted_at' => now()->subHours(3),
    ]);

    $this->artisan('procedures:check-missing-vitals')->assertSuccessful();

    expect(AdminNotification::where('type', 'procedure_vitals_missing')->count())->toBe(1);

    $this->artisan('procedures:check-missing-vitals')->assertSuccessful();

    expect(AdminNotification::where('type', 'procedure_vitals_missing')->count())->toBe(1);

    ProcedureVital::factory()->create([
        'procedure_id' => $procedure->id,
        'recorded_at' => now()->subHour()->startOfHour()->addMinutes(10),
    ]);
});

test('indoor role is requestable', function () {
    expect(UserRole::Indoor->label())->toBe(__('Indoor Staff'))
        ->and(User::factory()->indoor()->create()->isIndoor())->toBeTrue();
});
