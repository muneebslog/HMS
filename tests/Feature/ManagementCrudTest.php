<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\LabTest;
use App\Models\ProcedureType;
use App\Models\ProcedureTypeDocument;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('management.crud'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the management page', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->get(route('management.crud'));

    $response->assertOk();
});

test('management page displays current server time', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->get(route('management.crud'));

    $response->assertOk();
    $response->assertSee(now()->format('Y-m-d'));
});

test('authenticated users can create a doctor', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('create')
        ->set('doctorName', 'Dr. Smith')
        ->set('doctorSpecialization', 'Cardiology')
        ->set('doctorPayoutDaily', true)
        ->set('doctorGetFullSlips', true)
        ->set('doctorFullSlipsCount', '5')
        ->set('doctorDutyStartTime', '18:00')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'name' => 'Dr. Smith',
        'specialization' => 'Cardiology',
        'payout_daily' => true,
        'get_full_slips' => true,
        'full_slips_count' => 5,
        'duty_start_time' => '18:00:00',
    ]);
});

test('authenticated users can update a doctor', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->set('doctorName', 'Dr. Updated')
        ->set('doctorSpecialization', 'Neurology')
        ->set('doctorPayoutDaily', true)
        ->set('doctorGetFullSlips', true)
        ->set('doctorFullSlipsCount', '3')
        ->set('doctorDutyStartTime', '19:30')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'id' => $doctor->id,
        'name' => 'Dr. Updated',
        'specialization' => 'Neurology',
        'payout_daily' => true,
        'get_full_slips' => true,
        'full_slips_count' => 3,
        'duty_start_time' => '19:30:00',
    ]);
});

test('authenticated users can delete a doctor', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('delete', $doctor->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('doctors', [
        'id' => $doctor->id,
    ]);
});

test('authenticated users can create a service with needs vitals', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'Consultation With Vitals')
        ->set('serviceIsStandalone', false)
        ->set('serviceNeedsVitals', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'Consultation With Vitals',
        'needs_vitals' => true,
        'token_reset_type' => TokenResetType::Shift->value,
    ]);
});

test('authenticated users can create a service', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->set('serviceName', 'General Checkup')
        ->set('serviceIsStandalone', true)
        ->set('serviceTokenResetType', TokenResetType::Shift->value)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'General Checkup',
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift->value,
    ]);
});

test('service creation defaults token reset to shift', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('create')
        ->assertSet('serviceTokenResetType', TokenResetType::Shift->value)
        ->set('serviceName', 'Default Shift Service')
        ->set('serviceIsStandalone', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'name' => 'Default Shift Service',
        'is_standalone' => false,
        'token_reset_type' => TokenResetType::Shift->value,
    ]);
});

test('authenticated users can create a service price', function () {
    $user = User::factory()->admin()->create();
    $service = Service::factory()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'servicePrices')
        ->call('create')
        ->set('priceServiceId', $service->id)
        ->set('priceDoctorId', $doctor->id)
        ->set('priceAmount', '150.00')
        ->set('priceDoctorShare', '25.00')
        ->set('priceTokenStartsFrom', '201')
        ->set('priceIsFileCheck', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('service_prices', [
        'service_id' => $service->id,
        'doctor_id' => $doctor->id,
        'price' => 150.00,
        'doctor_share' => 25.00,
        'token_starts_from' => 201,
        'is_file_check' => true,
    ]);
});

test('service price doctor share can be null', function () {
    $user = User::factory()->admin()->create();
    $service = Service::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'servicePrices')
        ->call('create')
        ->set('priceServiceId', $service->id)
        ->set('priceDoctorId', '')
        ->set('priceAmount', '99.99')
        ->set('priceDoctorShare', '')
        ->set('priceTokenStartsFrom', '1')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('service_prices', [
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 99.99,
        'doctor_share' => null,
        'token_starts_from' => 1,
    ]);
});

