<?php

use App\Enums\FetalPresentation;
use App\Enums\LiquorVolume;
use App\Enums\PlacentaPosition;
use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\GyneHistory;
use App\Models\GyneUltrasound;
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
function createGyneUltrasoundToken(Doctor $doctor, Shift $shift, string $patientName, array $attributes = []): QueueToken
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

test('a gyne assistant can open the ultrasound page', function () {
    $user = User::factory()->gyneAssistant()->create();

    $this->actingAs($user)->get(route('gyne.ultrasound'))->assertOk()->assertSee('Gyne Ultrasound');
});

test('receptionists cannot open the ultrasound page by default', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)->get(route('gyne.ultrasound'))->assertForbidden();
});

test('the list shows gyne patients with their ultrasound status', function () {
    $user = User::factory()->gyneAssistant()->create();
    $shift = Shift::factory()->open()->create();
    $gynecologist = Doctor::factory()->gynecologist()->create();

    $done = createGyneUltrasoundToken($gynecologist, $shift, 'SCANNED PATIENT');
    GyneUltrasound::factory()->create(['queue_token_id' => $done->id, 'patient_id' => $done->patient_id]);
    $pending = createGyneUltrasoundToken($gynecologist, $shift, 'PENDING PATIENT');
    GyneHistory::factory()->create(['queue_token_id' => $pending->id, 'patient_id' => $pending->patient_id, 'lmp' => now()->subWeeks(20)->toDateString()]);
    createGyneUltrasoundToken(Doctor::factory()->create(), $shift, 'GENERAL PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.ultrasound')
        ->assertSee('SCANNED PATIENT')
        ->assertSeeInOrder(['PENDING PATIENT', 'LMP 20+0 wks', 'Pending'])
        ->assertDontSee('GENERAL PATIENT')
        ->assertSee('1 / 2');
});

test('the assistant saves an ultrasound report and moves to the next pending patient', function () {
    $user = User::factory()->gyneAssistant()->create();
    $shift = Shift::factory()->open()->create();
    $gynecologist = Doctor::factory()->gynecologist()->create();
    $first = createGyneUltrasoundToken($gynecologist, $shift, 'FIRST PATIENT', ['token_number' => 1]);
    $second = createGyneUltrasoundToken($gynecologist, $shift, 'SECOND PATIENT', ['token_number' => 2, 'arrived_at' => now()->subMinutes(5)]);
    $expectedEdd = today()->addDays(280 - (24 * 7 + 3));

    Livewire::actingAs($user)
        ->test('pages::gyne.ultrasound')
        ->call('selectToken', $first->id)
        ->set('fetusCount', 1)
        ->set('gaWeeks', 24)
        ->set('gaDays', 3)
        ->assertSee('EDD by scan '.$expectedEdd->format('d M Y'))
        ->set('fetalHeartRate', 145)
        ->set('efwGrams', 650)
        ->set('presentation', FetalPresentation::Breech->value)
        ->set('placenta', PlacentaPosition::Posterior->value)
        ->set('liquor', LiquorVolume::Adequate->value)
        ->set('afi', '12.5')
        ->set('impression', '  Single live intrauterine pregnancy  ')
        ->call('saveAndNext')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', $second->id);

    $ultrasound = GyneUltrasound::query()->where('queue_token_id', $first->id)->sole();

    expect($ultrasound->patient_id)->toBe($first->patient_id)
        ->and($ultrasound->scanned_on->toDateString())->toBe(today()->toDateString())
        ->and($ultrasound->fetus_count)->toBe(1)
        ->and($ultrasound->gestationalAgeLabel())->toBe('24+3 wks')
        ->and($ultrasound->expectedDeliveryDate()->toDateString())->toBe($expectedEdd->toDateString())
        ->and($ultrasound->fetal_heart_rate)->toBe(145)
        ->and($ultrasound->efw_grams)->toBe(650)
        ->and($ultrasound->presentation)->toBe(FetalPresentation::Breech)
        ->and($ultrasound->placenta)->toBe(PlacentaPosition::Posterior)
        ->and($ultrasound->liquor)->toBe(LiquorVolume::Adequate)
        ->and($ultrasound->afi)->toBe('12.5')
        ->and($ultrasound->impression)->toBe('Single live intrauterine pregnancy')
        ->and($ultrasound->recorded_by)->toBe($user->id)
        ->and($ultrasound->concernLabels())->toBe(['Breech']);
});

