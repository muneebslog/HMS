<?php

use App\Enums\UserRole;
use App\Enums\WardMaintenanceShift;
use App\Models\User;
use App\Models\WardMaintenanceEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected from admin ward maintenance submissions', function () {
    $this->get(route('admin.ward-maintenance-submissions'))->assertRedirect(route('login'));
});

test('admins can view ward maintenance submissions', function () {
    $admin = User::factory()->admin()->create();
    $nurse = User::factory()->inchargeNurse()->create();

    WardMaintenanceEntry::factory()->create([
        'user_id' => $nurse->id,
        'checklist_date' => now()->toDateString(),
        'shift' => WardMaintenanceShift::Morning,
        'checked_by_name' => 'Nurse Aisha',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ward-maintenance-submissions'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test('pages::admin.ward-maintenance-submissions')
        ->assertSee('Nurse Aisha')
        ->assertSee('Morning');
});

test('non-admin users cannot view ward maintenance submissions', function () {
    $user = User::factory()->create(['role' => UserRole::Receptionist]);

    $this->actingAs($user)
        ->get(route('admin.ward-maintenance-submissions'))
        ->assertForbidden();
});
