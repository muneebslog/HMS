<?php

use App\Enums\PrintJobStatus;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\PdfPrintJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('guests cannot view the drive page', function () {
    $this->get(route('admin.drive'))
        ->assertRedirect();
});

test('non-allowed roles cannot view the drive page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.drive'))
        ->assertForbidden();
});

test('admins can view the drive page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.drive'))
        ->assertOk();
});

test('management users can view the drive page', function () {
    $user = User::factory()->management()->create();

    $this->actingAs($user)
        ->get(route('admin.drive'))
        ->assertOk();
});

test('users can create nested folders', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openCreateFolderModal')
        ->set('folderName', 'Policies')
        ->call('saveFolder')
        ->assertHasNoErrors();

    $parent = DriveFolder::query()->where('name', 'Policies')->first();

    expect($parent)->not->toBeNull()
        ->and($parent->parent_id)->toBeNull()
        ->and($parent->created_by)->toBe($admin->id);

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openFolder', $parent->id)
        ->call('openCreateFolderModal')
        ->set('folderName', '2026')
        ->call('saveFolder')
        ->assertHasNoErrors();

    $child = DriveFolder::query()->where('name', '2026')->first();

    expect($child)->not->toBeNull()
        ->and($child->parent_id)->toBe($parent->id);
});

test('users can upload a pdf with name and tags', function () {
    $admin = User::factory()->admin()->create();
    $folder = DriveFolder::factory()->create(['created_by' => $admin->id]);
    $file = TemporaryUploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openFolder', $folder->id)
        ->call('openUploadModal')
        ->set('upload', $file)
        ->set('fileName', 'Monthly Report')
        ->set('fileTags', 'finance, monthly')
        ->call('uploadFile')
        ->assertHasNoErrors();

    $driveFile = DriveFile::query()->first();

    expect($driveFile)->not->toBeNull()
        ->and($driveFile->folder_id)->toBe($folder->id)
        ->and($driveFile->name)->toBe('Monthly Report')
        ->and($driveFile->tags)->toBe(['finance', 'monthly'])
        ->and($driveFile->isPdf())->toBeTrue();

    Storage::disk('local')->assertExists($driveFile->disk_path);
});

test('users can upload an image', function () {
    $admin = User::factory()->admin()->create();
    $file = TemporaryUploadedFile::fake()->image('scan.png');

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openUploadModal')
        ->set('upload', $file)
        ->set('fileName', 'Scan')
        ->call('uploadFile')
        ->assertHasNoErrors();

    $driveFile = DriveFile::query()->first();

    expect($driveFile)->not->toBeNull()
        ->and($driveFile->isImage())->toBeTrue();
});

test('users can rename a file and update tags', function () {
    $admin = User::factory()->admin()->create();
    $driveFile = DriveFile::factory()->create([
        'created_by' => $admin->id,
        'name' => 'Old name',
        'tags' => ['old'],
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openEditFileModal', $driveFile->id)
        ->set('editFileName', 'New name')
        ->set('editFileTags', 'hr, contracts')
        ->call('saveFile')
        ->assertHasNoErrors();

    expect($driveFile->fresh())
        ->name->toBe('New name')
        ->tags->toBe(['hr', 'contracts']);
});

test('search finds files by name or tag across folders', function () {
    $admin = User::factory()->admin()->create();
    $folder = DriveFolder::factory()->create(['created_by' => $admin->id]);

    DriveFile::factory()->create([
        'created_by' => $admin->id,
        'folder_id' => $folder->id,
        'name' => 'Alpha Contract',
        'tags' => ['legal'],
    ]);

    DriveFile::factory()->create([
        'created_by' => $admin->id,
        'name' => 'Other',
        'tags' => ['ops'],
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->set('search', 'Alpha')
        ->assertSee('Alpha Contract')
        ->assertDontSee('Other');

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->set('search', 'legal')
        ->assertSee('Alpha Contract')
        ->assertDontSee('Other');
});

test('authorized users can download and view drive files', function () {
    $admin = User::factory()->admin()->create();
    $driveFile = DriveFile::factory()->create(['created_by' => $admin->id]);

    $this->actingAs($admin)
        ->get(route('admin.drive.download', $driveFile))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('admin.drive.view', $driveFile))
        ->assertOk();
});

test('unauthorized users cannot download drive files', function () {
    $user = User::factory()->receptionist()->create();
    $driveFile = DriveFile::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.drive.download', $driveFile))
        ->assertForbidden();
});

test('printing a pdf queues a pdf print job', function () {
    $admin = User::factory()->admin()->create();
    $driveFile = DriveFile::factory()->create([
        'created_by' => $admin->id,
        'name' => 'Consent Form',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openPrintModal', $driveFile->id)
        ->set('copies', 2)
        ->call('queuePrint')
        ->assertHasNoErrors();

    $job = PdfPrintJob::query()->first();

    expect($job)->not->toBeNull()
        ->and($job->user_id)->toBe($admin->id)
        ->and($job->copies)->toBe(2)
        ->and($job->status)->toBe(PrintJobStatus::Pending)
        ->and($job->original_filename)->toBe('Consent Form.pdf');

    Storage::disk('local')->assertExists($job->disk_path);
    Storage::disk('local')->assertExists($driveFile->disk_path);
});

test('printing an image is rejected', function () {
    $admin = User::factory()->admin()->create();
    $driveFile = DriveFile::factory()->image()->create(['created_by' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openPrintModal', $driveFile->id)
        ->assertSet('showPrintModal', false);

    expect(PdfPrintJob::query()->count())->toBe(0);
});

test('deleting a file removes it from storage', function () {
    $admin = User::factory()->admin()->create();
    $driveFile = DriveFile::factory()->create(['created_by' => $admin->id]);
    $path = $driveFile->disk_path;

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('deleteFile', $driveFile->id)
        ->assertHasNoErrors();

    expect(DriveFile::query()->find($driveFile->id))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

test('deleting a folder cascades contents and storage', function () {
    $admin = User::factory()->admin()->create();
    $parent = DriveFolder::factory()->create(['created_by' => $admin->id, 'name' => 'Parent']);
    $child = DriveFolder::factory()->inFolder($parent)->create(['created_by' => $admin->id, 'name' => 'Child']);
    $file = DriveFile::factory()->inFolder($child)->create(['created_by' => $admin->id]);
    $path = $file->disk_path;

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('deleteFolder', $parent->id)
        ->assertHasNoErrors();

    expect(DriveFolder::query()->count())->toBe(0)
        ->and(DriveFile::query()->count())->toBe(0);

    Storage::disk('local')->assertMissing($path);
});

test('duplicate sibling folder names are rejected', function () {
    $admin = User::factory()->admin()->create();
    DriveFolder::factory()->create([
        'created_by' => $admin->id,
        'name' => 'Policies',
        'parent_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.drive')
        ->call('openCreateFolderModal')
        ->set('folderName', 'Policies')
        ->call('saveFolder')
        ->assertHasErrors(['folderName']);
});
