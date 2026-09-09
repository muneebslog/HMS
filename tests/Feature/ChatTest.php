<?php

use App\Enums\UserRole;
use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff see the floating chat widget on app pages', function () {
    $user = User::factory()->doctor()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSeeLivewire('staff-chat');
});

test('pending users do not see the floating chat widget', function () {
    $user = User::factory()->user()->create();

    $this->actingAs($user)
        ->get(route('pending-role'))
        ->assertOk()
        ->assertDontSeeLivewire('staff-chat');
});

test('doctor can start a conversation and message a receptionist', function () {
    Event::fake([ChatMessageSent::class]);

    $doctor = User::factory()->doctor()->create();
    $receptionist = User::factory()->receptionist()->create();

    Livewire::actingAs($doctor)
        ->test('staff-chat')
        ->call('toggle')
        ->call('startConversation', $receptionist->id)
        ->set('body', 'Patient is ready in room 2')
        ->call('sendMessage')
        ->assertHasNoErrors()
        ->assertSee('Patient is ready in room 2');

    $conversation = ChatConversation::query()->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->hasParticipant($doctor))->toBeTrue()
        ->and($conversation->hasParticipant($receptionist))->toBeTrue()
        ->and($conversation->messages)->toHaveCount(1)
        ->and($conversation->messages->first()->body)->toBe('Patient is ready in room 2');

    Event::assertDispatched(ChatMessageSent::class);
});

test('receptionist can reply to an admin in an existing conversation', function () {
    Event::fake([ChatMessageSent::class]);

    $receptionist = User::factory()->receptionist()->create();
    $admin = User::factory()->admin()->create();

    $conversation = ChatConversation::factory()->between($receptionist, $admin)->create([
        'last_message_at' => now()->subMinute(),
    ]);

    ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'user_id' => $admin->id,
        'body' => 'Please check the front desk queue.',
        'read_at' => now(),
    ]);

    Livewire::actingAs($receptionist)
        ->test('staff-chat')
        ->call('selectConversation', $conversation->id)
        ->assertSee('Please check the front desk queue.')
        ->set('body', 'On it now.')
        ->call('sendMessage')
        ->assertHasNoErrors()
        ->assertSee('On it now.');

    expect($conversation->fresh()->messages)->toHaveCount(2);

    Event::assertDispatched(ChatMessageSent::class);
});

test('users cannot open another persons conversation', function () {
    $doctor = User::factory()->doctor()->create();
    $receptionist = User::factory()->receptionist()->create();
    $outsider = User::factory()->management()->create();

    $conversation = ChatConversation::factory()->between($doctor, $receptionist)->create();

    Livewire::actingAs($outsider)
        ->test('staff-chat')
        ->call('selectConversation', $conversation->id)
        ->assertForbidden();
});

test('opening a conversation marks unread messages as read', function () {
    $doctor = User::factory()->doctor()->create();
    $receptionist = User::factory()->receptionist()->create();

    $conversation = ChatConversation::factory()->between($doctor, $receptionist)->create();

    $message = ChatMessage::factory()->create([
        'chat_conversation_id' => $conversation->id,
        'user_id' => $doctor->id,
        'body' => 'Hello',
        'read_at' => null,
    ]);

    Livewire::actingAs($receptionist)
        ->test('staff-chat')
        ->call('selectConversation', $conversation->id);

    expect($message->fresh()->read_at)->not->toBeNull();
});

test('find or create between keeps a single conversation for a pair', function () {
    $doctor = User::factory()->doctor()->create();
    $admin = User::factory()->admin()->create();

    $first = ChatConversation::findOrCreateBetween($doctor, $admin);
    $second = ChatConversation::findOrCreateBetween($admin, $doctor);

    expect($first->is($second))->toBeTrue()
        ->and(ChatConversation::query()->count())->toBe(1);
});

test('chat contacts exclude pending users and self', function () {
    $doctor = User::factory()->doctor()->create(['name' => 'Dr Alice']);
    User::factory()->receptionist()->create(['name' => 'Reception Bob']);
    User::factory()->user()->create(['name' => 'Pending Pat']);

    $component = Livewire::actingAs($doctor)
        ->test('staff-chat')
        ->call('toggle')
        ->assertSee('Reception Bob')
        ->assertDontSee('Pending Pat')
        ->set('contactSearch', 'Bob');

    $names = $component->instance()->contacts->pluck('name')->all();

    expect($names)->toContain('Reception Bob')
        ->and($names)->not->toContain('Pending Pat')
        ->and($names)->not->toContain('Dr Alice');
});

test('floating chat can be closed', function () {
    $user = User::factory()->doctor()->create();

    Livewire::actingAs($user)
        ->test('staff-chat')
        ->call('toggle')
        ->assertSet('open', true)
        ->call('closeChat')
        ->assertSet('open', false);
});

test('floating chat is available for staff roles', function (UserRole $role) {
    $user = User::factory()->{$role === UserRole::InchargeNurse ? 'inchargeNurse' : ($role === UserRole::LabTechnician ? 'labTechnician' : $role->value)}()->create();

    Livewire::actingAs($user)
        ->test('staff-chat')
        ->assertSet('userId', $user->id)
        ->call('toggle')
        ->assertSet('open', true);
})->with([
    'admin' => [UserRole::Admin],
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
    'indoor' => [UserRole::Indoor],
    'incharge_nurse' => [UserRole::InchargeNurse],
    'lab_technician' => [UserRole::LabTechnician],
]);
