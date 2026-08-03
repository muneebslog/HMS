<?php

use App\Enums\UserRole;
use App\Models\SupervisorChecklistOption;
use App\Models\SupervisorChecklistQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.supervisor-questions'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the supervisor questions page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.supervisor-questions'));

    $response->assertOk();
});

test('non-admin users cannot visit the supervisor questions page', function (UserRole $role, string $expected) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('admin.supervisor-questions'));

    $response->{$expected}();
})->with([
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
    'management' => [UserRole::Management, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('admin can create a question', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-questions')
        ->set('questionText', 'Is the lab running?')
        ->set('questionSortOrder', 1)
        ->set('questionIsActive', true)
        ->call('saveQuestion')
        ->assertHasNoErrors();

    expect(SupervisorChecklistQuestion::count())->toBe(1);
    expect(SupervisorChecklistQuestion::first()->question_text)->toBe('Is the lab running?');
    expect(SupervisorChecklistOption::count())->toBe(2);
    expect(SupervisorChecklistOption::where('is_no', true)->exists())->toBeTrue();
});

test('admin can edit a question', function () {
    $admin = User::factory()->admin()->create();
    $question = SupervisorChecklistQuestion::factory()->create(['question_text' => 'Old question']);

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-questions')
        ->call('editQuestion', $question->id)
        ->set('questionText', 'Updated question')
        ->set('questionSortOrder', 5)
        ->set('questionIsActive', false)
        ->call('saveQuestion')
        ->assertHasNoErrors();

    expect($question->fresh()->question_text)->toBe('Updated question');
    expect($question->fresh()->is_active)->toBeFalse();
});

test('admin can delete a question', function () {
    $admin = User::factory()->admin()->create();
    $question = SupervisorChecklistQuestion::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-questions')
        ->call('deleteQuestion', $question->id)
        ->assertHasNoErrors();

    expect(SupervisorChecklistQuestion::count())->toBe(0);
});

test('admin can add an option to a question', function () {
    $admin = User::factory()->admin()->create();
    $question = SupervisorChecklistQuestion::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-questions')
        ->call('manageOptions', $question->id)
        ->set('optionText', 'Yes')
        ->set('optionIsNo', false)
        ->set('optionSortOrder', 1)
        ->set('optionIsActive', true)
        ->call('saveOption')
        ->assertHasNoErrors();

    expect(SupervisorChecklistOption::count())->toBe(1);
    expect($question->fresh()->options->first()->option_text)->toBe('Yes');
});

test('admin can edit an option', function () {
    $admin = User::factory()->admin()->create();
    $option = SupervisorChecklistOption::factory()->create(['option_text' => 'Old option']);

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-questions')
        ->call('manageOptions', $option->question_id)
        ->call('editOption', $option->id)
        ->set('optionText', 'Updated option')
        ->set('optionIsNo', true)
        ->set('optionSortOrder', 2)
        ->set('optionIsActive', false)
        ->call('saveOption')
        ->assertHasNoErrors();

    expect($option->fresh()->option_text)->toBe('Updated option');
    expect($option->fresh()->is_no)->toBeTrue();
    expect($option->fresh()->is_active)->toBeFalse();
});

test('admin can delete an option', function () {
    $admin = User::factory()->admin()->create();
    $option = SupervisorChecklistOption::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.supervisor-questions')
        ->call('manageOptions', $option->question_id)
        ->call('deleteOption', $option->id)
        ->assertHasNoErrors();

    expect(SupervisorChecklistOption::count())->toBe(0);
});
