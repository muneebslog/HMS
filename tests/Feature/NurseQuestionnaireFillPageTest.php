<?php

use App\Enums\NurseQuestionnaireAnswer;
use App\Enums\UserRole;
use App\Models\AdminNotification;
use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\NurseQuestionnaireQuestion;
use App\Models\User;
use App\Services\NurseQuestionnaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('incharge.questionnaires'));

    $response->assertRedirect(route('login'));
});

test('incharge nurses can visit the questionnaires page', function () {
    $nurse = User::factory()->inchargeNurse()->create();

    $response = $this->actingAs($nurse)->get(route('incharge.questionnaires'));

    $response->assertOk();
});

test('non-incharge users cannot visit the questionnaires page', function (UserRole $role, string $expected) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get(route('incharge.questionnaires'));

    $response->{$expected}();
})->with([
    'admin' => [UserRole::Admin, 'assertOk'],
    'receptionist' => [UserRole::Receptionist, 'assertForbidden'],
    'doctor' => [UserRole::Doctor, 'assertForbidden'],
    'management' => [UserRole::Management, 'assertForbidden'],
    'user' => [UserRole::User, 'assertRedirect'],
]);

test('incharge nurse sees active questionnaires', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create(['name' => 'Ward Safety']);
    NurseQuestionnaireQuestion::factory()->create([
        'questionnaire_id' => $questionnaire->id,
        'question_text' => 'Are oxygen cylinders full?',
    ]);
    NurseQuestionnaire::factory()->inactive()->create(['name' => 'Hidden Form']);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.questionnaires')
        ->assertSee('Ward Safety')
        ->assertDontSee('Hidden Form');
});

test('incharge nurse can submit yes answers without remarks', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create();
    $question = NurseQuestionnaireQuestion::factory()->create(['questionnaire_id' => $questionnaire->id]);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.questionnaire', ['questionnaire' => $questionnaire])
        ->set("answers.{$question->id}", NurseQuestionnaireAnswer::Yes->value)
        ->call('submit')
        ->assertHasNoErrors();

    $entry = NurseQuestionnaireEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($nurse->id)
        ->and($entry->questionnaire_id)->toBe($questionnaire->id)
        ->and($entry->responses)->toHaveCount(1)
        ->and($entry->responses->first()->answer)->toBe(NurseQuestionnaireAnswer::Yes)
        ->and(AdminNotification::count())->toBe(0);
});

test('incharge nurse must add a remark when answering no', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create();
    $question = NurseQuestionnaireQuestion::factory()->create(['questionnaire_id' => $questionnaire->id]);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.questionnaire', ['questionnaire' => $questionnaire])
        ->set("answers.{$question->id}", NurseQuestionnaireAnswer::No->value)
        ->call('submit')
        ->assertHasErrors(["remarks.{$question->id}"]);

    expect(NurseQuestionnaireEntry::count())->toBe(0);
});

test('incharge nurse can submit a no answer with a remark', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create(['name' => 'Ward Safety']);
    $question = NurseQuestionnaireQuestion::factory()->create([
        'questionnaire_id' => $questionnaire->id,
        'question_text' => 'Are crash carts complete?',
    ]);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.questionnaire', ['questionnaire' => $questionnaire])
        ->set("answers.{$question->id}", NurseQuestionnaireAnswer::No->value)
        ->set("remarks.{$question->id}", 'Defibrillator missing pads')
        ->call('submit')
        ->assertHasNoErrors();

    $entry = NurseQuestionnaireEntry::query()->first();

    expect($entry->responses->first()->answer)->toBe(NurseQuestionnaireAnswer::No)
        ->and($entry->responses->first()->remarks)->toBe('Defibrillator missing pads')
        ->and(AdminNotification::count())->toBe(1)
        ->and(AdminNotification::first()->type)->toBe('nurse_questionnaire_no_answers');
});

test('already submitted block shows saved responses and prevents re-submission', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create();
    $question = NurseQuestionnaireQuestion::factory()->create(['questionnaire_id' => $questionnaire->id]);
    $block = app(NurseQuestionnaireService::class)->currentBlock($questionnaire);

    $entry = NurseQuestionnaireEntry::factory()
        ->forQuestionnaire($questionnaire)
        ->forUser($nurse)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $entry->responses()->create([
        'question_id' => $question->id,
        'answer' => NurseQuestionnaireAnswer::Yes,
        'remarks' => null,
    ]);

    Livewire::actingAs($nurse)
        ->test('pages::incharge.questionnaire', ['questionnaire' => $questionnaire])
        ->assertSee('Submitted Questionnaire')
        ->call('submit')
        ->assertHasNoErrors();

    expect(NurseQuestionnaireEntry::count())->toBe(1);
});

test('inactive questionnaires cannot be filled', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->inactive()->create();

    $this->actingAs($nurse)
        ->get(route('incharge.questionnaire', $questionnaire))
        ->assertNotFound();
});
