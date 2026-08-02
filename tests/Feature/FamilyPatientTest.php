<?php

use App\Models\AdminNotification;
use App\Models\Family;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('families store a unique phone and can have many patients', function () {
    $family = Family::factory()->create(['phone' => '03001234567']);
    $first = Patient::factory()->forFamily($family)->create(['name' => 'Daughter']);
    $second = Patient::factory()->forFamily($family)->create(['name' => 'Mother']);

    expect($family->fresh()->patients)->toHaveCount(2)
        ->and($first->contactPhone())->toBe('03001234567')
        ->and($second->contactPhone())->toBe('03001234567')
        ->and($first->family_id)->toBe($family->id)
        ->and($second->family_id)->toBe($family->id);
});

test('patient intake reuses an existing family phone for a new member', function () {
    $family = Family::factory()->create(['phone' => '03005556666']);
    Patient::factory()->forFamily($family)->create(['name' => 'Existing Member']);

    $service = app(PatientIntakeService::class);
    $resolvedFamily = $service->findFamilyByPhone('03005556666');

    expect($resolvedFamily)->not->toBeNull()
        ->and($resolvedFamily->patients)->toHaveCount(1);

    $patient = $service->resolvePatient(null, '03005556666', [
        'name' => 'New Member',
        'age' => 22,
        'gender' => 'female',
    ]);

    expect($patient->family_id)->toBe($family->id)
        ->and($patient->contactPhone())->toBe('03005556666')
        ->and($family->fresh()->patients)->toHaveCount(2);
});

test('patient intake selects an existing patient by id', function () {
    $existing = Patient::factory()->withPhone('03007778888')->create(['name' => 'Known Patient']);

    $patient = app(PatientIntakeService::class)->resolvePatient(
        $existing->id,
        '03007778888',
        ['name' => 'Known Patient'],
    );

    expect($patient->id)->toBe($existing->id)
        ->and(Patient::count())->toBe(1);
});

test('patient intake creates a patient without a family when phone is skipped', function () {
    $user = User::factory()->create();

    $patient = app(PatientIntakeService::class)->resolvePatient(null, null, [
        'name' => 'Elderly Patient',
    ]);

    expect($patient->family_id)->toBeNull()
        ->and($patient->contactPhone())->toBeNull();

    app(PatientIntakeService::class)->notifyWithoutPhone($user, $patient, 'walk_in');

    expect(AdminNotification::where('type', 'patient_without_phone')->count())->toBe(1);
});

test('updating contact phone creates or updates the family phone', function () {
    $patient = Patient::factory()->create(['name' => 'Solo Patient']);

    app(PatientIntakeService::class)->updateContactPhone($patient, '03001112222');

    expect($patient->fresh()->contactPhone())->toBe('03001112222')
        ->and(Family::where('phone', '03001112222')->exists())->toBeTrue();

    app(PatientIntakeService::class)->updateContactPhone($patient->fresh(), null);

    expect($patient->fresh()->family_id)->toBeNull()
        ->and($patient->fresh()->contactPhone())->toBeNull();
});
