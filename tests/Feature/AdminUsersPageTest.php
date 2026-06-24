<?php

use App\Enums\UserRole;
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
