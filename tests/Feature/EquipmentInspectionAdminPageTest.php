<?php

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Enums\UserRole;
use App\Models\EquipmentInspectionEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected from admin equipment inspection submissions', function () {
    $this->get(route('admin.equipment-inspection-submissions'))->assertRedirect(route('login'));
});

test('admins can view equipment inspection submissions', function () {
    $admin = User::factory()->admin()->create();
    $nurse = User::factory()->inchargeNurse()->create();

    EquipmentInspectionEntry::factory()->create([
        'user_id' => $nurse->id,
        'area' => EquipmentInspectionArea::LabourRoom,
        'checklist_date' => now()->toDateString(),
        'shift' => EquipmentInspectionShift::Morning,
        'checked_by_name' => 'Nurse Aisha',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.equipment-inspection-submissions'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test('pages::admin.equipment-inspection-submissions')
        ->assertSee('Nurse Aisha')
        ->assertSee('Labour Room')
        ->assertSee('Morning');
});

test('non-admin users cannot view equipment inspection submissions', function () {
    $user = User::factory()->create(['role' => UserRole::Receptionist]);

    $this->actingAs($user)
        ->get(route('admin.equipment-inspection-submissions'))
        ->assertForbidden();
});
