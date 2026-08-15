<?php

use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

$routeMap = [
    'admin' => [
        'management.crud',
        'admin.users',
    ],
    'management' => [
        'reception.invoices',
        'reception.queue',
        'payout.doctor',
    ],
    'receptionist' => [
        'reception.walkin',
        'reception.reservation',
        'reception.lab-entry',
        'reception.vitals',
        'reception.procedures',
        'reception.rooms',
        'payout.daily',
        'supervisor.checklist',
    ],
    'doctor' => [
        'doctor.portal',
    ],
    'shared' => [
        'reception.shift',
        'dashboard',
        'lab-entries',
    ],
];

test('admins can access all protected routes', function () use ($routeMap) {
    $user = User::factory()->admin()->create();
    $shiftRoutes = [
        'reception.walkin',
        'reception.reservation',
        'reception.lab-entry',
        'reception.vitals',
        'reception.procedures',
        'reception.rooms',
        'reception.invoices',
        'reception.queue',
    ];
    Shift::factory()->for($user)->open()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['doctor'], $routeMap['shared']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertSuccessful();
    }
});

test('receptionists are blocked from admin and management routes', function () use ($routeMap) {
    $user = User::factory()->receptionist()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertForbidden();
    }
});

test('receptionists can access their own routes', function () use ($routeMap) {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    foreach (array_merge($routeMap['receptionist'], $routeMap['shared']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertSuccessful();
    }
});

test('management can access their own routes', function () use ($routeMap) {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    foreach (array_merge($routeMap['management'], $routeMap['shared']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertSuccessful();
    }
});

test('management is blocked from admin and receptionist routes', function () use ($routeMap) {
    $user = User::factory()->management()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['receptionist']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertForbidden();
    }
});

test('doctors can access their own routes', function () use ($routeMap) {
    $user = User::factory()->doctor()->create();

    foreach ($routeMap['doctor'] as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertSuccessful();
    }
});

test('doctors are redirected from dashboard to doctor portal', function () {
    $user = User::factory()->doctor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('doctor.portal'));
});

test('doctors are blocked from admin, management and receptionist routes', function () use ($routeMap) {
    $user = User::factory()->doctor()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertForbidden();
    }
});

test('unauthenticated users are redirected to login', function () use ($routeMap) {
    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['doctor'], $routeMap['shared']) as $route) {
        $this->get(route($route))
            ->assertRedirect(route('login'));
    }
});

test('users with the default user role are redirected to the pending role page', function () use ($routeMap) {
    $user = User::factory()->user()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['doctor'], $routeMap['shared']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertRedirect(route('pending-role'));
    }
});

test('users with the default user role can access the pending role page', function () {
    $user = User::factory()->user()->create();

    $this->actingAs($user)
        ->get(route('pending-role'))
        ->assertSuccessful();
});

test('assigned users are redirected away from the pending role page', function (string $factory) {
    $user = User::factory()->{$factory}()->create();

    $this->actingAs($user)
        ->get(route('pending-role'))
        ->assertRedirect(route('dashboard'));
})->with([
    'admin' => ['admin'],
    'receptionist' => ['receptionist'],
    'management' => ['management'],
    'doctor' => ['doctor'],
    'indoor' => ['indoor'],
    'incharge nurse' => ['inchargeNurse'],
]);

test('incharge nurse role is requestable and identifiable', function () {
    expect(UserRole::InchargeNurse->label())->toBe(__('Incharge Nurse'))
        ->and(UserRole::InchargeNurse->value)->toBe('incharge_nurse')
        ->and(User::factory()->inchargeNurse()->create()->isInchargeNurse())->toBeTrue();

    $user = User::factory()->user()->create();

    Livewire::actingAs($user)
        ->test('pages::pending-role')
        ->call('requestRole')
        ->set('requestedRole', UserRole::InchargeNurse->value)
        ->call('submitRequest')
        ->assertHasNoErrors();
});
