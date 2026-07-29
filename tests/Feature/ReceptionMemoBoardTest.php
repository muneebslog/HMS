<?php

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
        ->call('createMemo')
        ->assertHasNoErrors()
        ->assertSee('Bring toner')
        ->assertSee('Toner stock is low at reception.');

    expect(ReceptionMemo::query()->count())->toBe(1);

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
