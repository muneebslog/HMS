<?php

use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\SupervisorChecklistEntry;
use App\Models\SupervisorChecklistOption;
use App\Models\SupervisorChecklistQuestion;
use App\Models\User;
use App\Services\SupervisorChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('supervisor.checklist'));

    $response->assertRedirect(route('login'));
});

test('receptionists can visit the checklist page', function () {
    $receptionist = User::factory()->receptionist()->create();

    $response = $this->actingAs($receptionist)->get(route('supervisor.checklist'));

    $response->assertOk();
});

test('non-receptionist users cannot visit the checklist page', function (UserRole $role, string $expected) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('supervisor.checklist'));

    $response->{$expected}();
})->with([
    'admin' => [UserRole::Admin, 'assertOk'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
    'management' => [UserRole::Management, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('receptionist sees active questions and options', function () {
    $receptionist = User::factory()->receptionist()->create();
    $question = SupervisorChecklistQuestion::factory()->create(['question_text' => 'Sample question?']);
    SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Option A']);

    Livewire::actingAs($receptionist)
        ->test('pages::supervisor.checklist')
        ->assertSee('Sample question?')
        ->assertSee('Option A');
});

test('receptionist can submit the checklist for the current block', function () {
    $receptionist = User::factory()->receptionist()->create();
    $question = SupervisorChecklistQuestion::factory()->create();
    $option = SupervisorChecklistOption::factory()->create(['question_id' => $question->id]);

    Livewire::actingAs($receptionist)
        ->test('pages::supervisor.checklist')
        ->set("selectedOptions.{$question->id}", $option->id)
        ->set("remarks.{$question->id}", 'All good')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SupervisorChecklistEntry::count())->toBe(1);

    $entry = SupervisorChecklistEntry::first();
    expect($entry->user_id)->toBe($receptionist->id);
    expect($entry->responses)->toHaveCount(1);
    expect($entry->responses->first()->remarks)->toBe('All good');
    expect($entry->responses->first()->options->pluck('id')->all())->toContain($option->id);
});

test('already submitted block shows saved responses and prevents re-submission', function () {
    $receptionist = User::factory()->receptionist()->create();
    $question = SupervisorChecklistQuestion::factory()->create();
    $option = SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Option A']);

    $block = app(SupervisorChecklistService::class)->currentBlock();
    $entry = SupervisorChecklistEntry::factory()->forUser($receptionist)->forBlock($block['start'], $block['end'])->create();
    $response = $entry->responses()->create([
        'entry_id' => $entry->id,
        'question_id' => $question->id,
        'remarks' => 'Already submitted',
    ]);
    $response->options()->attach($option->id);

    Livewire::actingAs($receptionist)
        ->test('pages::supervisor.checklist')
        ->assertSee('Already submitted')
        ->assertSee('Option A')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SupervisorChecklistEntry::count())->toBe(1);
});

test('submitting a checklist with no answers creates an admin notification', function () {
    $receptionist = User::factory()->receptionist()->create();
    $question = SupervisorChecklistQuestion::factory()->create(['question_text' => 'Is the ward clean?']);
    $noOption = SupervisorChecklistOption::factory()->no()->create(['question_id' => $question->id]);
    SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Yes']);

    Livewire::actingAs($receptionist)
        ->test('pages::supervisor.checklist')
        ->set("selectedOptions.{$question->id}", $noOption->id)
        ->set("remarks.{$question->id}", 'Needs cleaning')
        ->call('submit')
        ->assertHasNoErrors();

    expect(AdminNotification::count())->toBe(1);

    $notification = AdminNotification::first();
    expect($notification->type)->toBe('supervisor_checklist_no_answers');
    expect($notification->message)->toContain('Is the ward clean?');
    expect($notification->message)->toContain('Needs cleaning');
    expect($notification->metadata)->toHaveKey('entry_id');
});

test('submitting a checklist without no answers does not create a notification', function () {
    $receptionist = User::factory()->receptionist()->create();
    $question = SupervisorChecklistQuestion::factory()->create();
    $yesOption = SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Yes']);

    Livewire::actingAs($receptionist)
        ->test('pages::supervisor.checklist')
        ->set("selectedOptions.{$question->id}", $yesOption->id)
        ->call('submit')
        ->assertHasNoErrors();

    expect(AdminNotification::count())->toBe(0);
});
