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
    $response = $this->get(route('supervisor.checklist'));

    $response->assertRedirect(route('login'));
});

test('supervisors can visit the checklist page', function () {
    $supervisor = User::factory()->supervisor()->create();

    $response = $this->actingAs($supervisor)->get(route('supervisor.checklist'));

    $response->assertOk();
});

test('non-supervisor users cannot visit the checklist page', function (UserRole $role, string $expected) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('supervisor.checklist'));

    $response->{$expected}();
})->with([
    'admin' => [UserRole::Admin, 'assertOk'],
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
    'management' => [UserRole::Management, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('supervisor sees active questions and options', function () {
    $supervisor = User::factory()->supervisor()->create();
    $question = SupervisorChecklistQuestion::factory()->create(['question_text' => 'Sample question?']);
    SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Option A']);

    Livewire::actingAs($supervisor)
        ->test('pages::supervisor.checklist')
        ->assertSee('Sample question?')
        ->assertSee('Option A');
});

test('supervisor can submit the checklist for the current block', function () {
    $supervisor = User::factory()->supervisor()->create();
    $question = SupervisorChecklistQuestion::factory()->create();
    $option = SupervisorChecklistOption::factory()->create(['question_id' => $question->id]);

    $component = Livewire::actingAs($supervisor)
        ->test('pages::supervisor.checklist')
        ->set("selectedOptions.{$question->id}", [$option->id])
        ->set("remarks.{$question->id}", 'All good')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SupervisorChecklistEntry::count())->toBe(1);

    $entry = SupervisorChecklistEntry::first();
    expect($entry->user_id)->toBe($supervisor->id);
    expect($entry->responses)->toHaveCount(1);
    expect($entry->responses->first()->remarks)->toBe('All good');
    expect($entry->responses->first()->options->pluck('id')->all())->toContain($option->id);
});

test('already submitted block shows saved responses and prevents re-submission', function () {
    $supervisor = User::factory()->supervisor()->create();
    $question = SupervisorChecklistQuestion::factory()->create();
    $option = SupervisorChecklistOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Option A']);

    $block = app(SupervisorChecklistService::class)->currentBlock();
    $entry = SupervisorChecklistEntry::factory()->forSupervisor($supervisor)->forBlock($block['start'], $block['end'])->create();
    $response = $entry->responses()->create([
        'entry_id' => $entry->id,
        'question_id' => $question->id,
        'remarks' => 'Already submitted',
    ]);
    $response->options()->attach($option->id);

    Livewire::actingAs($supervisor)
        ->test('pages::supervisor.checklist')
        ->assertSee('Already submitted')
        ->assertSee('Option A')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SupervisorChecklistEntry::count())->toBe(1);
});
