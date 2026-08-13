<?php

use App\Enums\StationType;
use App\Enums\TokenResetType;
use App\Models\HealthAide;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\StationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('authenticated users can create a service with appear on er', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'Bandage Dressing ER')
        ->set('serviceIsStandalone', true)
        ->set('serviceAppearOnEr', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'Bandage Dressing ER',
        'appear_on_er' => true,
        'token_reset_type' => TokenResetType::Shift->value,
    ]);
});

test('er station page is publicly accessible', function () {
    Shift::factory()->open()->create();

    $this->get(route('display.er'))
        ->assertSuccessful()
        ->assertSee(__('ER Station'));
});

test('er station lists appear_on_er tokens and can mark them completed', function () {
    $shift = Shift::factory()->open()->create();
    $service = Service::factory()->appearOnEr()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    $queue = ServiceQueue::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'shift_id' => $shift->id,
        'date' => today(),
        'reset_type' => TokenResetType::Shift,
        'status' => 'open',
        'opened_at' => now(),
    ]);
    $patient = Patient::factory()->create(['name' => 'Bandage Patient']);
    $token = QueueToken::factory()->create([
        'service_queue_id' => $queue->id,
        'patient_id' => $patient->id,
        'token_number' => 7,
        'status' => 'waiting',
        'arrived_at' => now(),
    ]);
    $aide = HealthAide::factory()->create(['pin' => '1234']);

    Livewire::test('pages::display.medication-delivery')
        ->assertSee($patient->name)
        ->assertSee(__('Tap to complete'))
        ->set('pin', '1234')
        ->call('verifyPin')
        ->assertHasNoErrors()
        ->call('selectServiceToken', $token->id)
        ->call('requestCompleteService')
        ->assertHasNoErrors();

    expect($token->fresh()->status)->toBe('served')
        ->and(StationSession::query()->where('station', StationType::Er)->first()?->health_aide_id)->toBe($aide->id);
});

test('er station unlock creates a station session', function () {
    Shift::factory()->open()->create();
    $aide = HealthAide::factory()->create(['pin' => '5555', 'name' => 'ER Aide']);

    Livewire::test('pages::display.medication-delivery')
        ->set('pin', '5555')
        ->call('verifyPin')
        ->assertHasNoErrors();

    $session = StationSession::query()->where('station', StationType::Er)->first();

    expect($session)->not->toBeNull()
        ->and($session->health_aide_id)->toBe($aide->id)
        ->and($session->isExpired())->toBeFalse();
});
