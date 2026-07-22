<?php

use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.notifications'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the notifications page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.notifications'));

    $response->assertOk();
});

test('management can visit the notifications page', function () {
    $user = User::factory()->management()->create();

    $response = $this->actingAs($user)->get(route('admin.notifications'));

    $response->assertOk();
});

test('non-admin and non-management users cannot visit the notifications page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('admin.notifications'));

    $response->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'doctor' => [UserRole::Doctor],
]);

test('notifications page lists all notifications', function () {
    $admin = User::factory()->admin()->create();
    $read = AdminNotification::factory()->create(['read_at' => now()]);
    $unread = AdminNotification::factory()->create(['read_at' => null]);

    Livewire::actingAs($admin)
        ->test('pages::admin.notifications')
        ->assertSee($read->title)
        ->assertSee($unread->title);
});

test('notifications page can filter unread notifications', function () {
    $admin = User::factory()->admin()->create();
    $read = AdminNotification::factory()->create(['read_at' => now(), 'title' => 'Read Alert']);
    $unread = AdminNotification::factory()->create(['read_at' => null, 'title' => 'Unread Alert']);

    Livewire::actingAs($admin)
        ->test('pages::admin.notifications')
        ->call('setFilter', 'unread')
        ->assertSee($unread->title)
        ->assertDontSee($read->title);
});

test('notifications page can filter read notifications', function () {
    $admin = User::factory()->admin()->create();
    $read = AdminNotification::factory()->create(['read_at' => now(), 'title' => 'Read Alert']);
    $unread = AdminNotification::factory()->create(['read_at' => null, 'title' => 'Unread Alert']);

    Livewire::actingAs($admin)
        ->test('pages::admin.notifications')
        ->call('setFilter', 'read')
        ->assertSee($read->title)
        ->assertDontSee($unread->title);
});

test('admin can mark a notification as read', function () {
    $admin = User::factory()->admin()->create();
    $notification = AdminNotification::factory()->create(['read_at' => null]);

    Livewire::actingAs($admin)
        ->test('pages::admin.notifications')
        ->call('markAsRead', $notification->id)
        ->assertHasNoErrors();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('admin can mark all notifications as read', function () {
    $admin = User::factory()->admin()->create();
    AdminNotification::factory()->count(3)->create(['read_at' => null]);

    Livewire::actingAs($admin)
        ->test('pages::admin.notifications')
        ->call('markAllAsRead')
        ->assertHasNoErrors();

    expect(AdminNotification::whereNull('read_at')->count())->toBe(0);
});
