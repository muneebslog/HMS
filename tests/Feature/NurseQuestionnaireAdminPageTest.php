<?php

use App\Enums\UserRole;
use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.nurse-questionnaires'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the nurse questionnaires page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.nurse-questionnaires'));

    $response->assertOk();
});

test('non-admin users cannot visit the nurse questionnaires page', function (UserRole $role, string $expected) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get(route('admin.nurse-questionnaires'));

    $response->{$expected}();
})->with([
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
    'management' => [UserRole::Management, 'assertForbidden'],
    'incharge nurse' => [UserRole::InchargeNurse, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('admin can create a questionnaire', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaires')
        ->set('name', 'Ward Round')
        ->set('description', 'Hourly ward checks')
        ->set('intervalHours', 2)
        ->set('questionnaireIsActive', true)
        ->call('saveQuestionnaire')
        ->assertHasNoErrors();

    $questionnaire = NurseQuestionnaire::query()->first();

    expect($questionnaire)->not->toBeNull()
        ->and($questionnaire->name)->toBe('Ward Round')
        ->and($questionnaire->interval_hours)->toBe(2)
        ->and($questionnaire->created_by)->toBe($admin->id);
});

test('admin can edit a questionnaire', function () {
    $admin = User::factory()->admin()->create();
    $questionnaire = NurseQuestionnaire::factory()->create(['name' => 'Old name', 'interval_hours' => 2]);

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaires')
        ->call('editQuestionnaire', $questionnaire->id)
        ->set('name', 'Updated name')
        ->set('intervalHours', 4)
        ->set('questionnaireIsActive', false)
        ->call('saveQuestionnaire')
        ->assertHasNoErrors();

    expect($questionnaire->fresh()->name)->toBe('Updated name')
        ->and($questionnaire->fresh()->interval_hours)->toBe(4)
        ->and($questionnaire->fresh()->is_active)->toBeFalse();
});

test('admin can delete a questionnaire', function () {
    $admin = User::factory()->admin()->create();
    $questionnaire = NurseQuestionnaire::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaires')
        ->call('deleteQuestionnaire', $questionnaire->id)
        ->assertHasNoErrors();

    expect(NurseQuestionnaire::count())->toBe(0);
});

test('admin can add a question to a questionnaire', function () {
    $admin = User::factory()->admin()->create();
    $questionnaire = NurseQuestionnaire::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaires')
        ->call('manageQuestions', $questionnaire->id)
        ->set('questionText', 'Are crash carts complete?')
        ->set('questionSortOrder', 1)
        ->set('questionIsActive', true)
        ->call('saveQuestion')
        ->assertHasNoErrors();

    expect(NurseQuestionnaireQuestion::count())->toBe(1)
        ->and($questionnaire->fresh()->questions->first()->question_text)->toBe('Are crash carts complete?');
});

test('admin can edit a question', function () {
    $admin = User::factory()->admin()->create();
    $question = NurseQuestionnaireQuestion::factory()->create(['question_text' => 'Old question']);

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaires')
        ->call('manageQuestions', $question->questionnaire_id)
        ->call('editQuestion', $question->id)
        ->set('questionText', 'Updated question')
        ->set('questionSortOrder', 5)
        ->set('questionIsActive', false)
        ->call('saveQuestion')
        ->assertHasNoErrors();

    expect($question->fresh()->question_text)->toBe('Updated question')
        ->and($question->fresh()->is_active)->toBeFalse();
});

test('admin can delete a question', function () {
    $admin = User::factory()->admin()->create();
    $question = NurseQuestionnaireQuestion::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaires')
        ->call('manageQuestions', $question->questionnaire_id)
        ->call('deleteQuestion', $question->id)
        ->assertHasNoErrors();

    expect(NurseQuestionnaireQuestion::count())->toBe(0);
});
