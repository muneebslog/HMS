<?php

use App\Enums\SmsStatus;
use App\Enums\UserRole;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin users can visit the sms logs page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $response = $this->actingAs($admin)->get(route('admin.sms-logs'));

    $response->assertOk();
});

test('non admin users cannot visit the sms logs page', function () {
    $user = User::factory()->create(['role' => UserRole::Receptionist]);

    $response = $this->actingAs($user)->get(route('admin.sms-logs'));

    $response->assertForbidden();
});

test('sms logs page lists logs with their status', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $sentLog = SmsLog::factory()->sent()->create();
    $failedLog = SmsLog::factory()->failed()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sms-logs')
        ->assertSee($sentLog->phone)
        ->assertSee($failedLog->phone)
        ->assertSee($sentLog->status->label())
        ->assertSee($failedLog->status->label());
});

test('sms logs page can filter by status', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $queuedLog = SmsLog::factory()->queued()->create(['phone' => '+923001110000']);
    $sentLog = SmsLog::factory()->sent()->create(['phone' => '+923002220000']);

    Livewire::actingAs($admin)
        ->test('pages::admin.sms-logs')
        ->set('statusFilter', SmsStatus::Queued->value)
        ->assertSee($queuedLog->phone)
        ->assertDontSee($sentLog->phone);
});

test('sms logs page can search by phone', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $targetLog = SmsLog::factory()->create(['phone' => '+923001234567']);
    $otherLog = SmsLog::factory()->create(['phone' => '+923009876543']);

    Livewire::actingAs($admin)
        ->test('pages::admin.sms-logs')
        ->set('phoneSearch', '1234567')
        ->assertSee($targetLog->phone)
        ->assertDontSee($otherLog->phone);
});

test('sms logs page shows log details in a modal', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $log = SmsLog::factory()->sent()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sms-logs')
        ->call('viewLog', $log->id)
        ->assertSet('selectedLogId', $log->id)
        ->assertSet('showLogModal', true);
});

test('sms logs page shows failure reason for failed logs in a modal', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $log = SmsLog::factory()->failed()->create([
        'provider_response' => 'Insufficient credit',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.sms-logs')
        ->call('viewLog', $log->id)
        ->assertSee('Insufficient credit')
        ->assertSee('Failure Reason');
});

test('sms logs page shows a placeholder when a failed log has no recorded reason', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $log = SmsLog::factory()->failed()->create(['provider_response' => null]);

    Livewire::actingAs($admin)
        ->test('pages::admin.sms-logs')
        ->call('viewLog', $log->id)
        ->assertSee('Failure Reason')
        ->assertSee('No failure reason was recorded for this log.');
});
