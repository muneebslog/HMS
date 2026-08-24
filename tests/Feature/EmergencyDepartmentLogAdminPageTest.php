<?php

use App\Enums\EmergencyDepartmentShift;
use App\Enums\UserRole;
use App\Models\EmergencyDepartmentLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected from admin emergency department log submissions', function () {
    $this->get(route('admin.emergency-department-log-submissions'))->assertRedirect(route('login'));
});

test('admins can view emergency department log submissions', function () {
    $admin = User::factory()->admin()->create();
    $nurse = User::factory()->inchargeNurse()->create();

    EmergencyDepartmentLogEntry::factory()->create([
        'user_id' => $nurse->id,
        'checklist_date' => now()->toDateString(),
        'shift' => EmergencyDepartmentShift::Morning,
        'completed_by_name' => 'Nurse Aisha',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.emergency-department-log-submissions'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test('pages::admin.emergency-department-log-submissions')
        ->assertSee('Nurse Aisha')
        ->assertSee('Morning');
});

test('non-admin users cannot view emergency department log submissions', function () {
    $user = User::factory()->create(['role' => UserRole::Receptionist]);

    $this->actingAs($user)
        ->get(route('admin.emergency-department-log-submissions'))
        ->assertForbidden();
});
