<?php

use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\AdminReport;
use App\Models\AdminReportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();

    config([
        'services.ntfy.base_url' => 'https://ntfy.sh',
        'services.ntfy.admin_topic' => 'mmc-hms',
        'services.ntfy.reception_topic' => 'mmc-hms-reception',
    ]);
});

test('admins can visit the reports page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.reports'))
        ->assertOk();
});

test('non-admin users cannot visit the reports page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route('admin.reports'))
        ->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
]);

test('receptionist can start a report thread and notifies admin at priority 5', function () {
    $receptionist = User::factory()->receptionist()->create();

    Livewire::actingAs($receptionist)
        ->test('report-to-admin')
        ->call('openCreateForm')
        ->set('subject', 'Printer is jammed')
        ->set('body', 'The front desk printer will not print receipts.')
        ->call('startReport')
        ->assertHasNoErrors();

    $report = AdminReport::query()->first();

    expect($report)->not->toBeNull()
        ->and($report->subject)->toBe('Printer is jammed')
        ->and($report->created_by)->toBe($receptionist->id)
        ->and($report->messages)->toHaveCount(1)
        ->and($report->messages->first()->body)->toBe('The front desk printer will not print receipts.');

    expect(AdminNotification::where('type', 'admin_report_created')->count())->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ntfy.sh/mmc-hms'
            && $request->header('Priority')[0] === '5'
            && $request->header('Title')[0] === 'New Report to Admin';
    });
});

test('admin reply pushes priority 4 to the reception topic', function () {
    $receptionist = User::factory()->receptionist()->create();
    $admin = User::factory()->admin()->create();

    $report = AdminReport::factory()->create([
        'created_by' => $receptionist->id,
        'subject' => 'Need help with shift',
    ]);

    AdminReportMessage::factory()->create([
        'admin_report_id' => $report->id,
        'user_id' => $receptionist->id,
        'body' => 'Opening cash is short.',
    ]);

    Http::fake();

    Livewire::actingAs($admin)
        ->test('report-to-admin')
        ->call('selectReport', $report->id)
        ->set('replyBody', 'Please recount and send a photo.')
        ->call('reply')
        ->assertHasNoErrors();

    expect($report->fresh()->messages)->toHaveCount(2);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ntfy.sh/mmc-hms-reception'
            && $request->header('Priority')[0] === '4'
            && $request->header('Title')[0] === 'Report Reply';
    });
});

test('receptionist reply on their own thread pushes priority 5', function () {
    $receptionist = User::factory()->receptionist()->create();
    $admin = User::factory()->admin()->create();

    $report = AdminReport::factory()->create([
        'created_by' => $receptionist->id,
        'subject' => 'Need help with shift',
    ]);

    AdminReportMessage::factory()->create([
        'admin_report_id' => $report->id,
        'user_id' => $receptionist->id,
        'body' => 'Opening cash is short.',
    ]);

    AdminReportMessage::factory()->create([
        'admin_report_id' => $report->id,
        'user_id' => $admin->id,
        'body' => 'Please recount and send a photo.',
    ]);

    Http::fake();

    Livewire::actingAs($receptionist)
        ->test('report-to-admin')
        ->call('selectReport', $report->id)
        ->set('replyBody', 'Done, cash matches now.')
        ->call('reply')
        ->assertHasNoErrors();

    expect(AdminNotification::where('type', 'admin_report_replied')->count())->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ntfy.sh/mmc-hms'
            && $request->header('Priority')[0] === '5'
            && $request->header('Title')[0] === 'Report Reply';
    });
});

test('admin and creating receptionist can see shared report thread messages', function () {
    $receptionist = User::factory()->receptionist()->create();
    $admin = User::factory()->admin()->create();

    $report = AdminReport::factory()->create([
        'created_by' => $receptionist->id,
        'subject' => 'Shared subject',
    ]);

    AdminReportMessage::factory()->create([
        'admin_report_id' => $report->id,
        'user_id' => $receptionist->id,
        'body' => 'Hello from reception',
    ]);

    AdminReportMessage::factory()->create([
        'admin_report_id' => $report->id,
        'user_id' => $admin->id,
        'body' => 'Hello from admin',
    ]);

    Livewire::actingAs($receptionist)
        ->test('report-to-admin')
        ->call('selectReport', $report->id)
        ->assertSee('Shared subject')
        ->assertSee('Hello from reception')
        ->assertSee('Hello from admin');

    Livewire::actingAs($admin)
        ->test('report-to-admin')
        ->call('selectReport', $report->id)
        ->assertSee('Shared subject')
        ->assertSee('Hello from reception')
        ->assertSee('Hello from admin');
});

test('receptionist cannot see another receptionist report', function () {
    $author = User::factory()->receptionist()->create();
    $otherReceptionist = User::factory()->receptionist()->create();

    $report = AdminReport::factory()->create([
        'created_by' => $author->id,
        'subject' => 'Private cashier issue',
    ]);

    AdminReportMessage::factory()->create([
        'admin_report_id' => $report->id,
        'user_id' => $author->id,
        'body' => 'Cash drawer will not open.',
    ]);

    Livewire::actingAs($otherReceptionist)
        ->test('report-to-admin')
        ->assertDontSee('Private cashier issue')
        ->call('selectReport', $report->id)
        ->assertForbidden();
});

test('admin can see all receptionist reports', function () {
    $first = User::factory()->receptionist()->create();
    $second = User::factory()->receptionist()->create();
    $admin = User::factory()->admin()->create();

    AdminReport::factory()->create([
        'created_by' => $first->id,
        'subject' => 'First desk report',
    ]);

    AdminReport::factory()->create([
        'created_by' => $second->id,
        'subject' => 'Second desk report',
    ]);

    Livewire::actingAs($admin)
        ->test('report-to-admin')
        ->assertSee('First desk report')
        ->assertSee('Second desk report');
});

test('admin can start a report thread and notifies reception at priority 4', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('report-to-admin')
        ->call('openCreateForm')
        ->set('subject', 'Reminder about closing')
        ->set('body', 'Please close the shift before leaving.')
        ->call('startReport')
        ->assertHasNoErrors();

    expect(AdminReport::query()->count())->toBe(1);
    expect(AdminNotification::where('type', 'admin_report_created')->count())->toBe(0);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ntfy.sh/mmc-hms-reception'
            && $request->header('Priority')[0] === '4';
    });
});
