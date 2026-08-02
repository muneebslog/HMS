<?php

use App\Models\AdminNotification;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeExperience;
use App\Models\EmployeeQualification;
use App\Models\EmployeeTodo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('admin can view staff profiles list', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.employees'))
        ->assertOk()
        ->assertSee($employee->name)
        ->assertSee('#'.$employee->id);
});

test('non-admin cannot access staff profiles', function () {
    $user = User::factory()->receptionist()->create();
    Employee::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.employees'))
        ->assertForbidden();
});

test('admin can create a staff profile and is redirected to profile', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.employees')
        ->set('name', 'Dr. Amina Khan')
        ->set('email', 'amina@example.com')
        ->set('phone', '03001234567')
        ->set('designation', 'Cardiologist')
        ->set('department', 'Cardiology')
        ->set('employmentType', 'full_time')
        ->set('status', 'active')
        ->call('saveEmployee')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.employees.profile', Employee::where('email', 'amina@example.com')->first()));

    $this->assertDatabaseHas('employees', [
        'name' => 'Dr. Amina Khan',
        'email' => 'amina@example.com',
        'designation' => 'Cardiologist',
        'department' => 'Cardiology',
        'created_by' => $admin->id,
    ]);
});

test('admin can update employee info from profile page with undertaking', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('startEditingInfo')
        ->set('name', 'New Name')
        ->set('fatherName', 'Ali Khan')
        ->set('cnic', '35202-1234567-1')
        ->set('dateOfBirth', '1990-05-15')
        ->set('sex', 'female')
        ->set('religionSect', 'Islam')
        ->set('caste', 'Rajput')
        ->set('maritalStatus', 'married')
        ->set('currentAddress', 'Lahore')
        ->set('permanentAddress', 'Multan')
        ->set('emergencyContact', '03009876543')
        ->set('languages', 'Urdu, English')
        ->set('distanceTimeFromHospital', '10 km / 20 min')
        ->set('designation', 'Senior Nurse')
        ->set('undertakingAccepted', true)
        ->call('saveInfo')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->name)->toBe('New Name')
        ->and($employee->father_name)->toBe('Ali Khan')
        ->and($employee->cnic)->toBe('35202-1234567-1')
        ->and($employee->designation)->toBe('Senior Nurse')
        ->and($employee->undertaking_accepted)->toBeTrue()
        ->and($employee->undertaking_accepted_at)->not->toBeNull()
        ->and($employee->age)->toBe(Carbon::parse('1990-05-15')->age);
});

test('saving employee info requires undertaking', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('startEditingInfo')
        ->set('name', 'Updated Name')
        ->set('undertakingAccepted', false)
        ->call('saveInfo')
        ->assertHasErrors(['undertakingAccepted']);
});

test('admin can upload and remove a staff profile photo', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $file = UploadedFile::fake()->image('portrait.jpg', 400, 400);

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->set('photo', $file)
        ->call('savePhoto')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->hasPhoto())->toBeTrue();
    Storage::disk('local')->assertExists($employee->photo_path);

    $photoPath = $employee->photo_path;

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('removePhoto')
        ->assertHasNoErrors();

    $employee->refresh();

    expect($employee->hasPhoto())->toBeFalse()
        ->and($employee->photo_path)->toBeNull();

    Storage::disk('local')->assertMissing($photoPath);
});

test('admin can view staff profile photo', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'photo_path' => 'employee-photos/1/portrait.jpg',
    ]);
    Storage::disk('local')->put($employee->photo_path, 'fake-image-content');

    $this->actingAs($admin)
        ->get(route('employee-photos.show', $employee))
        ->assertOk();
});

test('non-admin cannot view staff profile photo', function () {
    $user = User::factory()->receptionist()->create();
    $employee = Employee::factory()->create([
        'photo_path' => 'employee-photos/1/portrait.jpg',
    ]);
    Storage::disk('local')->put($employee->photo_path, 'fake-image-content');

    $this->actingAs($user)
        ->get(route('employee-photos.show', $employee))
        ->assertForbidden();
});

test('admin can upload a document for an employee', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $file = UploadedFile::fake()->create('degree.pdf', 100);

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('openDocumentModal')
        ->set('documentTitle', 'MBBS Degree')
        ->set('documentType', 'degree')
        ->set('documentFile', $file)
        ->set('documentExpiryDate', now()->addYear()->format('Y-m-d'))
        ->call('saveDocument')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'title' => 'MBBS Degree',
        'type' => 'degree',
        'original_name' => 'degree.pdf',
    ]);

    $document = EmployeeDocument::first();
    Storage::disk('local')->assertExists($document->file_path);
});

