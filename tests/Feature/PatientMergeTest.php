<?php

use App\Enums\UserRole;
use App\Models\Family;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\QueueToken;
use App\Models\UltrasoundReport;
use App\Models\User;
use App\Models\Vital;
use App\Services\PatientMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin users can visit the merge duplicates page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->get(route('admin.merge-duplicates'))
        ->assertOk();
});

test('non admin users cannot visit the merge duplicates page', function () {
    $user = User::factory()->create(['role' => UserRole::Receptionist]);

    $this->actingAs($user)
        ->get(route('admin.merge-duplicates'))
        ->assertForbidden();
});

test('duplicate phone groups only include phones with two or more patients', function () {
    $duplicateFamily = Family::factory()->create(['phone' => '03001112233']);
    Patient::factory()->forFamily($duplicateFamily)->create(['name' => 'Aisha']);
    Patient::factory()->forFamily($duplicateFamily)->create(['name' => 'Aisha Copy']);

    $soloFamily = Family::factory()->create(['phone' => '03004445566']);
    Patient::factory()->forFamily($soloFamily)->create(['name' => 'Solo']);

    $blankFamily = Family::factory()->withoutPhone()->create();
    Patient::factory()->forFamily($blankFamily)->count(2)->create();

    $groups = app(PatientMergeService::class)->duplicatePhoneGroups();

    expect($groups)->toHaveCount(1)
        ->and($groups->first()->phone)->toBe('03001112233')
        ->and($groups->first()->patients)->toHaveCount(2);
});

test('merge reassigns related records to the oldest patient and deletes losers', function () {
    $family = Family::factory()->create(['phone' => '03001234567']);
    $winner = Patient::factory()->forFamily($family)->create([
        'name' => 'Original',
        'husband_name' => null,
        'cnic' => null,
        'age' => null,
        'gender' => null,
    ]);
    $loser = Patient::factory()->forFamily($family)->create([
        'name' => 'Duplicate',
        'husband_name' => 'Ali',
        'cnic' => '12345-1234567-1',
        'age' => 28,
        'gender' => 'female',
    ]);
    $keptSibling = Patient::factory()->forFamily($family)->create(['name' => 'Sister']);

    $invoice = Invoice::factory()->create(['patient_id' => $loser->id]);
    $labInvoice = LabInvoice::factory()->create(['patient_id' => $loser->id]);
    $token = QueueToken::factory()->create(['patient_id' => $loser->id]);
    $vital = Vital::factory()->create(['patient_id' => $loser->id]);
    $procedure = Procedure::factory()->create(['patient_id' => $loser->id]);
    $ultrasound = UltrasoundReport::factory()->create(['patient_id' => $loser->id]);

    $merged = app(PatientMergeService::class)->merge([$winner->id, $loser->id]);

    expect($merged->id)->toBe($winner->id)
        ->and($merged->husband_name)->toBe('Ali')
        ->and($merged->cnic)->toBe('12345-1234567-1')
        ->and($merged->age)->toBe(28)
        ->and($merged->gender)->toBe('female')
        ->and(Patient::find($loser->id))->toBeNull()
        ->and(Patient::find($keptSibling->id))->not->toBeNull()
        ->and($invoice->fresh()->patient_id)->toBe($winner->id)
        ->and($labInvoice->fresh()->patient_id)->toBe($winner->id)
        ->and($token->fresh()->patient_id)->toBe($winner->id)
        ->and($vital->fresh()->patient_id)->toBe($winner->id)
        ->and($procedure->fresh()->patient_id)->toBe($winner->id)
        ->and($ultrasound->fresh()->patient_id)->toBe($winner->id)
        ->and($family->fresh()->patients)->toHaveCount(2);
});

test('merge leaves unchecked patients alone when only some ids are passed', function () {
    $family = Family::factory()->create(['phone' => '03009876543']);
    $first = Patient::factory()->forFamily($family)->create(['name' => 'One']);
    $second = Patient::factory()->forFamily($family)->create(['name' => 'Two']);
    $third = Patient::factory()->forFamily($family)->create(['name' => 'Three']);

    app(PatientMergeService::class)->merge([$first->id, $second->id]);

    expect(Patient::find($first->id))->not->toBeNull()
        ->and(Patient::find($second->id))->toBeNull()
        ->and(Patient::find($third->id))->not->toBeNull();
});

test('cannot merge patients from different families', function () {
    $first = Patient::factory()->withPhone('03001110000')->create();
    $second = Patient::factory()->withPhone('03002220000')->create();

    app(PatientMergeService::class)->merge([$first->id, $second->id]);
})->throws(ValidationException::class);

test('cannot merge fewer than two patients', function () {
    $patient = Patient::factory()->withPhone('03003330000')->create();

    app(PatientMergeService::class)->merge([$patient->id]);
})->throws(ValidationException::class);

