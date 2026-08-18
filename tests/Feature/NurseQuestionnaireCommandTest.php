<?php

use App\Models\AdminNotification;
use App\Models\NurseQuestionnaire;
use App\Models\NurseQuestionnaireEntry;
use App\Models\NurseQuestionnaireQuestion;
use App\Models\User;
use App\Services\NurseQuestionnaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('command notifies only incharge nurses missing the previous block entry', function () {
    $missingNurse = User::factory()->inchargeNurse()->create();
    $submittedNurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create(['interval_hours' => 2]);
    NurseQuestionnaireQuestion::factory()->create(['questionnaire_id' => $questionnaire->id]);
    $block = app(NurseQuestionnaireService::class)->previousCompletedBlock($questionnaire);

    NurseQuestionnaireEntry::factory()
        ->forQuestionnaire($questionnaire)
        ->forUser($submittedNurse)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $this->artisan('nurse-questionnaires:check-missing')
        ->assertSuccessful()
        ->expectsOutputToContain('Notified: '.$missingNurse->name)
        ->doesntExpectOutputToContain('Notified: '.$submittedNurse->name);

    expect(AdminNotification::where('type', 'nurse_questionnaire_missing')->count())->toBe(1);
});

test('command creates no notifications when all incharge nurses submitted', function () {
    $nurse = User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->create(['interval_hours' => 2]);
    NurseQuestionnaireQuestion::factory()->create(['questionnaire_id' => $questionnaire->id]);
    $block = app(NurseQuestionnaireService::class)->previousCompletedBlock($questionnaire);

    NurseQuestionnaireEntry::factory()
        ->forQuestionnaire($questionnaire)
        ->forUser($nurse)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $this->artisan('nurse-questionnaires:check-missing')
        ->assertSuccessful()
        ->expectsOutputToContain('Done. 0 notification(s) sent.');

    expect(AdminNotification::where('type', 'nurse_questionnaire_missing')->count())->toBe(0);
});

test('command skips inactive questionnaires', function () {
    User::factory()->inchargeNurse()->create();
    $questionnaire = NurseQuestionnaire::factory()->inactive()->create();
    NurseQuestionnaireQuestion::factory()->create(['questionnaire_id' => $questionnaire->id]);

    $this->artisan('nurse-questionnaires:check-missing')
        ->assertSuccessful()
        ->expectsOutputToContain('No active questionnaires found.');

    expect(AdminNotification::count())->toBe(0);
});