test('admin can add update and delete a qualification', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $file = UploadedFile::fake()->create('mbbs.pdf', 100);

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('openQualificationModal')
        ->set('qualificationCourse', 'MBBS')
        ->set('qualificationPassingYear', '2015')
        ->set('qualificationInstitution', 'King Edward Medical University')
        ->set('qualificationDocument', $file)
        ->call('saveQualification')
        ->assertHasNoErrors();

    $qualification = EmployeeQualification::first();
    expect($qualification)->not->toBeNull()
        ->and($qualification->course)->toBe('MBBS')
        ->and($qualification->passing_year)->toBe(2015)
        ->and($qualification->original_name)->toBe('mbbs.pdf');

    Storage::disk('local')->assertExists($qualification->document_path);

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('editQualification', $qualification->id)
        ->set('qualificationCourse', 'MBBS (Honours)')
        ->call('saveQualification')
        ->assertHasNoErrors();

    expect($qualification->fresh()->course)->toBe('MBBS (Honours)');

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('deleteQualification', $qualification->id)
        ->assertHasNoErrors();

    expect(EmployeeQualification::count())->toBe(0);
});

test('admin can add update and delete experience', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('openExperienceModal')
        ->set('experienceCompany', 'City Hospital')
        ->set('experienceDateOfJoining', '2018-01-01')
        ->set('experienceDateOfLeaving', '2020-06-30')
        ->set('experienceReasonForLeaving', 'Relocated')
        ->call('saveExperience')
        ->assertHasNoErrors();

    $experience = EmployeeExperience::first();
    expect($experience)->not->toBeNull()
        ->and($experience->company)->toBe('City Hospital')
        ->and($experience->reason_for_leaving)->toBe('Relocated');

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('editExperience', $experience->id)
        ->set('experienceCompany', 'City General Hospital')
        ->call('saveExperience')
        ->assertHasNoErrors();

    expect($experience->fresh()->company)->toBe('City General Hospital');

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('deleteExperience', $experience->id)
        ->assertHasNoErrors();

    expect(EmployeeExperience::count())->toBe(0);
});

test('admin can download qualification document', function () {
    $admin = User::factory()->admin()->create();
    $qualification = EmployeeQualification::factory()->create([
        'document_path' => 'employee-qualifications/1/doc.pdf',
        'original_name' => 'doc.pdf',
    ]);
    Storage::disk('local')->put($qualification->document_path, 'file content');

    $this->actingAs($admin)
        ->get(route('employee-qualifications.download', $qualification))
        ->assertOk()
        ->assertDownload('doc.pdf');
});

test('admin can add and complete a todo', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('openTodoModal')
        ->set('todoTitle', 'Renew PMDC license')
        ->set('todoDescription', 'Submit renewal application')
        ->set('todoDueDate', now()->addWeek()->format('Y-m-d'))
        ->call('saveTodo')
        ->assertHasNoErrors();

    $todo = EmployeeTodo::first();
    expect($todo)->not->toBeNull();
    expect($todo->title)->toBe('Renew PMDC license');

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('markTodoDone', $todo->id)
        ->assertHasNoErrors();

    $todo->refresh();
    expect($todo->isCompleted())->toBeTrue();
    expect($todo->completed_by)->toBe($admin->id);
});

test('dashboard shows pending employee todos to admin', function () {
    $admin = User::factory()->admin()->create();
    $todo = EmployeeTodo::factory()->create([
        'title' => 'License renewal',
        'due_date' => now()->addDay()->format('Y-m-d'),
    ]);

    Livewire::actingAs($admin)
        ->test('pages::dashboard')
        ->assertSet('pendingEmployeeTodoCount', 1)
        ->assertSee('License renewal')
        ->assertSee($todo->employee->name);
});

test('dashboard todo card hides completed todos', function () {
    $admin = User::factory()->admin()->create();
    EmployeeTodo::factory()->completed()->create();

    Livewire::actingAs($admin)
        ->test('pages::dashboard')
        ->assertSet('pendingEmployeeTodoCount', 0);
});

test('employee todos notify command creates admin notification for overdue todos', function () {
    $admin = User::factory()->admin()->create();
    $todo = EmployeeTodo::factory()->overdue()->create([
        'title' => 'Expired license',
        'created_by' => $admin->id,
    ]);

    $this->artisan('employee-todos:notify')
        ->assertSuccessful();

    $this->assertDatabaseHas('admin_notifications', [
        'type' => 'employee_todo_due',
        'user_id' => $admin->id,
    ]);

    $this->artisan('employee-todos:notify')
        ->assertSuccessful();

    expect(AdminNotification::where('type', 'employee_todo_due')->count())->toBe(1);
});

test('employee todos notify command ignores completed todos', function () {
    User::factory()->admin()->create();
    EmployeeTodo::factory()->completed()->create();

    $this->artisan('employee-todos:notify')
        ->assertSuccessful();

    expect(AdminNotification::where('type', 'employee_todo_due')->count())->toBe(0);
});

test('admin can download employee document', function () {
    $admin = User::factory()->admin()->create();
    $document = EmployeeDocument::factory()->create();
    Storage::disk('local')->put($document->file_path, 'file content');

    $this->actingAs($admin)
        ->get(route('employee-documents.download', $document))
        ->assertOk()
        ->assertDownload($document->original_name);
});

test('non-admin cannot download employee document', function () {
    $user = User::factory()->receptionist()->create();
    $document = EmployeeDocument::factory()->create();

    $this->actingAs($user)
        ->get(route('employee-documents.download', $document))
        ->assertForbidden();
});
