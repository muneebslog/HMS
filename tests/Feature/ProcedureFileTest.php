<?php

use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureType;
use App\Models\ProcedureTypeDocument;
use App\Models\Shift;
use App\Models\User;
use App\Services\ProcedureFileBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('guests cannot download the procedure file', function () {
    $procedure = Procedure::factory()->create();

    $this->get(route('reception.procedures.file', $procedure))
        ->assertRedirect(route('login'));
});

test('receptionists without an open shift cannot download the procedure file', function () {
    $user = User::factory()->receptionist()->create();
    $procedure = Procedure::factory()->create();

    $this->actingAs($user)
        ->get(route('reception.procedures.file', $procedure))
        ->assertRedirect();
});

test('the procedure file route returns 404 when no documents are linked', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->create();

    $this->actingAs($user)
        ->get(route('reception.procedures.file', $procedure))
        ->assertNotFound();
});

test('the procedure file route returns an inline combined pdf', function () {
    Storage::fake('local');

    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create();
    $patient = Patient::factory()->create();
    $procedure = Procedure::factory()
        ->for($procedureType)
        ->for($patient)
        ->create();

    ProcedureTypeDocument::factory()->for($procedureType)->create([
        'original_name' => 'consent.pdf',
        'sort_order' => 1,
        'path' => "procedure-types/{$procedureType->id}/documents/consent.pdf",
    ]);
    ProcedureTypeDocument::factory()->image()->for($procedureType)->create([
        'original_name' => 'diagram.png',
        'sort_order' => 2,
        'path' => "procedure-types/{$procedureType->id}/documents/diagram.png",
    ]);

    $response = $this->actingAs($user)
        ->get(route('reception.procedures.file', $procedure));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))
        ->toContain('inline')
        ->toContain($patient->fresh()->mrn.'-procedure-file.pdf')
        ->and($response->getContent())->toStartWith('%PDF');
});

test('the procedure file builder merges documents in sort order', function () {
    Storage::fake('local');

    $procedureType = ProcedureType::factory()->create();
    $procedure = Procedure::factory()->for($procedureType)->create();

    ProcedureTypeDocument::factory()->image()->for($procedureType)->create([
        'original_name' => 'second.png',
        'sort_order' => 2,
        'path' => "procedure-types/{$procedureType->id}/documents/second.png",
    ]);
    ProcedureTypeDocument::factory()->for($procedureType)->create([
        'original_name' => 'first.pdf',
        'sort_order' => 1,
        'path' => "procedure-types/{$procedureType->id}/documents/first.pdf",
    ]);

    $pdf = app(ProcedureFileBuilder::class)->build($procedure->fresh(['procedureType.documents']));

    expect($pdf)->toStartWith('%PDF')
        ->and(substr_count($pdf, '/Type /Page'))->toBeGreaterThanOrEqual(2);
});
