<?php

use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        'reception.procedures',
        'payout.daily',
    ],
    'shared' => [
        'reception.shift',
        'dashboard',
    ],
];

test('admins can access all protected routes', function () use ($routeMap) {
    $user = User::factory()->admin()->create();
    $shiftRoutes = [
        'reception.walkin',
        'reception.reservation',
        'reception.lab-entry',
        'reception.procedures',
        'reception.invoices',
        'reception.queue',
    ];
    Shift::factory()->for($user)->open()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['shared']) as $route) {
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

test('unauthenticated users are redirected to login', function () use ($routeMap) {
    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['shared']) as $route) {
        $this->get(route($route))
            ->assertRedirect(route('login'));
    }
});

test('users with the default user role are redirected to the pending role page', function () use ($routeMap) {
    $user = User::factory()->user()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['shared']) as $route) {
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

test('assigned users are redirected away from the pending role page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route('pending-role'))
        ->assertRedirect(route('dashboard'));
})->with([
    'admin' => [UserRole::Admin],
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
]);
