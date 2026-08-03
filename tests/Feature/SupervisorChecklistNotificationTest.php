<?php

use App\Models\AdminNotification;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SupervisorChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('notification is created when checklist is missing for a block', function () {
    $receptionist = User::factory()->receptionist()->create();
    $block = app(SupervisorChecklistService::class)->currentBlock();

    $notification = app(NotificationService::class)->notifySupervisorChecklistMissing(
        $receptionist,
        $block['start'],
        $block['end']
    );

    expect($notification)->not->toBeNull();
    expect(AdminNotification::count())->toBe(1);
    expect($notification->type)->toBe('supervisor_checklist_missing');
    expect($notification->metadata)->toHaveKey('supervisor_id', $receptionist->id);
    expect($notification->actionable_url)->toBe(route('admin.supervisor-checklist'));
});

test('duplicate notifications for the same block are suppressed', function () {
    $receptionist = User::factory()->receptionist()->create();
    $block = app(SupervisorChecklistService::class)->currentBlock();
    $service = app(NotificationService::class);

    $first = $service->notifySupervisorChecklistMissing($receptionist, $block['start'], $block['end']);
    $second = $service->notifySupervisorChecklistMissing($receptionist, $block['start'], $block['end']);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect(AdminNotification::count())->toBe(1);
});
