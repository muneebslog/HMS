<?php

use App\Enums\PrintJobStatus;
use App\Enums\SmsStatus;
use App\Enums\TokenResetType;
use App\Jobs\SendAppointmentConfirmationSms;
use App\Models\AdminNotification;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientCall;
use App\Models\PrintJob;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\QueueService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        ->origin->toBe('reservation')
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
        'origin' => 'reservation',
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
        ->origin->toBe('reservation')
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

test('receptionists can visit the patient calling page', function () {
    $user = User::factory()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.patient-calling'));

    $response->assertOk();
});

test('patient calling page lists only reserved tokens for the selected doctor', function () {
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
        'origin' => 'reservation',
    ]);

    QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $arrivedPatient->id,
        'token_number' => 2,
        'status' => 'waiting',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.patient-calling')
        ->set('selectedDoctorId', $doctor->id)
        ->assertSee($reservedPatient->name)
        ->assertSee($reservedToken->token_number)
        ->assertDontSee($arrivedPatient->name);
});

test('patient calling page renders a call link for each reservation', function () {
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
        'origin' => 'reservation',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.patient-calling')
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

test('reservation creates a queued sms log and dispatches job when a phone number is provided', function () {
    Queue::fake();

    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create(['duty_start_time' => '18:00:00']);
    consultationPrice($service, $doctor);

    Livewire::actingAs($user)
        ->test('pages::reception.reservation')
        ->set('selectedDoctorId', $doctor->id)
        ->call('selectToken', 5)
        ->set('patientName', 'SMS Patient')
        ->set('patientPhone', validPhone())
        ->call('reserve')
        ->assertHasNoErrors();

    $log = SmsLog::first();
    expect($log)->not->toBeNull()
        ->status->toBe(SmsStatus::Queued)
        ->phone->toBe(validPhone())
        ->doctor_id->toBe($doctor->id)
        ->token_number->toBe(5);

    Queue::assertPushed(function (SendAppointmentConfirmationSms $job) use ($doctor, $log) {
        return $job->phone === validPhone()
            && $job->doctor->is($doctor)
            && $job->tokenNumber === 5
            && $job->estimatedTime?->format('g:i A') === '6:20 PM'
            && $job->smsLogId === $log->id;
    });
});

test('appointment confirmation sms job marks log as failed when veevotech returns an error', function () {
    Http::fake([
        'https://api.veevotech.com/v3/sendsms' => Http::response('Error', 500),
    ]);
    config([
        'services.veevo_sms.enabled' => true,
        'services.veevo_sms.hash' => 'test-api-hash',
    ]);

    $doctor = Doctor::factory()->create();
    $estimatedTime = Carbon::parse('18:20:00');
    $log = SmsLog::factory()->queued()->create([
        'doctor_id' => $doctor->id,
        'phone' => validPhone(),
        'token_number' => 5,
    ]);

    $job = new SendAppointmentConfirmationSms(validPhone(), $doctor, 5, $estimatedTime, $log->id);
    $job->handle(app(SmsService::class));

    $log->refresh();
    expect($log)
        ->status->toBe(SmsStatus::Failed)
        ->sent_at->toBeNull()
        ->provider_response->toBe('Error');
});

test('appointment confirmation sms job sends sms via veevotech and marks log as sent', function () {
    Http::fake();
    config([
        'services.veevo_sms.enabled' => true,
        'services.veevo_sms.hash' => 'test-api-hash',
    ]);

    $doctor = Doctor::factory()->create();
    $estimatedTime = Carbon::parse('18:20:00');
    $log = SmsLog::factory()->queued()->create([
        'doctor_id' => $doctor->id,
        'phone' => validPhone(),
        'token_number' => 5,
    ]);

    $job = new SendAppointmentConfirmationSms(validPhone(), $doctor, 5, $estimatedTime, $log->id);
    $job->handle(app(SmsService::class));

    Http::assertSent(function ($request) use ($doctor) {
        return $request->url() === 'https://api.veevotech.com/v3/sendsms'
            && $request['receivernum'] === '+923001234567'
            && str_contains($request['textmessage'], 'Your appointment with Dr. '.$doctor->name)
            && str_contains($request['textmessage'], 'token #5')
            && str_contains($request['textmessage'], '6:20 PM')
            && str_contains($request['textmessage'], 'اس میں فرق آسکتا ہے');
    });

    $log->refresh();
    expect($log)
        ->status->toBe(SmsStatus::Sent)
        ->message->toContain($doctor->name)
        ->sent_at->not->toBeNull();
});

test('reservation does not send a confirmation sms when no phone number is provided', function () {
    Queue::fake();
    Http::fake();
    config([
        'services.veevo_sms.enabled' => true,
        'services.veevo_sms.hash' => 'test-api-hash',
    ]);

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

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(SmsLog::count())->toBe(0);
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

test('uncalled reservations appear in the not called today list', function () {
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
        'origin' => 'reservation',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.patient-calling')
        ->set('selectedDoctorId', $doctor->id)
        ->assertSee($patient->name)
        ->assertSee(__('Not Called Today'));
});

test('marking a reservation called creates a patient call record', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $queue = app(QueueService::class)->queueFor($service, $doctor->id, $shift);

    $patient = Patient::factory()->create(['phone' => validPhone()]);

    $token = QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'reserved',
        'origin' => 'reservation',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.patient-calling')
        ->set('selectedDoctorId', $doctor->id)
        ->call('markCalled', $token->id)
        ->assertHasNoErrors();

    $call = PatientCall::first();
    expect($call)->not->toBeNull()
        ->queue_token_id->toBe($token->id)
        ->called_by->toBe($user->id);

    expect($call->called_at)->not->toBeNull();
});

test('a called reservation is removed from the not called today list', function () {
    $user = User::factory()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = consultationService();
    $doctor = Doctor::factory()->create();
    consultationPrice($service, $doctor);

    $queue = app(QueueService::class)->queueFor($service, $doctor->id, $shift);

    $patient = Patient::factory()->create(['phone' => validPhone()]);

    $token = QueueToken::create([
        'service_queue_id' => $queue->id,
        'invoice_item_id' => null,
        'patient_id' => $patient->id,
        'token_number' => 1,
        'status' => 'reserved',
        'origin' => 'reservation',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.patient-calling')
        ->set('selectedDoctorId', $doctor->id)
        ->call('markCalled', $token->id)
        ->assertDontSeeHtml('wire:key="uncalled-'.$token->id.'"');
});
