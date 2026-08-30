<?php

use App\Enums\ReceptionMemoColor;
use App\Models\ReceptionMemo;
use App\Models\ReceptionMemoRead;
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

test('receptionist can create a memo and notifies reception at priority 5', function () {
    $receptionist = User::factory()->receptionist()->create();

    Livewire::actingAs($receptionist)
        ->test('reception-memo-board')
        ->call('openCreateForm')
        ->set('title', 'Bring toner')
        ->set('body', 'Toner stock is low at reception.')
        ->set('color', ReceptionMemoColor::Sky->value)
        ->call('createMemo')
        ->assertHasNoErrors()
        ->assertSet('showCreateForm', false);

    $memo = ReceptionMemo::query()->first();

    expect($memo)->not->toBeNull()
        ->and($memo->title)->toBe('Bring toner')
        ->and($memo->body)->toBe('Toner stock is low at reception.')
        ->and($memo->color)->toBe(ReceptionMemoColor::Sky);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ntfy.sh/mmc-hms-reception'
            && $request->header('Priority')[0] === '5'
            && $request->header('Title')[0] === 'New Reception Memo';
    });
});

test('admin can create a memo and notifies reception at priority 5', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('reception-memo-board')
        ->call('openCreateForm')
        ->set('title', 'Admin note')
        ->set('body', 'Please verify walk-in totals tonight.')
        ->call('createMemo')
        ->assertHasNoErrors();

    expect(ReceptionMemo::query()->count())->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ntfy.sh/mmc-hms-reception'
            && $request->header('Priority')[0] === '5';
    });
});

test('memo stays visible until confirmed with the read it phrase', function () {
    $receptionist = User::factory()->receptionist()->create();
    $memo = ReceptionMemo::factory()->create([
        'title' => 'Sticky note',
        'body' => 'Do not forget closing checklist.',
    ]);

    Livewire::actingAs($receptionist)
        ->test('reception-memo-board')
        ->assertSee('Sticky note')
        ->set("confirmations.{$memo->id}", 'wrong phrase')
        ->call('markAsRead', $memo->id)
        ->assertHasErrors(["confirmations.{$memo->id}"])
        ->assertSee('Sticky note');

    expect(ReceptionMemoRead::query()->count())->toBe(0);

    Livewire::actingAs($receptionist)
        ->test('reception-memo-board')
        ->set("confirmations.{$memo->id}", 'Read It')
        ->call('markAsRead', $memo->id)
        ->assertHasNoErrors()
        ->assertDontSee('Sticky note');

    expect(ReceptionMemoRead::query()->count())->toBe(1);
});

test('marking a memo as read only dismisses it for that user', function () {
    $firstReceptionist = User::factory()->receptionist()->create();
    $secondReceptionist = User::factory()->receptionist()->create();
    $memo = ReceptionMemo::factory()->create([
        'title' => 'Shared memo',
        'body' => 'Everyone should see this.',
    ]);

    Livewire::actingAs($firstReceptionist)
        ->test('reception-memo-board')
        ->set("confirmations.{$memo->id}", 'read it')
        ->call('markAsRead', $memo->id)
        ->assertHasNoErrors()
        ->assertDontSee('Shared memo');

    Livewire::actingAs($secondReceptionist)
        ->test('reception-memo-board')
        ->assertSee('Shared memo')
        ->assertSee('Everyone should see this.');
});

test('acknowledged memos appear in history view', function () {
    $receptionist = User::factory()->receptionist()->create();
    $memo = ReceptionMemo::factory()->create([
        'title' => 'Shift handoff',
        'body' => 'Remember to lock the supply cabinet.',
    ]);

    Livewire::actingAs($receptionist)
        ->test('reception-memo-board')
        ->set("confirmations.{$memo->id}", 'read it')
        ->call('markAsRead', $memo->id)
        ->assertHasNoErrors()
        ->set('view', 'history')
        ->assertSee('Shift handoff')
        ->assertSee('Remember to lock the supply cabinet.')
        ->assertSee('You acknowledged');
});

test('memo creator can delete a memo for everyone', function () {
    $receptionist = User::factory()->receptionist()->create();
    $memo = ReceptionMemo::factory()->create([
        'created_by' => $receptionist->id,
        'title' => 'Remove me',
        'body' => 'This memo should be deleted.',
    ]);

    Livewire::actingAs($receptionist)
        ->test('reception-memo-board')
        ->call('deleteMemo', $memo->id)
        ->assertHasNoErrors()
        ->assertDontSee('Remove me');

    expect(ReceptionMemo::query()->count())->toBe(0);
});

test('admin can delete another users memo', function () {
    $receptionist = User::factory()->receptionist()->create();
    $admin = User::factory()->admin()->create();
    $memo = ReceptionMemo::factory()->create([
        'created_by' => $receptionist->id,
        'title' => 'Admin cleanup',
        'body' => 'Admin can remove this.',
    ]);

    Livewire::actingAs($admin)
        ->test('reception-memo-board')
        ->call('deleteMemo', $memo->id)
        ->assertHasNoErrors();

    expect(ReceptionMemo::query()->count())->toBe(0);
});

test('memo cards render with their selected color', function () {
    $receptionist = User::factory()->receptionist()->create();

    $roseMemo = ReceptionMemo::factory()->create([
        'title' => 'Urgent supply',
        'body' => 'Order more gloves.',
        'color' => ReceptionMemoColor::Rose,
    ]);

    $limeMemo = ReceptionMemo::factory()->create([
        'title' => 'Reminder',
        'body' => 'Water the plants.',
        'color' => ReceptionMemoColor::Lime,
    ]);

    Livewire::actingAs($receptionist)
        ->test('reception-memo-board')
        ->assertSee('Urgent supply')
        ->assertSee('Reminder')
        ->assertSeeHtml('memo-card-rose')
        ->assertSeeHtml('memo-card-lime')
        ->assertSee('Rose')
        ->assertSee('Lime');
});
