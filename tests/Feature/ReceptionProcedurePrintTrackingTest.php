<?php

use App\Enums\ProcedureDocumentKind;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureDocument;
use App\Models\ProcedureType;
use App\Models\ProcedureTypeDocument;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('printing the procedure file marks it as printed and hides re-admit action', function () {
    Storage::fake('local');

    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $procedureType = ProcedureType::factory()->create();
    $patient = Patient::factory()->create();
    $procedure = Procedure::factory()
        ->admitted()
        ->for($procedureType)
        ->for($patient)
        ->create();

    ProcedureTypeDocument::factory()->for($procedureType)->create([
        'original_name' => 'consent.pdf',
        'path' => "procedure-types/{$procedureType->id}/documents/consent.pdf",
    ]);

    expect($procedure->isFilePrinted())->toBeFalse();

    $this->actingAs($user)
        ->get(route('reception.procedures.file', $procedure))
        ->assertOk();

    $procedure->refresh();

    expect($procedure->isFilePrinted())->toBeTrue()
        ->and($procedure->file_printed_by)->toBe($user->id);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee(__('1. Admission'))
        ->assertDontSee(__('1. Add Admission'))
        ->assertSee(__('2. File Printed'));
});

test('printing the procedure bill tracks generated and printed document state', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->admitted()->create();

    $this->actingAs($user)
        ->get(route('reception.procedures.print', $procedure))
        ->assertOk();

    $document = ProcedureDocument::query()
        ->where('procedure_id', $procedure->id)
        ->where('kind', ProcedureDocumentKind::Bill)
        ->first();

    expect($document)->not->toBeNull()
        ->and($document->generated_at)->not->toBeNull()
        ->and($document->printed_at)->not->toBeNull()
        ->and($document->printed_by)->toBe($user->id);
});
