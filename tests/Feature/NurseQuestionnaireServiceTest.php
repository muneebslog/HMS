<?php

use App\Models\NurseQuestionnaire;
use App\Services\NurseQuestionnaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('two hour questionnaires use even-hour blocks', function () {
    $this->travelTo(Carbon::parse('2026-08-18 15:30:00'));

    $questionnaire = NurseQuestionnaire::factory()->make(['interval_hours' => 2]);
    $block = app(NurseQuestionnaireService::class)->currentBlock($questionnaire);

    expect($block['start']->format('Y-m-d H:i'))->toBe('2026-08-18 14:00')
        ->and($block['end']->format('Y-m-d H:i'))->toBe('2026-08-18 16:00');
});

test('four hour questionnaires align blocks from the start of the day', function () {
    $this->travelTo(Carbon::parse('2026-08-18 09:10:00'));

    $questionnaire = NurseQuestionnaire::factory()->make(['interval_hours' => 4]);
    $block = app(NurseQuestionnaireService::class)->currentBlock($questionnaire);

    expect($block['start']->format('H:i'))->toBe('08:00')
        ->and($block['end']->format('H:i'))->toBe('12:00');
});

test('blocks for date match the questionnaire interval', function () {
    $questionnaire = NurseQuestionnaire::factory()->make(['interval_hours' => 2]);
    $blocks = app(NurseQuestionnaireService::class)->blocksForDate($questionnaire, Carbon::parse('2026-08-18'));

    expect($blocks)->toHaveCount(12)
        ->and($blocks->first()['start']->format('H:i'))->toBe('00:00')
        ->and($blocks->first()['end']->format('H:i'))->toBe('02:00')
        ->and($blocks->last()['start']->format('H:i'))->toBe('22:00')
        ->and($blocks->last()['end']->format('H:i'))->toBe('00:00');
});