test('authenticated users can create a lab test', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'Complete Blood Count')
        ->set('labTestCode', 'CBC-001')
        ->set('labTestSample', 'E.D.T.A 2cc')
        ->set('labTestPrice', '1200.00')
        ->set('labTestTimeRequired', '1 hour')
        ->set('labTestIsInHouse', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'sample' => 'E.D.T.A 2cc',
        'test_price' => 1200.00,
        'time_required' => '1 hour',
        'is_in_house' => true,
    ]);
});

test('authenticated users can create two lab tests with the same code', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'CBC In House')
        ->set('labTestCode', '1300')
        ->set('labTestSample', 'E.D.T.A 2cc')
        ->set('labTestPrice', '700.00')
        ->set('labTestTimeRequired', 'Same day')
        ->set('labTestIsInHouse', true)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'CBC Send Out')
        ->set('labTestCode', '1300')
        ->set('labTestSample', 'E.D.T.A 2cc')
        ->set('labTestPrice', '900.00')
        ->set('labTestTimeRequired', 'Next day')
        ->set('labTestIsInHouse', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(LabTest::where('test_code', '1300')->count())->toBe(2);
});

test('authenticated users can create a lab test with empty test code', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('create')
        ->set('labTestName', 'Basic Metabolic Panel')
        ->set('labTestCode', '')
        ->set('labTestPrice', '800.00')
        ->set('labTestTimeRequired', '30 minutes')
        ->set('labTestIsInHouse', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'test_name' => 'Basic Metabolic Panel',
        'test_code' => null,
        'test_price' => 800.00,
        'time_required' => '30 minutes',
        'is_in_house' => true,
    ]);
});

test('authenticated users can update a lab test', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestName', 'Updated Blood Count')
        ->set('labTestCode', 'UBC-002')
        ->set('labTestSample', 'Clotted 2cc')
        ->set('labTestPrice', '1500.00')
        ->set('labTestTimeRequired', '2 hours')
        ->set('labTestIsInHouse', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'test_name' => 'Updated Blood Count',
        'test_code' => 'UBC-002',
        'sample' => 'Clotted 2cc',
        'test_price' => 1500.00,
        'time_required' => '2 hours',
        'is_in_house' => false,
    ]);
});

test('management lab tests table displays specimen values', function () {
    $user = User::factory()->admin()->create();
    LabTest::factory()->create([
        'test_name' => 'CBC',
        'sample' => 'E.D.T.A 2cc',
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->assertSee('E.D.T.A 2cc');
});

test('authenticated users can update a lab test with null test code', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create(['test_code' => null]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestName', 'Updated Blood Count')
        ->set('labTestCode', 'UBC-002')
        ->set('labTestPrice', '1500.00')
        ->set('labTestTimeRequired', '2 hours')
        ->set('labTestIsInHouse', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'test_name' => 'Updated Blood Count',
        'test_code' => 'UBC-002',
        'test_price' => 1500.00,
        'time_required' => '2 hours',
        'is_in_house' => false,
    ]);
});

test('authenticated users can delete a lab test', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('delete', $labTest->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('lab_tests', [
        'id' => $labTest->id,
    ]);
});
test('authenticated users can deactivate and reactivate a doctor', function () {
    $user = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->set('doctorIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'id' => $doctor->id,
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->set('doctorIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'id' => $doctor->id,
        'is_active' => true,
    ]);
});

test('authenticated users can deactivate and reactivate a service', function () {
    $user = User::factory()->admin()->create();
    $service = Service::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('edit', $service->id)
        ->set('serviceIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'id' => $service->id,
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'services')
        ->call('edit', $service->id)
        ->set('serviceIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('services', [
        'id' => $service->id,
        'is_active' => true,
    ]);
});

test('authenticated users can deactivate and reactivate a lab test', function () {
    $user = User::factory()->admin()->create();
    $labTest = LabTest::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'labTests')
        ->call('edit', $labTest->id)
        ->set('labTestIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lab_tests', [
        'id' => $labTest->id,
        'is_active' => true,
    ]);
});

