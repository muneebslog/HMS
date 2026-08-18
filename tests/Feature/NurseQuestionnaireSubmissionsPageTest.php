<?php

use App\Enums\NurseQuestionnaireAnswer;
use App\Enums\UserRole;
use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\NurseQuestionnaireQuestion;
use App\Models\User;
use App\Services\NurseQuestionnaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.nurse-questionnaire-submissions'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the nurse questionnaire submissions page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.nurse-questionnaire-submissions'));

    $response->assertOk();
});

test('non-admin users cannot visit the nurse questionnaire submissions page', function (UserRole $role, string $expected) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get(route('admin.nurse-questionnaire-submissions'));

    $response->{$expected}();
})->with([
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'incharge nurse' => [UserRole::InchargeNurse, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('page lists daily blocks for a selected nurse and questionnaire', function () {
    $admin = User::factory()->admin()->create();
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create(['interval_hours' => 2]);

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaire-submissions')
        ->set('selectedQuestionnaireId', $questionnaire->id)
        ->set('selectedNurseId', $nurse->id)
        ->assertSee($nurse->name)
        ->assertSee('00:00')
        ->assertSee('Missing');
});

test('submitted blocks show as submitted', function () {
    $admin = User::factory()->admin()->create();
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create(['interval_hours' => 2]);
    $block = app(NurseQuestionnaireService::class)->currentBlock($questionnaire);

    NurseQuestionnaireEntry::factory()
        ->forQuestionnaire($questionnaire)
        ->forUser($nurse)
        ->forBlock($block['start'], $block['end'])
        ->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaire-submissions')
        ->set('selectedQuestionnaireId', $questionnaire->id)
        ->set('selectedNurseId', $nurse->id)
        ->assertSee('Submitted');
});

test('expanding a submitted block shows responses', function () {
    $admin = User::factory()->admin()->create();
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create();
    $question = NurseQuestionnaireQuestion::factory()->create([
        'questionnaire_id' => $questionnaire->id,
        'question_text' => 'Are oxygen cylinders full?',
    ]);
    $block = app(NurseQuestionnaireService::class)->currentBlock($questionnaire);
    $entry = NurseQuestionnaireEntry::factory()
        ->forQuestionnaire($questionnaire)
        ->forUser($nurse)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $entry->responses()->create([
        'question_id' => $question->id,
        'answer' => NurseQuestionnaireAnswer::No,
        'remarks' => 'One cylinder empty',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.nurse-questionnaire-submissions')
        ->set('selectedQuestionnaireId', $questionnaire->id)
        ->set('selectedNurseId', $nurse->id)
        ->call('toggleBlock', $block['start']->format('H:i'))
        ->assertSee('Are oxygen cylinders full?')
        ->assertSee('One cylinder empty');
});