test('merge duplicates page lists groups and merges selected patients', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $family = Family::factory()->create(['phone' => '03005556677']);
    $winner = Patient::factory()->forFamily($family)->create(['name' => 'Keep Me']);
    $loser = Patient::factory()->forFamily($family)->create(['name' => 'Merge Me']);
    $sibling = Patient::factory()->forFamily($family)->create(['name' => 'Leave Me']);

    Invoice::factory()->create(['patient_id' => $loser->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.merge-duplicates')
        ->assertSee('03005556677')
        ->assertSee('Keep Me')
        ->assertSee('Merge Me')
        ->assertSee('Leave Me')
        ->set('selected.'.$winner->id, true)
        ->set('selected.'.$loser->id, true)
        ->set('selected.'.$sibling->id, false)
        ->call('confirmMerge', $family->id)
        ->call('mergeConfirmed')
        ->assertSet('showConfirmModal', false);

    expect(Patient::find($winner->id))->not->toBeNull()
        ->and(Patient::find($loser->id))->toBeNull()
        ->and(Patient::find($sibling->id))->not->toBeNull()
        ->and(Invoice::where('patient_id', $winner->id)->count())->toBe(1);
});

test('merge duplicates page auto-checks same-name lookalikes only', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $family = Family::factory()->create(['phone' => '03006667788']);
    $first = Patient::factory()->forFamily($family)->create(['name' => 'Muneeb']);
    $duplicate = Patient::factory()->forFamily($family)->create(['name' => ' muneeb ']);
    $sibling = Patient::factory()->forFamily($family)->create(['name' => 'Sara']);

    Livewire::actingAs($admin)
        ->test('pages::admin.merge-duplicates')
        ->assertSet('selected.'.$first->id, true)
        ->assertSet('selected.'.$duplicate->id, true)
        ->assertSet('selected.'.$sibling->id, false);
});

test('merge all merges multiple phone groups while leaving unchecked patients alone', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $firstFamily = Family::factory()->create(['phone' => '03007778899']);
    $firstWinner = Patient::factory()->forFamily($firstFamily)->create(['name' => 'Muneeb']);
    $firstLoser = Patient::factory()->forFamily($firstFamily)->create(['name' => 'Muneeb']);
    $firstSibling = Patient::factory()->forFamily($firstFamily)->create(['name' => 'Sister']);

    $secondFamily = Family::factory()->create(['phone' => '03008889900']);
    $secondWinner = Patient::factory()->forFamily($secondFamily)->create(['name' => 'Aisha']);
    $secondLoser = Patient::factory()->forFamily($secondFamily)->create(['name' => 'Aisha']);

    Livewire::actingAs($admin)
        ->test('pages::admin.merge-duplicates')
        ->assertSet('selected.'.$firstSibling->id, false)
        ->call('confirmMergeAll')
        ->assertSet('mergingAll', true)
        ->assertSet('confirmGroupCount', 2)
        ->call('mergeConfirmed')
        ->assertSet('showConfirmModal', false);

    expect(Patient::find($firstWinner->id))->not->toBeNull()
        ->and(Patient::find($firstLoser->id))->toBeNull()
        ->and(Patient::find($firstSibling->id))->not->toBeNull()
        ->and(Patient::find($secondWinner->id))->not->toBeNull()
        ->and(Patient::find($secondLoser->id))->toBeNull();
});

test('unlink from phone detaches patient and keeps their records', function () {
    $family = Family::factory()->create(['phone' => '03009990011']);
    $kept = Patient::factory()->forFamily($family)->create(['name' => 'Kept']);
    $unlinked = Patient::factory()->forFamily($family)->create(['name' => 'Wrong Number']);
    $invoice = Invoice::factory()->create(['patient_id' => $unlinked->id]);

    $result = app(PatientMergeService::class)->unlinkFromPhone($unlinked);

    expect($result->family_id)->toBeNull()
        ->and($result->contactPhone())->toBeNull()
        ->and(Patient::find($unlinked->id))->not->toBeNull()
        ->and($kept->fresh()->family_id)->toBe($family->id)
        ->and($invoice->fresh()->patient_id)->toBe($unlinked->id)
        ->and(Family::find($family->id))->not->toBeNull();
});

test('unlink from phone deletes empty family when last patient is removed', function () {
    $family = Family::factory()->create(['phone' => '03001112200']);
    $patient = Patient::factory()->forFamily($family)->create(['name' => 'Only One']);

    app(PatientMergeService::class)->unlinkFromPhone($patient);

    expect($patient->fresh()->family_id)->toBeNull()
        ->and(Family::find($family->id))->toBeNull();
});

test('merge duplicates page can unlink a patient from the phone group', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $family = Family::factory()->create(['phone' => '03002223344']);
    $kept = Patient::factory()->forFamily($family)->create(['name' => 'Same']);
    $duplicate = Patient::factory()->forFamily($family)->create(['name' => 'Same']);
    $wrong = Patient::factory()->forFamily($family)->create(['name' => 'Other Person']);

    Livewire::actingAs($admin)
        ->test('pages::admin.merge-duplicates')
        ->assertSee('Other Person')
        ->call('unlinkFromPhone', $wrong->id)
        ->assertDontSee('Other Person');

    expect($wrong->fresh()->family_id)->toBeNull()
        ->and($kept->fresh()->family_id)->toBe($family->id)
        ->and($duplicate->fresh()->family_id)->toBe($family->id);
});