test('authenticated users can create a procedure type', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('create')
        ->set('procedureTypeName', 'Normal Delivery')
        ->set('procedureTypeIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('procedure_types', [
        'name' => 'Normal Delivery',
        'is_active' => true,
    ]);
});

test('authenticated users can update a procedure type', function () {
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('edit', $procedureType->id)
        ->set('procedureTypeName', 'Updated Delivery')
        ->set('procedureTypeIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('procedure_types', [
        'id' => $procedureType->id,
        'name' => 'Updated Delivery',
        'is_active' => false,
    ]);
});

test('authenticated users can delete a procedure type', function () {
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('delete', $procedureType->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('procedure_types', [
        'id' => $procedureType->id,
    ]);
});

test('procedure type names must be unique', function () {
    $user = User::factory()->admin()->create();
    ProcedureType::factory()->create(['name' => 'Normal Delivery']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('create')
        ->set('procedureTypeName', 'Normal Delivery')
        ->call('save')
        ->assertHasErrors(['procedureTypeName']);

    expect(ProcedureType::where('name', 'Normal Delivery')->count())->toBe(1);
});

test('admins can upload multiple documents to a procedure type', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();

    $pdfContents = new FPDF;
    $pdfContents->AddPage();
    $pdf = TemporaryUploadedFile::fake()
        ->createWithContent('consent.pdf', $pdfContents->Output('S'))
        ->mimeType('application/octet-stream');
    $image = TemporaryUploadedFile::fake()
        ->image('form.png')
        ->mimeType('application/octet-stream');

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('openDocuments', $procedureType->id)
        ->assertSet('showDocumentsModal', true)
        ->set('documentUploads', [$pdf, $image])
        ->call('uploadDocuments')
        ->assertHasNoErrors();

    expect($procedureType->documents()->count())->toBe(2);

    $documents = $procedureType->documents()->orderBy('sort_order')->get();

    expect($documents[0]->original_name)->toBe('consent.pdf')
        ->and($documents[0]->mime_type)->toBe('application/pdf')
        ->and($documents[0]->sort_order)->toBe(1)
        ->and($documents[1]->original_name)->toBe('form.png')
        ->and($documents[1]->mime_type)->toBe('image/png')
        ->and($documents[1]->sort_order)->toBe(2);

    Storage::disk('local')->assertExists($documents[0]->path);
    Storage::disk('local')->assertExists($documents[1]->path);
});

test('procedure type document upload errors name the file that was rejected', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();
    $file = TemporaryUploadedFile::fake()->create('patient-notes.txt', 10, 'text/plain');

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->set('documentUploads', [$file])
        ->call('uploadDocuments')
        ->assertHasErrors(['documentUploads.0'])
        ->assertSee('patient-notes.txt must be a PDF, JPG, JPEG, or PNG file.');
});

test('admins can discard staged document uploads before saving them', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->set('documentUploads', [TemporaryUploadedFile::fake()->image('form.png')])
        ->call('clearDocumentUploads')
        ->assertSet('documentUploads', [])
        ->assertDispatched('document-uploads-reset');

    expect($procedureType->documents()->count())->toBe(0);
});

test('reopening the documents modal drops previously staged uploads', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->set('documentUploads', [TemporaryUploadedFile::fake()->image('form.png')])
        ->call('openDocuments', $procedureType->id)
        ->assertSet('documentUploads', []);
});

test('procedure type document uploads reject disguised unsupported files', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();
    $file = TemporaryUploadedFile::fake()
        ->createWithContent('malicious.jpg', '<?php echo "not an image";')
        ->mimeType('application/octet-stream');

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->set('documentUploads', [$file])
        ->call('uploadDocuments')
        ->assertHasErrors(['documentUploads.0']);

    expect($procedureType->documents()->count())->toBe(0);
});

