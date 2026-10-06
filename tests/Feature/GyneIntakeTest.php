<?php

use App\Enums\GyneOperation;
use App\Enums\GyneProblem;
use App\Enums\TokenResetType;
use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\GyneHistory;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

/**
 * Create an arrived token in the given doctor's queue for the shift.
 *
 * @param  array<string, mixed>  $attributes
 */
function createGyneIntakeToken(Doctor $doctor, Shift $shift, string $patientName, array $attributes = []): QueueToken
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

test('the gyne assistant role has a label and factory state', function () {
    $user = User::factory()->gyneAssistant()->create();

    expect(UserRole::GyneAssistant->label())->toBe('Gyne Assistant')
        ->and($user->isGyneAssistant())->toBeTrue();
});

test('a gyne assistant can open the intake page and is sent there from the dashboard', function () {
    $user = User::factory()->gyneAssistant()->create();

    $this->actingAs($user)->get(route('gyne.intake'))->assertOk()->assertSee('Gyne Intake');

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertRedirect(route('gyne.intake'));
});

test('a gyne assistant cannot open the doctor opd page', function () {
    $user = User::factory()->gyneAssistant()->create();

    $this->actingAs($user)->get(route('gyne.opd'))->assertForbidden();
});

test('receptionists cannot open the intake page by default', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)->get(route('gyne.intake'))->assertForbidden();
});

test('intake lists arrived patients of every gynecologist with their history status', function () {
    $user = User::factory()->gyneAssistant()->create();
    $shift = Shift::factory()->open()->create();
    $firstGynecologist = Doctor::factory()->gynecologist()->create();
    $secondGynecologist = Doctor::factory()->gynecologist()->create();

    $done = createGyneIntakeToken($firstGynecologist, $shift, 'DONE PATIENT');
    GyneHistory::factory()->create(['queue_token_id' => $done->id, 'patient_id' => $done->patient_id]);
    createGyneIntakeToken($secondGynecologist, $shift, 'PENDING PATIENT');
    createGyneIntakeToken(Doctor::factory()->create(), $shift, 'GENERAL PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.intake')
        ->assertSee('DONE PATIENT')
        ->assertSee('PENDING PATIENT')
        ->assertDontSee('GENERAL PATIENT')
        ->assertSee('1 / 2');
});

test('the assistant saves a history and moves to the next pending patient', function () {
    $user = User::factory()->gyneAssistant()->create();
    $shift = Shift::factory()->open()->create();
    $gynecologist = Doctor::factory()->gynecologist()->create();
    $first = createGyneIntakeToken($gynecologist, $shift, 'FIRST PATIENT', ['token_number' => 1]);
    $second = createGyneIntakeToken($gynecologist, $shift, 'SECOND PATIENT', ['token_number' => 2, 'arrived_at' => now()->subMinutes(5)]);
    $lmp = now()->subWeeks(14)->subDays(2)->toDateString();

    Livewire::actingAs($user)
        ->test('pages::gyne.intake')
        ->call('selectToken', $first->id)
        ->set('gravida', 3)
        ->set('para', 2)
        ->set('livingChildren', 2)
        ->set('miscarriages', 0)
        ->set('marriedSince', '2018-04')
        ->set('lastPregnancyAt', '2023-11')
        ->set('lmp', $lmp)
        ->assertSee('14+2 wks')
        ->set('problems', [GyneProblem::HighBloodPressure->value])
        ->set('operations', [GyneOperation::CSection->value])
        ->set('operationsOther', 'Hernia repair')
        ->call('saveAndNext')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', $second->id);

    $history = GyneHistory::query()->where('queue_token_id', $first->id)->sole();

    expect($history->patient_id)->toBe($first->patient_id)
        ->and($history->gravida)->toBe(3)
        ->and($history->para)->toBe(2)
        ->and($history->living_children)->toBe(2)
        ->and($history->miscarriages)->toBe(0)
        ->and($history->married_since->toDateString())->toBe('2018-04-01')
        ->and($history->last_pregnancy_at->toDateString())->toBe('2023-11-01')
        ->and($history->lmp->toDateString())->toBe($lmp)
        ->and($history->problems)->toBe([GyneProblem::HighBloodPressure->value])
        ->and($history->operations)->toBe([GyneOperation::CSection->value])
        ->and($history->operations_other)->toBe('Hernia repair')
        ->and($history->recorded_by)->toBe($user->id)
        ->and($history->obstetricSummary())->toBe('G3 P2 A0 L2')
        ->and($history->gestationalAgeLabel())->toBe('14+2 wks')
        ->and($history->expectedDeliveryDate()->toDateString())->toBe(now()->parse($lmp)->addDays(280)->toDateString());
});

test('saving the last pending patient returns to the list', function () {
    $user = User::factory()->gyneAssistant()->create();
    $token = createGyneIntakeToken(Doctor::factory()->gynecologist()->create(), Shift::factory()->open()->create(), 'ONLY PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.intake')
        ->call('selectToken', $token->id)
        ->set('gravida', 1)
        ->call('saveAndNext')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', null);
});

test('the form is prefilled from the patients previous visit', function () {
    $user = User::factory()->gyneAssistant()->create();
    $gynecologist = Doctor::factory()->gynecologist()->create();
    $token = createGyneIntakeToken($gynecologist, Shift::factory()->open()->create(), 'RETURNING PATIENT');
    GyneHistory::factory()->create([
        'patient_id' => $token->patient_id,
        'gravida' => 4,
        'married_since' => '2015-06-01',
        'operations' => [GyneOperation::CSection->value],
    ]);

    Livewire::actingAs($user)
        ->test('pages::gyne.intake')
        ->call('selectToken', $token->id)
        ->assertSet('gravida', 4)
        ->assertSet('marriedSince', '2015-06')
        ->assertSet('operations', [GyneOperation::CSection->value]);
});

test('future dates and unknown tags are rejected', function () {
    $user = User::factory()->gyneAssistant()->create();
    $token = createGyneIntakeToken(Doctor::factory()->gynecologist()->create(), Shift::factory()->open()->create(), 'PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.intake')
        ->call('selectToken', $token->id)
        ->set('lmp', now()->addDay()->toDateString())
        ->set('marriedSince', now()->addMonths(2)->format('Y-m'))
        ->set('problems', ['not_a_problem'])
        ->call('saveAndNext')
        ->assertHasErrors(['lmp', 'marriedSince', 'problems.0']);

    expect(GyneHistory::query()->count())->toBe(0);
});

test('gestational age and edd are hidden once the lmp is too old to be a pregnancy', function () {
    $history = new GyneHistory(['lmp' => now()->subYear()]);

    expect($history->gestationalAge())->toBeNull()
        ->and($history->expectedDeliveryDate())->toBeNull();
});
