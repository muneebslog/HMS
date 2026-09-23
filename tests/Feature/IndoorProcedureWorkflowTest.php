<?php

use App\Enums\ProcedureDocumentKind;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\Doctor;
use App\Models\Procedure;
use App\Models\ProcedureDocument;
use App\Models\ProcedureType;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

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

test('there is no overdue ward readings check now that readings cannot be recorded', function () {
    $receptionist = User::factory()->receptionist()->create();
    Procedure::factory()->admitted()->create(['admitted_at' => now()->subHours(3)]);

    $this->actingAs($receptionist)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Overdue ward readings');

    expect(array_keys(Artisan::all()))->not->toContain('procedures:check-missing-vitals')
        ->and(collect(app(Schedule::class)->events())->map->command->implode(' '))->not->toContain('check-missing-vitals');
});

test('the cleanup migration deletes old missing readings notifications only', function () {
    $actor = User::factory()->admin()->create();
    $make = fn (string $type) => AdminNotification::create([
        'user_id' => $actor->id, 'type' => $type, 'title' => $type, 'message' => $type, 'actionable_url' => '/', 'metadata' => [],
    ]);
    $make('procedure_vitals_missing');
    $make('procedure_vitals_missing');
    $kept = $make('checklist_missing');

    $migration = require database_path('migrations/2026_09_24_014203_delete_procedure_vitals_missing_notifications.php');
    $migration->up();

    expect(AdminNotification::where('type', 'procedure_vitals_missing')->count())->toBe(0)
        ->and(AdminNotification::whereKey($kept->id)->exists())->toBeTrue();
});

test('indoor role is requestable', function () {
    expect(UserRole::Indoor->label())->toBe(__('Indoor Staff'))
        ->and(User::factory()->indoor()->create()->isIndoor())->toBeTrue();
});
