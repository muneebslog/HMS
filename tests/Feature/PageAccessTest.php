<?php

use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use App\Services\PageAccessService;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('seeded defaults allow receptionists to access reception routes', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.walkin'))
        ->assertSuccessful();

    expect(app(PageAccessService::class)->canAccess($user, 'reception.walkin'))->toBeTrue();
});

test('seeded defaults block receptionists from admin routes', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.users'))
        ->assertForbidden();
});

test('admin bypasses page access checks', function () {
    $user = User::factory()->admin()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('admin.users'))
        ->assertSuccessful();

    expect(app(PageAccessService::class)->canAccess($user, 'admin.sql-runner'))->toBeTrue();
});

test('syncing permissions grants and revokes route access', function () {
    $user = User::factory()->management()->create();
    $service = app(PageAccessService::class);
    Shift::factory()->for($user)->open()->create();

    $managementRoutes = collect($service->manageableRoutesForRole(UserRole::Management))
        ->reject(fn (string $route) => $route === 'reception.invoices')
        ->values()
        ->all();

    $service->syncForRole(UserRole::Management, $managementRoutes);

    $this->actingAs($user)
        ->get(route('reception.invoices'))
        ->assertForbidden();

    $service->syncForRole(UserRole::Management, [...$managementRoutes, 'reception.invoices']);

    $this->actingAs($user)
        ->get(route('reception.invoices'))
        ->assertSuccessful();
});

test('cannot assign admin only routes to non admin roles via sync', function () {
    $service = app(PageAccessService::class);

    $service->syncForRole(UserRole::Management, [
        'admin.users',
        'admin.sql-runner',
        'admin.merge-duplicates',
        'reception.invoices',
    ]);

    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    expect($service->canAccess($user, 'admin.users'))->toBeFalse()
        ->and($service->canAccess($user, 'admin.sql-runner'))->toBeFalse()
        ->and($service->canAccess($user, 'reception.invoices'))->toBeTrue();
});

test('child routes inherit parent page access', function () {
    $user = User::factory()->doctor()->create();

    expect(app(PageAccessService::class)->canAccess($user, 'indoor.procedure'))->toBeTrue();
});

test('admin can manage page access via livewire', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.page-access')
        ->assertSuccessful()
        ->assertSee(__('Receptionist'))
        ->call('openRole', UserRole::Doctor->value)
        ->assertSet('showModal', true)
        ->call('save')
        ->assertHasNoErrors();
});

test('non admins cannot access page access admin page', function () {
    $user = User::factory()->management()->create();

    $this->actingAs($user)
        ->get(route('admin.page-access'))
        ->assertForbidden();
});

test('reset to defaults restores role permissions', function () {
    $service = app(PageAccessService::class);

    $service->syncForRole(UserRole::Doctor, []);

    expect($service->routesForRole(UserRole::Doctor))->toBe([]);

    $service->resetRoleToDefaults(UserRole::Doctor);

    expect($service->canAccess(User::factory()->doctor()->create(), 'doctor.portal'))->toBeTrue();
});

test('sidebar hides admin pages from receptionists', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(__('SQL Runner'))
        ->assertDontSee(__('Users'))
        ->assertDontSee(__('Checklist'))
        ->assertDontSee(__('Questionnaires'));
});
