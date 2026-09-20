<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('guests are redirected to the login page', function () {
    $this->get(route('extras'))->assertRedirect(route('login'));
});

test('admins can visit extras and see tool cards', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('extras'))
        ->assertSuccessful()
        ->assertSee(__('Extras'))
        ->assertSee(__('Lab API and Info'))
        ->assertSee(__('Management CRUD'))
        ->assertSee(__('Users'))
        ->assertSee(__('Staff Profiles'))
        ->assertSee(__('Health Aides'))
        ->assertSee(__('Admin Side'))
        ->assertSee(__('Dev Side'))
        ->assertSee(__('Stats'))
        ->assertSee(__('Page Access'))
        ->assertSee(__('Act as Role'))
        ->assertSee(__('Queue'));
});

test('management users see queue and lab api on extras but not admin-only cards', function () {
    $user = User::factory()->management()->create();

    $this->actingAs($user)
        ->get(route('extras'))
        ->assertSuccessful()
        ->assertSee(__('Queue'))
        ->assertSee(__('Lab API and Info'))
        ->assertDontSee(__('Page Access'))
        ->assertDontSee(__('Act as Role'));
});

test('receptionists with lab access can visit extras', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('extras'))
        ->assertSuccessful()
        ->assertSee(__('Lab API and Info'))
        ->assertDontSee(__('Page Access'))
        ->assertDontSee(__('Act as Role'));
});

test('doctors without extras access cannot visit extras', function () {
    $user = User::factory()->doctor()->create();

    $this->actingAs($user)
        ->get(route('extras'))
        ->assertForbidden();
});

test('extras appears in the sidebar instead of administration items', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Extras'))
        ->assertDontSee(__('Management CRUD'))
        ->assertDontSee(__('Dev Side'))
        ->assertDontSee(__('Admin Side'));
});

test('queue is not listed under management in the sidebar', function () {
    $user = User::factory()->management()->create();

    $html = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Extras'))
        ->getContent();

    expect(str_contains($html, 'href="'.route('reception.queue').'"'))->toBeFalse();
});

test('admins can open extras hub pages', function (string $route) {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route($route))
        ->assertSuccessful();
})->with([
    'admin-side' => ['extras.admin-side'],
    'dev-side' => ['extras.dev-side'],
    'stats' => ['extras.stats'],
]);

test('non-admins cannot open extras hub pages', function (string $route, UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route($route))
        ->assertForbidden();
})->with([
    'admin-side receptionist' => ['extras.admin-side', UserRole::Receptionist],
    'dev-side management' => ['extras.dev-side', UserRole::Management],
    'stats doctor' => ['extras.stats', UserRole::Doctor],
]);
