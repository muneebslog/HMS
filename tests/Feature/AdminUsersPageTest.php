<?php

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.users'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the users page', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->get(route('admin.users'));

    $response->assertOk();
});

test('non-admins cannot visit the users page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('admin.users'));

    $response->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
]);

test('users with the default user role are redirected to the pending role page', function () {
    $user = User::factory()->user()->create();

    $response = $this->actingAs($user)->get(route('admin.users'));

    $response->assertRedirect(route('pending-role'));
});

test('admin can change another users role', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $user->id)
        ->assertSet('editingUserId', $user->id)
        ->set('editingRole', UserRole::Management->value)
        ->call('saveRole', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh()->role)->toBe(UserRole::Management);
});

test('admin can assign doctor role and link a doctor profile', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $user->id)
        ->set('editingRole', UserRole::Doctor->value)
        ->set('editingDoctorId', $doctor->id)
        ->call('saveRole', $user->id)
        ->assertHasNoErrors();

    $user->refresh();
    $doctor->refresh();

    expect($user->role)->toBe(UserRole::Doctor)
        ->and($doctor->user_id)->toBe($user->id);
});

test('admin cannot assign doctor role without selecting a doctor profile', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $user->id)
        ->set('editingRole', UserRole::Doctor->value)
        ->call('saveRole', $user->id)
        ->assertHasErrors(['editingDoctorId']);

    expect($user->fresh()->role)->toBe(UserRole::User);
});

test('admin cannot link a doctor profile already linked to another user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $otherUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($otherUser)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $user->id)
        ->set('editingRole', UserRole::Doctor->value)
        ->set('editingDoctorId', $doctor->id)
        ->call('saveRole', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh()->role)->toBe(UserRole::User)
        ->and($doctor->fresh()->user_id)->toBe($otherUser->id);
});

test('changing role away from doctor unlinks the doctor profile', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($user)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $user->id)
        ->set('editingRole', UserRole::Receptionist->value)
        ->call('saveRole', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh()->role)->toBe(UserRole::Receptionist)
        ->and($doctor->fresh()->user_id)->toBeNull();
});

test('admin cannot set an invalid role', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $user->id)
        ->set('editingRole', 'invalid')
        ->call('saveRole', $user->id)
        ->assertHasErrors(['editingRole']);

    expect($user->fresh()->role)->toBe(UserRole::Receptionist);
});

test('users page lists all users', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertSee($admin->name)
        ->assertSee($receptionist->name)
        ->assertSee($management->name)
        ->assertSee($admin->roleLabel())
        ->assertSee($receptionist->roleLabel())
        ->assertSee($management->roleLabel());
});
