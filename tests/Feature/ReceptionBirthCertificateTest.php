<?php

use App\Enums\BirthMultiplicity;
use App\Enums\LivingStatus;
use App\Enums\ProcedureDocumentKind;
use App\Models\Procedure;
use App\Models\ProcedureBirthCertificateDetail;
use App\Models\ProcedureDocument;
use App\Models\ProcedureType;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('birth certificate button opens the details form from a procedure card', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create([
        'name' => 'Normal Delivery',
    ]);
    $procedure->patient->update([
        'name' => 'Ayesha Khan',
        'husband_name' => 'Ahmed Khan',
        'age' => 28,
        'cnic' => '35202-1111111-1',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('3. Payment Ledger')
        ->assertSee('4. Birth Certificate')
        ->call('openBirthCertificate', $procedure->id)
        ->assertSet('showViewModal', false)
        ->assertSet('showBirthCertificateModal', true)
        ->assertSet('bcFatherName', 'Ahmed Khan')
        ->assertSet('bcMotherName', 'Ayesha Khan')
        ->assertSet('bcMotherAge', 28)
        ->assertSet('bcMotherCnic', '35202-1111111-1')
        ->assertSee('Birth Certificate Details')
        ->assertSee('Father Name')
        ->assertSee('Mother\'s Father Name')
        ->assertSee('This Birth');
});

test('saved birth certificate opens print view while edit opens the form', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create();
    ProcedureBirthCertificateDetail::factory()->create([
        'procedure_id' => $procedure->id,
        'father_name' => 'Ali Raza',
        'mother_name' => 'Sara Ali',
        'born_at' => '2026-09-04',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('viewProcedure', $procedure->id)
        ->assertSee('Edit details')
        ->call('openBirthCertificate', $procedure->id)
        ->assertSet('showBirthCertificateModal', false)
        ->assertSet('showViewModal', true)
        ->call('editBirthCertificate', $procedure->id)
        ->assertSet('showViewModal', false)
        ->assertSet('showBirthCertificateModal', true)
        ->assertSet('bcFatherName', 'Ali Raza')
        ->assertSet('bcMotherName', 'Sara Ali')
        ->assertSet('bcBornAt', '2026-09-04');
});

test('birth certificate details can be saved and printed from reception', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('openBirthCertificate', $procedure->id)
        ->set('bcFatherName', 'Ali Raza')
        ->set('bcMotherName', 'Sara Ali')
        ->set('bcGrandfatherName', 'Muhammad Raza')
        ->set('bcMaternalGrandfatherName', 'Imran Ali')
        ->set('bcFatherAge', 32)
        ->set('bcMotherAge', 27)
        ->set('bcFatherCnic', '35202-2222222-2')
        ->set('bcMotherCnic', '35202-3333333-3')
        ->set('bcHomeAddress', 'House 12, Lahore')
        ->set('bcBornAt', '2026-09-04')
        ->set('bcSex', 'female')
        ->set('bcStatus', LivingStatus::Living->value)
        ->set('bcBabyName', 'Fatima')
        ->set('bcMultiplicity', BirthMultiplicity::Single->value)
        ->call('saveBirthCertificate')
        ->assertHasNoErrors()
        ->assertSet('showBirthCertificateModal', false);

    $detail = ProcedureBirthCertificateDetail::query()->where('procedure_id', $procedure->id)->first();

    expect($detail)->not->toBeNull()
        ->and($detail->father_name)->toBe('Ali Raza')
        ->and($detail->mother_name)->toBe('Sara Ali')
        ->and($detail->grandfather_name)->toBe('Muhammad Raza')
        ->and($detail->maternal_grandfather_name)->toBe('Imran Ali')
        ->and($detail->father_age)->toBe(32)
        ->and($detail->mother_age)->toBe(27)
        ->and($detail->father_cnic)->toBe('35202-2222222-2')
        ->and($detail->mother_cnic)->toBe('35202-3333333-3')
        ->and($detail->home_address)->toBe('House 12, Lahore')
        ->and($detail->sex)->toBe('female')
        ->and($detail->status)->toBe(LivingStatus::Living)
        ->and($detail->baby_name)->toBe('Fatima')
        ->and($detail->multiplicity)->toBe(BirthMultiplicity::Single)
        ->and($detail->child_order)->toBeNull()
        ->and($detail->recorded_by)->toBe($user->id);

    config([
        'hospital.address' => 'Peer Colony, St. # 1, Walton Road, Lahore.',
        'hospital.phone' => '0320-8489685 , 042-3662345',
        'hospital.email' => 'mmcwalton@gmail.com',
    ]);

    $this->actingAs($user)
        ->get(route('indoor.procedures.birth-certificate', $procedure))
        ->assertOk()
        ->assertSee('Ali Raza')
        ->assertSee('Sara Ali')
        ->assertSee('Muhammad Raza')
        ->assertSee('Imran Ali')
        ->assertSee('Fatima')
        ->assertSee('House 12, Lahore')
        ->assertSee('Friday, 04 Sep 2026')
        ->assertDontSee('10:30')
        ->assertSee('IN WITNESS WHEREOF')
        ->assertSee('Staff Nurse / Midwife')
        ->assertSee('Peer Colony, St. # 1, Walton Road, Lahore.')
        ->assertSee('0320-8489685 , 042-3662345')
        ->assertSee('mmcwalton@gmail.com')
        ->assertSee('BC-'.str_pad((string) $procedure->id, 6, '0', STR_PAD_LEFT))
        ->assertSee('aria-label="BC-'.str_pad((string) $procedure->id, 6, '0', STR_PAD_LEFT).'"', false)
        ->assertDontSee('>'.__('MRN').'<', false)
        ->assertDontSee(__('Place of Birth'));

    expect(ProcedureDocument::query()
        ->where('procedure_id', $procedure->id)
        ->where('kind', ProcedureDocumentKind::BirthCertificate)
        ->exists())->toBeTrue();
});

test('twin birth certificate requires which child was born', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $procedure = Procedure::factory()->for($shift)->create();

    Livewire::actingAs($user)
        ->test('pages::reception.procedures')
        ->call('openBirthCertificate', $procedure->id)
        ->set('bcFatherName', 'Ali Raza')
        ->set('bcMotherName', 'Sara Ali')
        ->set('bcGrandfatherName', 'Muhammad Raza')
        ->set('bcMaternalGrandfatherName', 'Imran Ali')
        ->set('bcFatherAge', 32)
        ->set('bcMotherAge', 27)
        ->set('bcFatherCnic', '35202-2222222-2')
        ->set('bcMotherCnic', '35202-3333333-3')
        ->set('bcHomeAddress', 'House 12, Lahore')
        ->set('bcBornAt', '2026-09-04')
        ->set('bcSex', 'male')
        ->set('bcStatus', LivingStatus::Living->value)
        ->set('bcMultiplicity', BirthMultiplicity::Twin->value)
        ->set('bcChildOrder', null)
        ->call('saveBirthCertificate')
        ->assertHasErrors(['bcChildOrder'])
        ->set('bcChildOrder', 2)
        ->call('saveBirthCertificate')
        ->assertHasNoErrors();

    $detail = ProcedureBirthCertificateDetail::query()->where('procedure_id', $procedure->id)->first();

    expect($detail->multiplicity)->toBe(BirthMultiplicity::Twin)
        ->and($detail->child_order)->toBe(2);
});

test('birth certificate remains available for delivery types without saved details', function () {
    $user = User::factory()->indoor()->create();
    $type = ProcedureType::factory()->delivery()->create();
    $procedure = Procedure::factory()->admitted()->for($type)->create();

    $this->actingAs($user)
        ->get(route('indoor.procedures.birth-certificate', $procedure))
        ->assertOk()
        ->assertSee('Birth Certificate')
        ->assertSee('IN WITNESS WHEREOF');
});
