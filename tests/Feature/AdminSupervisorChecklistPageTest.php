<?php

use App\Enums\UserRole;
use App\Models\SupervisorChecklistEntry;
use App\Models\SupervisorChecklistOption;
use App\Models\SupervisorChecklistQuestion;
use App\Models\User;
use App\Services\SupervisorChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.supervisor-checklist'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the supervisor checklist summary page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.supervisor-checklist'));

    $response->assertOk();
});

test('non-admin users cannot visit the supervisor checklist summary page', function (UserRole $role, string $expected) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('admin.supervisor-checklist'));

    $response->{$expected}();
})->with([
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
    'management' => [UserRole::Management, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('page lists daily blocks for a selected receptionist', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-checklist')
        ->set('selectedReceptionistId', $receptionist->id)
        ->assertSee($receptionist->name)
        ->assertSee('00:00')
        ->assertSee('Missing');
});

test('submitted blocks show as submitted', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();
    $block = app(SupervisorChecklistService::class)->currentBlock();

    SupervisorChecklistEntry::factory()
        ->forUser($receptionist)
        ->forBlock($block['start'], $block['end'])
        ->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-checklist')
        ->set('selectedReceptionistId', $receptionist->id)
        ->assertSee('Submitted');
});

test('expanding a submitted block shows responses', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();
    $question = SupervisorChecklistQuestion::factory()->create(['question_text' => 'Check temperature?']);
    $option = SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Normal']);
    $block = app(SupervisorChecklistService::class)->currentBlock();
    $entry = SupervisorChecklistEntry::factory()
        ->forUser($receptionist)
        ->forBlock($block['start'], $block['end'])
        ->create();
    $response = $entry->responses()->create([
        'question_id' => $question->id,
        'remarks' => 'Looks fine',
    ]);
    $response->options()->attach($option->id);

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-checklist')
        ->set('selectedReceptionistId', $receptionist->id)
        ->call('toggleBlock', $block['start']->format('H:i'))
        ->assertSee('Check temperature?')
        ->assertSee('Normal')
        ->assertSee('Looks fine');
});

test('no answers are highlighted in the expanded view', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = User::factory()->receptionist()->create();
    $question = SupervisorChecklistQuestion::factory()->create(['question_text' => 'Is equipment working?']);
    $noOption = SupervisorChecklistOption::factory()->no()->create(['question_id' => $question->id]);
    $block = app(SupervisorChecklistService::class)->currentBlock();
    $entry = SupervisorChecklistEntry::factory()
        ->forUser($receptionist)
        ->forBlock($block['start'], $block['end'])
        ->create();
    $response = $entry->responses()->create([
        'question_id' => $question->id,
        'remarks' => 'Broken',
    ]);
    $response->options()->attach($noOption->id);

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-checklist')
        ->set('selectedReceptionistId', $receptionist->id)
        ->call('toggleBlock', $block['start']->format('H:i'))
        ->assertSee('Is equipment working?')
        ->assertSee('Broken');
});
