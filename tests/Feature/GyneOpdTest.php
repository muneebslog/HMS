<?php

use App\Enums\GyneOperation;
use App\Enums\GyneProblem;
use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\GyneHistory;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Create a token in the given doctor's queue for the shift.
 *
 * @param  array<string, mixed>  $attributes
 */
function createGyneOpdToken(Doctor $doctor, Shift $shift, string $patientName, array $attributes = []): QueueToken
{
    $queue = ServiceQueue::query()->firstOrCreate(
        ['doctor_id' => $doctor->id, 'shift_id' => $shift->id],
        [
            'service_id' => Service::factory()->create(['token_reset_type' => TokenResetType::Shift])->id,
            'date' => today(),
            'reset_type' => TokenResetType::Shift,
            'status' => 'open',
            'opened_at' => now(),
        ],
    );

    return QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => Patient::factory()->create(['name' => $patientName])->id,
        'token_number' => $queue->tokens()->count() + 1,
        'status' => 'waiting',
        'arrived_at' => now()->subMinutes(10),
        ...$attributes,
    ]);
}

test('guests are redirected to login', function () {
    $this->get(route('gyne.opd'))->assertRedirect(route('login'));
});

test('a gynecologist can open todays opd', function () {
    $user = User::factory()->doctor()->create();
    Doctor::factory()->gynecologist()->forUser($user)->create();

    $this->actingAs($user)
        ->get(route('gyne.opd'))
        ->assertOk()
        ->assertSee("Today's OPD");
});

test('a non gynecologist doctor is forbidden', function () {
    $user = User::factory()->doctor()->create();
    Doctor::factory()->forUser($user)->create();

    $this->actingAs($user)->get(route('gyne.opd'))->assertForbidden();
});

test('a gynecologist sees only their own arrived waiting and serving patients', function () {
    $user = User::factory()->doctor()->create();
    $gynecologist = Doctor::factory()->gynecologist()->forUser($user)->create();
    $otherDoctor = Doctor::factory()->gynecologist()->create();
    $shift = Shift::factory()->open()->create();

    $waiting = createGyneOpdToken($gynecologist, $shift, 'WAITING PATIENT');
    Vital::factory()->create([
        'queue_token_id' => $waiting->id,
        'patient_id' => $waiting->patient_id,
        'bp_systolic' => 120,
        'bp_diastolic' => 80,
    ]);
    createGyneOpdToken($gynecologist, $shift, 'SERVING PATIENT', ['status' => 'serving']);
    createGyneOpdToken($gynecologist, $shift, 'RESERVED PATIENT', ['status' => 'reserved', 'arrived_at' => null]);
    createGyneOpdToken($gynecologist, $shift, 'SERVED PATIENT', ['status' => 'served']);
    createGyneOpdToken($otherDoctor, $shift, 'OTHER DOCTOR PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.opd')
        ->assertSee('WAITING PATIENT')
        ->assertSee('BP 120/80')
        ->assertSee('SERVING PATIENT')
        ->assertDontSee('RESERVED PATIENT')
        ->assertDontSee('SERVED PATIENT')
        ->assertDontSee('OTHER DOCTOR PATIENT');
});

test('patients from older shifts are not listed', function () {
    $user = User::factory()->doctor()->create();
    $gynecologist = Doctor::factory()->gynecologist()->forUser($user)->create();
    $oldShift = Shift::factory()->create(['opened_at' => now()->subDays(2), 'closed_at' => now()->subDays(2)->addHours(8)]);
    $shift = Shift::factory()->open()->create();

    createGyneOpdToken($gynecologist, $oldShift, 'OLD SHIFT PATIENT');
    createGyneOpdToken($gynecologist, $shift, 'CURRENT SHIFT PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.opd')
        ->assertSee('CURRENT SHIFT PATIENT')
        ->assertDontSee('OLD SHIFT PATIENT');
});

test('admins see arrived patients of every gynecologist but not other doctors', function () {
    $admin = User::factory()->admin()->create();
    $firstGynecologist = Doctor::factory()->gynecologist()->create();
    $secondGynecologist = Doctor::factory()->gynecologist()->create();
    $generalDoctor = Doctor::factory()->create();
    $shift = Shift::factory()->open()->create();

    createGyneOpdToken($firstGynecologist, $shift, 'FIRST GYNE PATIENT');
    createGyneOpdToken($secondGynecologist, $shift, 'SECOND GYNE PATIENT');
    createGyneOpdToken($generalDoctor, $shift, 'GENERAL OPD PATIENT');

    Livewire::actingAs($admin)
        ->test('pages::gyne.opd')
        ->assertSee('FIRST GYNE PATIENT')
        ->assertSee('SECOND GYNE PATIENT')
        ->assertDontSee('GENERAL OPD PATIENT');
});

test('the doctor sees the assistants history summary on the card and in the modal', function () {
    $user = User::factory()->doctor()->create();
    $gynecologist = Doctor::factory()->gynecologist()->forUser($user)->create();
    $shift = Shift::factory()->open()->create();
    $token = createGyneOpdToken($gynecologist, $shift, 'HISTORY PATIENT');
    $assistant = User::factory()->gyneAssistant()->create(['name' => 'Assistant Sana']);
    GyneHistory::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $token->patient_id,
        'gravida' => 3,
        'para' => 2,
        'miscarriages' => 1,
        'living_children' => 2,
        'lmp' => now()->subWeeks(20)->toDateString(),
        'problems' => [GyneProblem::Diabetes->value],
        'operations' => [GyneOperation::CSection->value],
        'recorded_by' => $assistant->id,
    ]);
    $previousToken = QueueToken::factory()->create(['patient_id' => $token->patient_id, 'status' => 'served']);
    GyneHistory::factory()->create([
        'queue_token_id' => $previousToken->id,
        'patient_id' => $token->patient_id,
        'gravida' => 2,
        'para' => 1,
        'miscarriages' => 1,
        'living_children' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('pages::gyne.opd')
        ->assertSee('G3 P2 A1 L2')
        ->assertSee('20+0 wks')
        ->assertSee('C-section')
        ->assertSee('Diabetes')
        ->call('openHistory', $token->id)
        ->assertSet('showHistoryModal', true)
        ->assertSee('Assistant Sana')
        ->assertSee('Previous visits');
});

test('the doctor can correct the history', function () {
    $user = User::factory()->doctor()->create();
    $gynecologist = Doctor::factory()->gynecologist()->forUser($user)->create();
    $token = createGyneOpdToken($gynecologist, Shift::factory()->open()->create(), 'EDIT PATIENT');
    $history = GyneHistory::factory()->create(['queue_token_id' => $token->id, 'patient_id' => $token->patient_id, 'gravida' => 2]);

    Livewire::actingAs($user)
        ->test('pages::gyne.opd')
        ->call('openHistory', $token->id)
        ->call('editHistory')
        ->assertSet('gravida', 2)
        ->set('gravida', 5)
        ->call('saveHistory')
        ->assertHasNoErrors()
        ->assertSet('isEditingHistory', false);

    expect($history->refresh()->gravida)->toBe(5)
        ->and($history->updated_by)->toBe($user->id);
});

test('a patient without a history shows as not taken', function () {
    $user = User::factory()->doctor()->create();
    $gynecologist = Doctor::factory()->gynecologist()->forUser($user)->create();
    createGyneOpdToken($gynecologist, Shift::factory()->open()->create(), 'NEW PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.opd')
        ->assertSee('History not taken yet');
});
