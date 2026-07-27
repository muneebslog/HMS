<?php

use App\Models\AdminNotification;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeTodo;
use App\Models\User;
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
        ->assertSee($employee->name);
});

test('non-admin cannot access staff profiles', function () {
    $user = User::factory()->receptionist()->create();
    Employee::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.employees'))
        ->assertForbidden();
});

test('admin can create a staff profile', function () {
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
        ->assertHasNoErrors();

    $this->assertDatabaseHas('employees', [
        'name' => 'Dr. Amina Khan',
        'email' => 'amina@example.com',
        'designation' => 'Cardiologist',
        'department' => 'Cardiology',
        'created_by' => $admin->id,
    ]);
});

test('admin can update employee info from profile page', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('startEditingInfo')
        ->set('name', 'New Name')
        ->set('designation', 'Senior Nurse')
        ->call('saveInfo')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'name' => 'New Name',
        'designation' => 'Senior Nurse',
    ]);
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
