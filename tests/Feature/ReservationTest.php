<?php

use App\Enums\PrintJobStatus;
use App\Enums\TokenResetType;
use App\Models\AdminNotification;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PrintJob;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function validPhone(): string
{
    return '03001234567';
}

function consultationService(): Service
{
    return Service::factory()->create([
        'name' => 'Consultation',
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift,
    ]);
}

function consultationPrice(Service $service, Doctor $doctor): ServicePrice
{
    return ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 250.00,
    ]);
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.reservation'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the reservation page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.reservation'));

    $response->assertOk();
});

test('a token can be reserved for a doctor', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5)
        ->set('patientName', 'Reserved Patient')
        ->set('patientPhone', validPhone())
        ->call('reserve')
        ->assertHasNoErrors();

    $queue = ServiceQueue::first();
    expect($queue)->not->toBeNull()
        ->last_token_number->toBe(5);

    $token = QueueToken::first();
    expect($token)->not->toBeNull()
        ->token_number->toBe(5)
        ->status->toBe('reserved')
        ->patient->name->toBe('Reserved Patient')
        ->patient->phone->toBe(validPhone())
        ->invoice_item_id->toBeNull();
});

test('reserving an already used token fails', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $queue = app(QueueService::class)->queueFor($service, $doctor->id, $shift);
    QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => Patient::factory()->create()->id,
        'token_number' => 3,
        'status' => 'reserved',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 3)
        ->set('patientName', 'Another Patient')
        ->set('patientPhone', validPhone())
        ->call('reserve');

    expect(QueueToken::where('token_number', 3)->count())->toBe(1);
});

test('walk-in tokens skip reserved numbers', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 1)
        ->set('patientName', 'Reserved Patient')
        ->set('patientPhone', validPhone())
        ->call('reserve');

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'Walk-in Patient')
        ->set('selectedServiceId', $service->id)
        ->set('selectedDoctorId', $doctor->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::whereHas('patient', fn ($q) => $q->where('name', 'Walk-in Patient'))->first();
    expect($invoice->items->first()->queueToken->token_number)->toBe(2);
});

test('marking a reservation arrived creates an invoice and links the token', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $component = Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 4)
        ->set('patientName', 'Phone Patient')
        ->set('patientPhone', validPhone())
        ->call('reserve');

    $token = QueueToken::first();

    $component->call('selectToken', 4)
        ->call('markArrived')
        ->assertHasNoErrors();

    $invoice = Invoice::first();

    expect(PrintJob::count())->toBe(1);
    expect(PrintJob::first())
        ->invoice_id->toBe($invoice->id)
        ->status->toBe(PrintJobStatus::Pending);
    expect($invoice)->not->toBeNull()
        ->patient->name->toBe('Phone Patient')
        ->total->toBe(250.00)
        ->status->toBe('paid');

    $token->refresh();
    expect($token)
        ->status->toBe('waiting')
        ->invoice_item_id->not->toBeNull()
        ->invoiceItem->invoice_id->toBe($invoice->id);
});

test('phone is required for reservation', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5)
        ->set('patientName', 'Reserved Patient')
        ->set('patientPhone', '')
        ->call('reserve')
        ->assertHasErrors(['patientPhone']);

    expect(QueueToken::count())->toBe(0);
});

test('phone must be exactly 11 digits', function (string $phone) {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5)
        ->set('patientName', 'Reserved Patient')
        ->set('patientPhone', $phone)
        ->call('reserve')
        ->assertHasErrors(['patientPhone']);

    expect(QueueToken::count())->toBe(0);
})->with([
    'ten digits' => '0300123456',
    'twelve digits' => '030012345678',
    'letters' => '0300abc4567',
]);

test('receptionists can visit the doctor reservations page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.doctor-reservations'));

    $response->assertOk();
});

test('doctor reservations page lists only reserved tokens for the selected doctor', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $queue = app(QueueService::class)->queueFor($service, $doctor->id, $shift);

    $reservedPatient = Patient::factory()->create(['phone' => validPhone()]);
    $arrivedPatient = Patient::factory()->create();

    $reservedToken = QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $reservedPatient->id,
        'token_number' => 1,
        'status' => 'reserved',
    ]);

    QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $arrivedPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.doctor-reservations')
        ->set('selectedDoctorId', $doctor->id)
        ->assertSee($reservedPatient->name)
        ->assertSee($reservedToken->token_number)
        ->assertDontSee($arrivedPatient->name);
});

test('doctor reservations page renders a call link for each reservation', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $queue = app(QueueService::class)->queueFor($service, $doctor->id, $shift);

    $patient = Patient::factory()->create(['phone' => validPhone()]);

    QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'reserved',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.doctor-reservations')
        ->set('selectedDoctorId', $doctor->id)
        ->assertSeeHtml('href="tel:'.validPhone().'"');
});

test('a token can be reserved without a phone number', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5)
        ->set('patientName', 'No Phone Patient')
        ->set('hasNoPhone', true)
        ->call('reserve')
        ->assertHasNoErrors();

    $token = QueueToken::first();
    expect($token)->not->toBeNull()
        ->token_number->toBe(5)
        ->status->toBe('reserved')
        ->patient->name->toBe('No Phone Patient')
        ->patient->phone->toBeNull();
});

test('reserving without a phone number logs an admin notification', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5)
        ->set('patientName', 'No Phone Patient')
        ->set('hasNoPhone', true)
        ->call('reserve')
        ->assertHasNoErrors();

    $notification = AdminNotification::first();
    expect($notification)->not->toBeNull()
        ->user_id->toBe($user->id)
        ->type->toBe('reservation_without_phone')
        ->read_at->toBeNull();

    expect($notification->message)->toContain('No Phone Patient');
    expect($notification->message)->toContain((string) $user->name);
});

test('reservation page shows estimated token times when doctor has a duty start time', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create(['duty_start_time' => '18:00:00']);
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->assertSee('6:00 PM')
        ->assertSee('6:05 PM')
        ->assertSee('6:10 PM');
});

test('reservation page hides estimated token times when doctor has no duty start time', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create(['duty_start_time' => null]);
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->assertDontSee('PM')
        ->assertDontSee('AM');
});

test('management cannot create a second consultation service', function () {
    $user = User::factory()->create();
    Service::factory()->create(['name' => 'Consultation']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'consultation')
        ->set('serviceIsStandalone', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasErrors(['serviceName']);

    expect(Service::whereRaw('LOWER(name) = ?', ['consultation'])->count())->toBe(1);
});
