<?php

use App\Enums\KanbanStatus;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\KanbanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.kanban'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the kanban page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.kanban'));

    $response->assertOk();
});

test('non-admins cannot visit the kanban page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('admin.kanban'));

    $response->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
]);

test('users with the default user role are redirected to the pending role page', function () {
    $user = User::factory()->user()->create();

    $response = $this->actingAs($user)->get(route('admin.kanban'));

    $response->assertRedirect(route('pending-role'));
});

test('admin can create a kanban item', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->set('title', 'New Task')
        ->set('description', 'Task description')
        ->call('saveItem')
        ->assertHasNoErrors();

    $item = KanbanItem::first();

    expect($item)->not->toBeNull()
        ->and($item->title)->toBe('New Task')
        ->and($item->description)->toBe('Task description')
        ->and($item->status)->toBe(KanbanStatus::Todo)
        ->and($item->created_by)->toBe($admin->id);
});

test('creating a kanban item creates an admin notification', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->set('title', 'New Task')
        ->set('description', 'Task description')
        ->call('saveItem')
        ->assertHasNoErrors();

    $notification = AdminNotification::first();

    expect($notification)->not->toBeNull()
        ->and($notification->user_id)->toBe($admin->id)
        ->and($notification->type)->toBe('kanban_item_created')
        ->and($notification->title)->toBe(__('🎯 New Kanban Task Added'))
        ->and($notification->actionable_url)->toBe(route('admin.kanban'));
});

test('admin cannot create a kanban item without a title', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->set('title', '')
        ->call('saveItem')
        ->assertHasErrors(['title']);

    expect(KanbanItem::count())->toBe(0);
});

test('admin can edit a kanban item', function () {
    $admin = User::factory()->admin()->create();
    $item = KanbanItem::factory()->for($admin, 'creator')->create([
        'title' => 'Old Title',
        'description' => 'Old description',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->call('editItem', $item->id)
        ->set('title', 'Updated Title')
        ->set('description', 'Updated description')
        ->call('saveItem')
        ->assertHasNoErrors();

    $item->refresh();

    expect($item->title)->toBe('Updated Title')
        ->and($item->description)->toBe('Updated description');
});

test('admin can delete a kanban item', function () {
    $admin = User::factory()->admin()->create();
    $item = KanbanItem::factory()->for($admin, 'creator')->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->call('confirmDelete', $item->id)
        ->call('deleteItem')
        ->assertHasNoErrors();

    expect(KanbanItem::find($item->id))->toBeNull();
});

test('admin can move an item to the next column', function () {
    $admin = User::factory()->admin()->create();
    $item = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Todo)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->call('moveToColumn', $item->id, KanbanStatus::InProgress->value)
        ->assertHasNoErrors();

    $item->refresh();

    expect($item->status)->toBe(KanbanStatus::InProgress);
});

test('admin can move an item to the previous column', function () {
    $admin = User::factory()->admin()->create();
    $item = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Done)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->call('moveToColumn', $item->id, KanbanStatus::InProgress->value)
        ->assertHasNoErrors();

    $item->refresh();

    expect($item->status)->toBe(KanbanStatus::InProgress);
});

test('admin can reorder an item up within its column', function () {
    $admin = User::factory()->admin()->create();
    $first = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Todo)->create(['position' => 0]);
    $second = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Todo)->create(['position' => 1]);

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->call('moveUp', $second->id)
        ->assertHasNoErrors();

    $first->refresh();
    $second->refresh();

    expect($first->position)->toBe(1)
        ->and($second->position)->toBe(0);
});

test('admin can reorder an item down within its column', function () {
    $admin = User::factory()->admin()->create();
    $first = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Todo)->create(['position' => 0]);
    $second = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Todo)->create(['position' => 1]);

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->call('moveDown', $first->id)
        ->assertHasNoErrors();

    $first->refresh();
    $second->refresh();

    expect($first->position)->toBe(1)
        ->and($second->position)->toBe(0);
});

test('kanban page displays items grouped by status', function () {
    $admin = User::factory()->admin()->create();
    $todoItem = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Todo)->create();
    $doneItem = KanbanItem::factory()->for($admin, 'creator')->status(KanbanStatus::Done)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.kanban')
        ->assertSee($todoItem->title)
        ->assertSee($doneItem->title)
        ->assertSee(KanbanStatus::Todo->label())
        ->assertSee(KanbanStatus::Done->label());
});
