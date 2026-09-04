<?php

use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use App\Services\PageAccessService;
use App\Services\RoleActingService;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('non admins cannot visit the act as role page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.act-as-role'))
        ->assertForbidden();
});

test('non admins cannot start acting as a role via livewire', function () {
    $user = User::factory()->management()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.act-as-role')
        ->assertForbidden();
});

test('admin can start acting as receptionist and loses admin only page access', function () {
    $admin = User::factory()->admin()->create();
    Shift::factory()->for($admin)->open()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.act-as-role')
        ->call('startActing', UserRole::Receptionist->value)
        ->assertRedirect(route('dashboard'));

    expect(app(RoleActingService::class)->isActing())->toBeTrue()
        ->and(app(RoleActingService::class)->current())->toBe(UserRole::Receptionist)
        ->and($admin->fresh()->isAdmin())->toBeFalse()
        ->and($admin->fresh()->isReceptionist())->toBeTrue()
        ->and($admin->fresh()->isActuallyAdmin())->toBeTrue()
        ->and(app(PageAccessService::class)->canAccess($admin->fresh(), 'admin.users'))->toBeFalse()
        ->and(app(PageAccessService::class)->canAccess($admin->fresh(), 'reception.walkin'))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('reception.walkin'))
        ->assertSuccessful();
});

test('admin acting as receptionist is blocked from page access editor', function () {
    $admin = User::factory()->admin()->create();

    app(RoleActingService::class)->start($admin, UserRole::Receptionist);

    $this->actingAs($admin)
        ->get(route('admin.page-access'))
        ->assertForbidden();
});

test('admin can still open act as role while acting and can stop', function () {
    $admin = User::factory()->admin()->create();

    app(RoleActingService::class)->start($admin, UserRole::Management);

    $this->actingAs($admin)
        ->get(route('admin.act-as-role'))
        ->assertSuccessful();

    Livewire::actingAs($admin)
        ->test('pages::admin.act-as-role')
        ->assertSee(__('Currently acting as :role', ['role' => UserRole::Management->label()]))
        ->call('stopActing')
        ->assertSuccessful();

    expect(app(RoleActingService::class)->isActing())->toBeFalse()
        ->and($admin->fresh()->isAdmin())->toBeTrue()
        ->and(app(PageAccessService::class)->canAccess($admin->fresh(), 'admin.users'))->toBeTrue();
});

test('banner can stop acting as a role', function () {
    $admin = User::factory()->admin()->create();

    app(RoleActingService::class)->start($admin, UserRole::Doctor);

    Livewire::actingAs($admin)
        ->test('role-acting-banner')
        ->assertSee(__('Acting as :role — pages and navigation match that role.', [
            'role' => UserRole::Doctor->label(),
        ]))
        ->call('stopActing')
        ->assertRedirect(route('admin.act-as-role'));

    expect(app(RoleActingService::class)->isActing())->toBeFalse();
});

test('stopping restores access to admin only routes', function () {
    $admin = User::factory()->admin()->create();
    Shift::factory()->for($admin)->open()->create();

    app(RoleActingService::class)->start($admin, UserRole::Indoor);

    $this->actingAs($admin)
        ->get(route('admin.sql-runner'))
        ->assertForbidden();

    app(RoleActingService::class)->stop();

    $this->actingAs($admin)
        ->get(route('admin.sql-runner'))
        ->assertSuccessful();
});

test('cannot act as admin or unassigned user role', function () {
    $admin = User::factory()->admin()->create();
    $service = app(RoleActingService::class);

    expect(fn () => $service->start($admin, UserRole::Admin))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->start($admin, UserRole::User))
        ->toThrow(InvalidArgumentException::class);
});

test('admin can act as lab technician', function () {
    $admin = User::factory()->admin()->create();
    $service = app(RoleActingService::class);

    $service->start($admin, UserRole::LabTechnician);

    expect($service->current())->toBe(UserRole::LabTechnician)
        ->and($admin->isLabTechnician())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('lab-entries'))
        ->assertSuccessful();
});
