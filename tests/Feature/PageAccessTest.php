<?php

use App\Enums\UserRole;
use App\Models\Procedure;
use App\Models\ProcedureBirthCertificateDetail;
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
    $user = User::factory()->receptionist()->create();

    expect(app(PageAccessService::class)->canAccess($user, 'reception.procedures.file'))->toBeTrue();
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

test('birth certificate is accessible to every assigned logged in role', function (string $roleFactory) {
    $user = User::factory()->{$roleFactory}()->create();
    $procedure = Procedure::factory()->create();
    ProcedureBirthCertificateDetail::factory()->create([
        'procedure_id' => $procedure->id,
    ]);

    expect(app(PageAccessService::class)->canAccess($user, 'indoor.procedures.birth-certificate'))->toBeTrue();

    $this->actingAs($user)
        ->get(route('indoor.procedures.birth-certificate', $procedure))
        ->assertSuccessful();
})->with([
    'admin' => 'admin',
    'receptionist' => 'receptionist',
    'management' => 'management',
    'doctor' => 'doctor',
    'indoor' => 'indoor',
    'incharge nurse' => 'inchargeNurse',
    'lab technician' => 'labTechnician',
]);

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

test('sidebar hides doctor pages from admins', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee(__('Doctor Portal'))
        ->assertDontSee(__('My Procedures'))
        ->assertDontSee('href="'.route('doctor.medication', absolute: false).'"', false)
        ->assertDontSee('href="'.route('doctor.portal', absolute: false).'"', false);
});

test('mr lookup appears under management in the sidebar', function () {
    $user = User::factory()->management()->create();

    $html = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('MR Lookup'))
        ->assertDontSee(__('Lab API and Info'))
        ->getContent();

    $managementPos = strpos($html, __('Management'));
    $mrLookupPos = strpos($html, __('MR Lookup'));
    $extrasPos = strpos($html, __('Extras'));

    expect($managementPos)->not->toBeFalse()
        ->and($mrLookupPos)->toBeGreaterThan($managementPos);

    if ($extrasPos !== false) {
        expect($mrLookupPos)->toBeLessThan($extrasPos);
    }
});

test('lab api and info is reachable from extras instead of the sidebar', function () {
    $user = User::factory()->management()->create();

    $html = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Extras'))
        ->getContent();

    expect(str_contains($html, 'href="'.route('lab-api-and-info').'"'))->toBeFalse();

    $this->actingAs($user)
        ->get(route('extras'))
        ->assertSuccessful()
        ->assertSee(__('Lab API and Info'));
});

test('dev tools are reachable from extras instead of the sidebar', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Extras'))
        ->assertDontSee(__('SMS Logs'))
        ->assertDontSee(__('Merge Duplicates'))
        ->assertDontSee(__('SQL Runner'))
        ->assertDontSee(__('Kanban'));

    $this->actingAs($user)
        ->get(route('extras.dev-side'))
        ->assertSuccessful()
        ->assertSee(__('Dev Side'))
        ->assertSee(__('SMS Logs'))
        ->assertSee(__('Merge Duplicates'))
        ->assertSee(__('SQL Runner'))
        ->assertSee(__('Kanban'));
});

test('admin tools are reachable from extras instead of the sidebar', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Extras'))
        ->assertDontSee(__('Policy Journal'))
        ->assertDontSee(__('Reports to Admin'));

    $this->actingAs($user)
        ->get(route('extras.admin-side'))
        ->assertSuccessful()
        ->assertSee(__('Admin Side'))
        ->assertSee(__('Policy Journal'))
        ->assertSee(__('Notifications'))
        ->assertSee(__('Reports to Admin'));
});

test('stats pages are reachable from extras instead of the sidebar', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Extras'))
        ->assertDontSee(__('Monthly Report'))
        ->assertDontSee(__('Procedure Finances'))
        ->assertDontSee(__('Service Statistics'))
        ->assertDontSee(__('Medication Deliveries'));

    $this->actingAs($user)
        ->get(route('extras.stats'))
        ->assertSuccessful()
        ->assertSee(__('Stats'))
        ->assertSee(__('Monthly Report'))
        ->assertSee(__('Procedure Finances'))
        ->assertSee(__('Service Statistics'))
        ->assertSee(__('Medication Deliveries'));
});