test('saving the last pending patient returns to the list', function () {
    $user = User::factory()->gyneAssistant()->create();
    $token = createGyneUltrasoundToken(Doctor::factory()->gynecologist()->create(), Shift::factory()->open()->create(), 'ONLY PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.ultrasound')
        ->call('selectToken', $token->id)
        ->set('gaWeeks', 10)
        ->call('saveAndNext')
        ->assertHasNoErrors()
        ->assertSet('selectedTokenId', null);

    expect(GyneUltrasound::query()->sole()->ga_days)->toBe(0);
});

test('reopening a patient prefills this visits report and saving updates it', function () {
    $user = User::factory()->gyneAssistant()->create();
    $token = createGyneUltrasoundToken(Doctor::factory()->gynecologist()->create(), Shift::factory()->open()->create(), 'PATIENT');
    $ultrasound = GyneUltrasound::factory()->create([
        'queue_token_id' => $token->id,
        'patient_id' => $token->patient_id,
        'fetal_heart_rate' => 130,
        'liquor' => LiquorVolume::Reduced,
    ]);

    Livewire::actingAs($user)
        ->test('pages::gyne.ultrasound')
        ->call('selectToken', $token->id)
        ->assertSet('fetalHeartRate', 130)
        ->assertSet('liquor', LiquorVolume::Reduced->value)
        ->set('fetalHeartRate', 138)
        ->call('saveAndNext')
        ->assertHasNoErrors();

    expect(GyneUltrasound::query()->count())->toBe(1)
        ->and($ultrasound->refresh()->fetal_heart_rate)->toBe(138)
        ->and($ultrasound->updated_by)->toBe($user->id);
});

test('a previous visits scan is not carried into a new visit', function () {
    $user = User::factory()->gyneAssistant()->create();
    $token = createGyneUltrasoundToken(Doctor::factory()->gynecologist()->create(), Shift::factory()->open()->create(), 'RETURNING PATIENT');
    GyneUltrasound::factory()->create(['patient_id' => $token->patient_id, 'fetal_heart_rate' => 150]);

    Livewire::actingAs($user)
        ->test('pages::gyne.ultrasound')
        ->call('selectToken', $token->id)
        ->assertSet('fetalHeartRate', null);
});

test('out of range values and unknown options are rejected', function () {
    $user = User::factory()->gyneAssistant()->create();
    $token = createGyneUltrasoundToken(Doctor::factory()->gynecologist()->create(), Shift::factory()->open()->create(), 'PATIENT');

    Livewire::actingAs($user)
        ->test('pages::gyne.ultrasound')
        ->call('selectToken', $token->id)
        ->set('gaWeeks', 50)
        ->set('gaDays', 9)
        ->set('fetalHeartRate', 400)
        ->set('placenta', 'sideways')
        ->call('saveAndNext')
        ->assertHasErrors(['gaWeeks', 'gaDays', 'fetalHeartRate', 'placenta']);

    expect(GyneUltrasound::query()->count())->toBe(0);
});

test('abnormal findings are flagged for the doctor', function () {
    $ultrasound = new GyneUltrasound([
        'fetal_heart_rate' => 95,
        'presentation' => FetalPresentation::Transverse,
        'placenta' => PlacentaPosition::LowLying,
        'liquor' => LiquorVolume::Increased,
    ]);

    expect($ultrasound->concernLabels())->toBe(['FHR 95', 'Transverse', 'Placenta Low lying', 'Liquor Increased']);
});
