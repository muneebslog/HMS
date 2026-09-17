<?php

use App\Actions\AdvanceOutgoingSample;
use App\Enums\OutgoingSampleStatus;
use App\Models\LabInvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('outgoing samples advance forward only through asked given and received', function () {
    Storage::fake('local');

    $user = User::factory()->receptionist()->create();
    $item = LabInvoiceItem::factory()->outgoing()->create();

    $action = app(AdvanceOutgoingSample::class);

    $asked = $action->handle($item, OutgoingSampleStatus::Asked, $user);
    expect($asked->outgoing_status)->toBe(OutgoingSampleStatus::Asked)
        ->and($asked->asked_by)->toBe($user->id)
        ->and($asked->asked_at)->not->toBeNull();

    $given = $action->handle($asked, OutgoingSampleStatus::Given, $user);
    expect($given->outgoing_status)->toBe(OutgoingSampleStatus::Given)
        ->and($given->given_by)->toBe($user->id);

    $pdf = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
    $received = $action->handle($given, OutgoingSampleStatus::Received, $user, $pdf);

    expect($received->outgoing_status)->toBe(OutgoingSampleStatus::Received)
        ->and($received->received_by)->toBe($user->id)
        ->and($received->hasReport())->toBeTrue();

    expect(fn () => $action->handle($received, OutgoingSampleStatus::Asked, $user))
        ->toThrow(InvalidArgumentException::class);
});

test('in-house items cannot be advanced as outgoing samples', function () {
    $user = User::factory()->receptionist()->create();
    $item = LabInvoiceItem::factory()->inHouse()->create();

    expect(fn () => app(AdvanceOutgoingSample::class)->handle($item, OutgoingSampleStatus::Asked, $user))
        ->toThrow(InvalidArgumentException::class);
});

test('reports can be uploaded after an outgoing sample is received', function () {
    Storage::fake('local');

    $user = User::factory()->receptionist()->create();
    $item = LabInvoiceItem::factory()->outgoing()->create([
        'outgoing_status' => OutgoingSampleStatus::Received,
        'received_at' => now(),
        'received_by' => $user->id,
    ]);

    $pdf = UploadedFile::fake()->create('outsource.pdf', 120, 'application/pdf');
    $updated = app(AdvanceOutgoingSample::class)->storeReportForItem($item, $pdf, $user);

    expect($updated->hasReport())->toBeTrue()
        ->and($updated->report_original_name)->toBe('outsource.pdf')
        ->and($updated->report_uploaded_by)->toBe($user->id);
});
