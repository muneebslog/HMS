<?php

use App\Enums\PrintJobStatus;
use App\Enums\UserRole;
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

test('admins can view the pdf print page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.pdf-print'))
        ->assertOk();
});

test('non-admins cannot view the pdf print page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.pdf-print'))
        ->assertForbidden();
});

test('admins can upload a pdf and queue a print job', function () {
    $admin = User::factory()->admin()->create();
    $file = TemporaryUploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    Livewire::actingAs($admin)
        ->test('pages::admin.pdf-print')
        ->set('pdf', $file)
        ->call('queuePrint')
        ->assertHasNoErrors()
        ->assertSet('pdf', null);

    $job = PdfPrintJob::query()->first();

    expect($job)->not->toBeNull()
        ->and($job->user_id)->toBe($admin->id)
        ->and($job->original_filename)->toBe('report.pdf')
        ->and($job->status)->toBe(PrintJobStatus::Pending);

    Storage::disk('local')->assertExists($job->disk_path);
});

test('pdf upload rejects non-pdf files', function () {
    $admin = User::factory()->admin()->create();
    $file = TemporaryUploadedFile::fake()->create('notes.txt', 10, 'text/plain');

    Livewire::actingAs($admin)
        ->test('pages::admin.pdf-print')
        ->set('pdf', $file)
        ->call('queuePrint')
        ->assertHasErrors(['pdf']);

    expect(PdfPrintJob::query()->count())->toBe(0);
});

test('admins can retry a failed pdf print job', function () {
    $admin = User::factory()->admin()->create();
    $job = PdfPrintJob::factory()->failed()->create([
        'user_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.pdf-print')
        ->call('retry', $job->id)
        ->assertHasNoErrors();

    expect($job->fresh())
        ->status->toBe(PrintJobStatus::Pending)
        ->failed_at->toBeNull()
        ->error_message->toBeNull();
});

test('the pdf print page shows the failure reason', function () {
    $admin = User::factory()->admin()->create();
    PdfPrintJob::factory()->failed()->create([
        'user_id' => $admin->id,
        'error_message' => 'Printer offline',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.pdf-print')
        ->assertSee('Printer offline');
});

test('management users cannot access the pdf print page', function () {
    $user = User::factory()->create(['role' => UserRole::Management]);

    $this->actingAs($user)
        ->get(route('admin.pdf-print'))
        ->assertForbidden();
});