test('procedure type document uploads reject invalid files', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();
    $file = TemporaryUploadedFile::fake()->create('notes.txt', 10, 'text/plain');

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->set('documentUploads', [$file])
        ->call('uploadDocuments')
        ->assertHasErrors(['documentUploads.0']);

    expect($procedureType->documents()->count())->toBe(0);
});

test('admins can preview private procedure type documents inline', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $document = ProcedureTypeDocument::factory()->jpeg()->create([
        'mime_type' => 'application/octet-stream',
    ]);

    $this->actingAs($user)
        ->get(route('management.procedure-type-documents.preview', $document))
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('content-disposition', 'inline; filename="'.$document->original_name.'"');
});

test('non admins cannot preview private procedure type documents', function () {
    Storage::fake('local');
    $user = User::factory()->receptionist()->create();
    $document = ProcedureTypeDocument::factory()->jpeg()->create();

    $this->actingAs($user)
        ->get(route('management.procedure-type-documents.preview', $document))
        ->assertForbidden();
});

test('admins can reorder and delete procedure type documents', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();
    $first = ProcedureTypeDocument::factory()->for($procedureType)->create([
        'original_name' => 'first.pdf',
        'sort_order' => 1,
        'path' => "procedure-types/{$procedureType->id}/documents/first.pdf",
    ]);
    $second = ProcedureTypeDocument::factory()->for($procedureType)->create([
        'original_name' => 'second.pdf',
        'sort_order' => 2,
        'path' => "procedure-types/{$procedureType->id}/documents/second.pdf",
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->call('moveDocumentUp', $second->id)
        ->assertHasNoErrors();

    expect($first->fresh()->sort_order)->toBe(2)
        ->and($second->fresh()->sort_order)->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->call('moveDocumentDown', $second->id)
        ->assertHasNoErrors();

    expect($first->fresh()->sort_order)->toBe(1)
        ->and($second->fresh()->sort_order)->toBe(2);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->call('openDocuments', $procedureType->id)
        ->call('deleteDocument', $first->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('procedure_type_documents', ['id' => $first->id]);
    Storage::disk('local')->assertMissing($first->path);
    Storage::disk('local')->assertExists($second->path);
});

test('deleting a procedure type removes its documents and stored files', function () {
    Storage::fake('local');
    $user = User::factory()->admin()->create();
    $procedureType = ProcedureType::factory()->create();
    $document = ProcedureTypeDocument::factory()->for($procedureType)->create([
        'path' => "procedure-types/{$procedureType->id}/documents/consent.pdf",
    ]);

    Storage::disk('local')->assertExists($document->path);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'procedureTypes')
        ->call('delete', $procedureType->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('procedure_types', ['id' => $procedureType->id]);
    $this->assertDatabaseMissing('procedure_type_documents', ['id' => $document->id]);
    Storage::disk('local')->assertMissing($document->path);
});

test('authenticated users can create a room', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('create')
        ->set('roomNumber', 'Room 101')
        ->set('roomIsActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('rooms', [
        'number' => 'Room 101',
        'is_active' => true,
    ]);
});

test('authenticated users can update a room', function () {
    $user = User::factory()->admin()->create();
    $room = Room::factory()->create(['number' => 'Room 1']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('edit', $room->id)
        ->set('roomNumber', 'Room 2')
        ->set('roomIsActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('rooms', [
        'id' => $room->id,
        'number' => 'Room 2',
        'is_active' => false,
    ]);
});

test('authenticated users can delete a room', function () {
    $user = User::factory()->admin()->create();
    $room = Room::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('delete', $room->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
});

test('room numbers must be unique', function () {
    $user = User::factory()->admin()->create();
    Room::factory()->create(['number' => 'Room 101']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'rooms')
        ->call('create')
        ->set('roomNumber', 'Room 101')
        ->call('save')
        ->assertHasErrors(['roomNumber']);

    expect(Room::where('number', 'Room 101')->count())->toBe(1);
});
