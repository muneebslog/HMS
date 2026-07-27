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
    'supervisor' => [UserRole::Supervisor, 'assertForbidden'],
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
    'management' => [UserRole::Management, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('page lists daily blocks for a selected supervisor', function () {
    $admin = User::factory()->admin()->create();
    $supervisor = User::factory()->supervisor()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-checklist')
        ->set('selectedSupervisorId', $supervisor->id)
        ->assertSee($supervisor->name)
        ->assertSee('00:00 - 01:00')
        ->assertSee('01:00 - 02:00');
});

test('page shows submitted and missing block statuses', function () {
    $admin = User::factory()->admin()->create();
    $supervisor = User::factory()->supervisor()->create();
    $block = app(SupervisorChecklistService::class)->currentBlock();

    SupervisorChecklistEntry::factory()
        ->forSupervisor($supervisor)
        ->forBlock($block['start'], $block['end'])
        ->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-checklist')
        ->set('selectedSupervisorId', $supervisor->id)
        ->assertSee($block['start']->format('H:i').' - '.$block['end']->format('H:i'))
        ->assertSee('Submitted');
});

test('expanding a submitted block shows question option and remark details', function () {
    $admin = User::factory()->admin()->create();
    $supervisor = User::factory()->supervisor()->create();
    $question = SupervisorChecklistQuestion::factory()->create(['question_text' => 'Check temperature?']);
    $option = SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Normal']);
    $block = app(SupervisorChecklistService::class)->currentBlock();
    $entry = SupervisorChecklistEntry::factory()
        ->forSupervisor($supervisor)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $response = $entry->responses()->create([
        'entry_id' => $entry->id,
        'question_id' => $question->id,
        'remarks' => 'Room was cool',
    ]);
    $response->options()->attach($option->id);

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-checklist')
        ->set('selectedSupervisorId', $supervisor->id)
        ->call('toggleBlock', $block['start']->format('H:i'))
        ->assertSee('Check temperature?')
        ->assertSee('Normal')
        ->assertSee('Room was cool');
});
