<?php

use App\Models\AdminNotification;
use App\Models\SupervisorChecklistEntry;
use App\Models\User;
use App\Services\SupervisorChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('command notifies only supervisors missing the previous block entry', function () {
    $missingSupervisor = User::factory()->supervisor()->create();
    $submittedSupervisor = User::factory()->supervisor()->create();
    $block = app(SupervisorChecklistService::class)->previousCompletedBlock();

    SupervisorChecklistEntry::factory()
        ->forSupervisor($submittedSupervisor)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $this->artisan('supervisor:check-missing-checklists')
        ->assertSuccessful()
        ->expectsOutputToContain('Notified: '.$missingSupervisor->name)
        ->doesntExpectOutputToContain('Notified: '.$submittedSupervisor->name);

    expect(AdminNotification::where('type', 'supervisor_checklist_missing')->count())->toBe(1);
});

test('command creates no notifications when all supervisors submitted', function () {
    $supervisor = User::factory()->supervisor()->create();
    $block = app(SupervisorChecklistService::class)->previousCompletedBlock();

    SupervisorChecklistEntry::factory()
        ->forSupervisor($supervisor)
        ->forBlock($block['start'], $block['end'])
        ->create();

    $this->artisan('supervisor:check-missing-checklists')
        ->assertSuccessful()
        ->expectsOutputToContain('Done. 0 notification(s) sent.');

    expect(AdminNotification::where('type', 'supervisor_checklist_missing')->count())->toBe(0);
});
