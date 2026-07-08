<?php

use App\Enums\RoleRequestStatus;
use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\RoleRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('user role users can request a role', function () {
    $user = User::factory()->user()->create();

    Livewire::actingAs($user)
        ->test('pages::pending-role')
        ->call('requestRole')
        ->assertSet('showRequestModal', true)
        ->set('requestedRole', UserRole::Receptionist->value)
        ->set('message', 'I need reception access.')
        ->call('submitRequest')
        ->assertHasNoErrors();

    $request = RoleRequest::first();

    expect($request)->not->toBeNull()
        ->and($request->user_id)->toBe($user->id)
        ->and($request->requested_role)->toBe(UserRole::Receptionist)
        ->and($request->status)->toBe(RoleRequestStatus::Pending)
        ->and($request->message)->toBe('I need reception access.');
});

test('user role users can request the doctor role', function () {
    $user = User::factory()->user()->create();

    Livewire::actingAs($user)
        ->test('pages::pending-role')
        ->call('requestRole')
        ->assertSet('showRequestModal', true)
        ->set('requestedRole', UserRole::Doctor->value)
        ->set('message', 'I am a doctor.')
        ->call('submitRequest')
        ->assertHasNoErrors();

    $request = RoleRequest::first();

    expect($request)->not->toBeNull()
        ->and($request->user_id)->toBe($user->id)
        ->and($request->requested_role)->toBe(UserRole::Doctor)
        ->and($request->status)->toBe(RoleRequestStatus::Pending);
});

test('user role users cannot request the admin or user role', function () {
    $user = User::factory()->user()->create();

    Livewire::actingAs($user)
        ->test('pages::pending-role')
        ->call('requestRole')
        ->set('requestedRole', UserRole::Admin->value)
        ->call('submitRequest')
        ->assertHasErrors(['requestedRole']);

    expect(RoleRequest::count())->toBe(0);
});

test('user role users cannot submit multiple pending requests', function () {
    $user = User::factory()->user()->create();

    RoleRequest::factory()->for($user)->pending()->create();

    Livewire::actingAs($user)
        ->test('pages::pending-role')
        ->call('requestRole')
        ->assertSet('showRequestModal', false);

    expect(RoleRequest::count())->toBe(1);
});

test('pending request is shown on the pending role page', function () {
    $user = User::factory()->user()->create();
    $request = RoleRequest::factory()->for($user)->pending()->create([
        'requested_role' => UserRole::Management,
    ]);

    Livewire::actingAs($user)
        ->test('pages::pending-role')
        ->assertSee($request->requested_role->label());
});

test('admins see pending role requests on the users page', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $request = RoleRequest::factory()->for($user)->pending()->create([
        'requested_role' => UserRole::Receptionist,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertSee($user->name)
        ->assertSee($user->email)
        ->assertSee($request->requested_role->label());
});

test('admins can approve a role request', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $request = RoleRequest::factory()->for($user)->pending()->create([
        'requested_role' => UserRole::Receptionist,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $request->id)
        ->assertSet('showRequestModal', true)
        ->set('adminNotes', 'Approved.')
        ->call('approveRequest')
        ->assertHasNoErrors();

    $request->refresh();
    $user->refresh();

    expect($request->status)->toBe(RoleRequestStatus::Approved)
        ->and($request->processed_by)->toBe($admin->id)
        ->and($user->role)->toBe(UserRole::Receptionist);
});

test('admins can approve a doctor role request and link a doctor profile', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $doctor = Doctor::factory()->create();
    $request = RoleRequest::factory()->for($user)->pending()->create([
        'requested_role' => UserRole::Doctor,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $request->id)
        ->assertSet('showRequestModal', true)
        ->set('requestDoctorId', $doctor->id)
        ->set('adminNotes', 'Welcome doctor.')
        ->call('approveRequest')
        ->assertHasNoErrors();

    $request->refresh();
    $user->refresh();
    $doctor->refresh();

    expect($request->status)->toBe(RoleRequestStatus::Approved)
        ->and($user->role)->toBe(UserRole::Doctor)
        ->and($doctor->user_id)->toBe($user->id);
});

test('admins cannot approve a doctor request without selecting a doctor profile', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $request = RoleRequest::factory()->for($user)->pending()->create([
        'requested_role' => UserRole::Doctor,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $request->id)
        ->set('requestDoctorId', '')
        ->call('approveRequest')
        ->assertHasErrors(['requestDoctorId']);

    expect($user->fresh()->role)->toBe(UserRole::User);
});

test('admins cannot approve a doctor request with an already linked doctor profile', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $otherUser = User::factory()->doctor()->create();
    $doctor = Doctor::factory()->forUser($otherUser)->create();
    $request = RoleRequest::factory()->for($user)->pending()->create([
        'requested_role' => UserRole::Doctor,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $request->id)
        ->set('requestDoctorId', $doctor->id)
        ->call('approveRequest')
        ->assertHasNoErrors();

    expect($user->fresh()->role)->toBe(UserRole::User)
        ->and($doctor->fresh()->user_id)->toBe($otherUser->id);
});

test('admins can reject a role request', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->user()->create();
    $request = RoleRequest::factory()->for($user)->pending()->create([
        'requested_role' => UserRole::Management,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $request->id)
        ->set('adminNotes', 'Not needed.')
        ->call('rejectRequest')
        ->assertHasNoErrors();

    $request->refresh();
    $user->refresh();

    expect($request->status)->toBe(RoleRequestStatus::Rejected)
        ->and($request->processed_by)->toBe($admin->id)
        ->and($user->role)->toBe(UserRole::User);
});

test('admins cannot demote themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $admin->id)
        ->set('editingRole', UserRole::Receptionist->value)
        ->call('saveRole', $admin->id);

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('admins cannot remove the last admin', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $otherAdmin->id)
        ->set('editingRole', UserRole::Management->value)
        ->call('saveRole', $otherAdmin->id)
        ->assertHasNoErrors();

    expect($otherAdmin->fresh()->role)->toBe(UserRole::Management);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('editRole', $admin->id)
        ->set('editingRole', UserRole::Management->value)
        ->call('saveRole', $admin->id);

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('admins cannot approve a request that would demote themselves', function () {
    $admin = User::factory()->admin()->create();
    $request = RoleRequest::factory()->for($admin)->pending()->create([
        'requested_role' => UserRole::Receptionist,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $request->id)
        ->set('adminNotes', 'Approved.')
        ->call('approveRequest')
        ->assertHasNoErrors();

    expect($admin->fresh()->role)->toBe(UserRole::Admin)
        ->and($request->fresh()->status)->toBe(RoleRequestStatus::Pending);
});

test('admins cannot approve a request that would remove the last admin', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $request = RoleRequest::factory()->for($otherAdmin)->pending()->create([
        'requested_role' => UserRole::Management,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $request->id)
        ->set('adminNotes', 'Approved.')
        ->call('approveRequest')
        ->assertHasNoErrors();

    expect($otherAdmin->fresh()->role)->toBe(UserRole::Management);

    $selfRequest = RoleRequest::factory()->for($admin)->pending()->create([
        'requested_role' => UserRole::Receptionist,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('processRequest', $selfRequest->id)
        ->set('adminNotes', 'Approved.')
        ->call('approveRequest')
        ->assertHasNoErrors();

    expect($admin->fresh()->role)->toBe(UserRole::Admin)
        ->and($selfRequest->fresh()->status)->toBe(RoleRequestStatus::Pending);
});
