<?php

use App\Enums\UserRole;
use App\Events\AdminReportMessagePosted;
use App\Events\ReceptionMemoPosted;
use App\Models\AdminReport;
use App\Models\AdminReportMessage;
use App\Models\ReceptionMemo;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();

    config([
        'services.ntfy.base_url' => 'https://ntfy.sh',
        'services.ntfy.admin_topic' => 'mmc-hms',
        'services.ntfy.reception_topic' => 'mmc-hms-reception',
        'broadcasting.default' => 'null',
    ]);
});

test('creating a memo dispatches ReceptionMemoPosted', function () {
    Event::fake([ReceptionMemoPosted::class]);

    $receptionist = User::factory()->receptionist()->create();

    Livewire::actingAs($receptionist)
        ->test('reception-memo-board')
        ->call('openCreateForm')
        ->set('title', 'Bring toner')
        ->set('body', 'Toner stock is low at reception.')
        ->call('createMemo')
        ->assertHasNoErrors();

    $memo = ReceptionMemo::query()->first();

    Event::assertDispatched(ReceptionMemoPosted::class, function (ReceptionMemoPosted $event) use ($memo, $receptionist) {
        return $event->memo->is($memo)
            && $event->actor->is($receptionist);
    });
});

test('starting and replying to a report dispatches AdminReportMessagePosted', function () {
    Event::fake([AdminReportMessagePosted::class]);

    $receptionist = User::factory()->receptionist()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($receptionist)
        ->test('report-to-admin')
        ->call('openCreateForm')
        ->set('subject', 'Printer jammed')
        ->set('body', 'Cannot print receipts.')
        ->call('startReport')
        ->assertHasNoErrors();

    $report = AdminReport::query()->first();

    Event::assertDispatched(AdminReportMessagePosted::class, function (AdminReportMessagePosted $event) use ($report, $receptionist) {
        return $event->report->is($report)
            && $event->actor->is($receptionist)
            && $event->isNewThread === true;
    });

    Event::fake([AdminReportMessagePosted::class]);

    Livewire::actingAs($admin)
        ->test('report-to-admin')
        ->call('selectReport', $report->id)
        ->set('replyBody', 'Try restarting the spooler.')
        ->call('reply')
        ->assertHasNoErrors();

    Event::assertDispatched(AdminReportMessagePosted::class, function (AdminReportMessagePosted $event) use ($report, $admin) {
        return $event->report->is($report)
            && $event->actor->is($admin)
            && $event->isNewThread === false;
    });
});

test('ReceptionMemoPosted broadcasts the expected payload on the private channel', function () {
    $actor = User::factory()->receptionist()->create();
    $memo = ReceptionMemo::factory()->create([
        'created_by' => $actor->id,
        'title' => 'Sticky note',
        'body' => str_repeat('Please check closing checklist. ', 10),
    ]);

    $event = new ReceptionMemoPosted($memo, $actor);

    expect($event->broadcastAs())->toBe('memo.posted');
    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-hms.reception');

    $payload = $event->broadcastWith();

    expect($payload)
        ->memo_id->toBe($memo->id)
        ->title->toBe('Sticky note')
        ->actor_id->toBe($actor->id)
        ->actor_name->toBe($actor->name)
        ->and(strlen($payload['body']))->toBeLessThanOrEqual(123);
});

test('AdminReportMessagePosted broadcasts the expected payload on the private channel', function () {
    $actor = User::factory()->admin()->create();
    $report = AdminReport::factory()->create([
        'created_by' => $actor->id,
        'subject' => 'Queue TV frozen',
    ]);

    AdminReportMessage::factory()->create([
        'admin_report_id' => $report->id,
        'user_id' => $actor->id,
    ]);

    $event = new AdminReportMessagePosted($report, $actor, true);

    expect($event->broadcastAs())->toBe('report.posted');
    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-hms.reception');

    expect($event->broadcastWith())
        ->report_id->toBe($report->id)
        ->subject->toBe('Queue TV frozen')
        ->actor_id->toBe($actor->id)
        ->actor_name->toBe($actor->name)
        ->is_new_thread->toBeTrue();
});

test('admin and receptionist can authorize the reception broadcast channel', function (UserRole $role) {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    Broadcast::forgetDrivers();

    Broadcast::channel('hms.reception', function (User $user) {
        return $user->isAdmin() || $user->isReceptionist();
    });

    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-hms.reception',
            'socket_id' => '1.1',
        ])
        ->assertSuccessful();
})->with([
    'admin' => [UserRole::Admin],
    'receptionist' => [UserRole::Receptionist],
]);

test('non reception roles cannot authorize the reception broadcast channel', function (UserRole $role) {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    Broadcast::forgetDrivers();

    Broadcast::channel('hms.reception', function (User $user) {
        return $user->isAdmin() || $user->isReceptionist();
    });

    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-hms.reception',
            'socket_id' => '1.1',
        ])
        ->assertForbidden();
})->with([
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
]);
