<?php

use App\Models\AdminNotification;
use App\Models\SupervisorChecklistEntry;
use App\Models\User;
use App\Services\SupervisorChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('command notifies only receptionists missing the previous block entry', function () {
    $missingReceptionist = User::factory()->receptionist()->create();
    $submittedReceptionist = User::factory()->receptionist()->create();
    $block = app(SupervisorChecklistService::class)->previousCompletedBlock();

    SupervisorChecklistEntry::factory()
        ->forUser($submittedReceptionist)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $this->artisan('supervisor:check-missing-checklists')
        ->assertSuccessful()
        ->expectsOutputToContain('Notified: '.$missingReceptionist->name)
        ->doesntExpectOutputToContain('Notified: '.$submittedReceptionist->name);

    expect(AdminNotification::where('type', 'supervisor_checklist_missing')->count())->toBe(1);
});

test('command creates no notifications when all receptionists submitted', function () {
    $receptionist = User::factory()->receptionist()->create();
    $block = app(SupervisorChecklistService::class)->previousCompletedBlock();

    SupervisorChecklistEntry::factory()
        ->forUser($receptionist)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $this->artisan('supervisor:check-missing-checklists')
        ->assertSuccessful()
        ->expectsOutputToContain('Done. 0 notification(s) sent.');

    expect(AdminNotification::where('type', 'supervisor_checklist_missing')->count())->toBe(0);
});
