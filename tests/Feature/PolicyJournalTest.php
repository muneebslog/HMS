<?php

use App\Enums\PolicyJournalStatus;
use App\Enums\UserRole;
use App\Models\PolicyJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.policy-journal'));

    $response->assertRedirect(route('login'));
});

test('admins can visit the policy journal page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.policy-journal'));

    $response->assertOk();
});

test('non-admins cannot visit the policy journal page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('admin.policy-journal'));

    $response->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
    'supervisor' => [UserRole::Supervisor],
]);

test('users with the default user role are redirected to the pending role page', function () {
    $user = User::factory()->user()->create();

    $response = $this->actingAs($user)->get(route('admin.policy-journal'));

    $response->assertRedirect(route('pending-role'));
});

test('admin can create a policy journal entry', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->set('title', 'Billing Dispute Policy')
        ->set('incident', 'A patient disputed an invoice.')
        ->set('resolution', 'We reviewed the invoice and issued a credit.')
        ->set('policy', 'All billing disputes must be escalated to management within 24 hours.')
        ->set('category', 'Billing')
        ->set('tags', 'billing, dispute, escalation')
        ->set('effectiveDate', '2026-07-01')
        ->set('reviewDate', '2026-12-31')
        ->set('status', PolicyJournalStatus::Active->value)
        ->call('saveEntry')
        ->assertHasNoErrors();

    $entry = PolicyJournal::first();

    expect($entry)->not->toBeNull()
        ->and($entry->title)->toBe('Billing Dispute Policy')
        ->and($entry->incident)->toBe('A patient disputed an invoice.')
        ->and($entry->resolution)->toBe('We reviewed the invoice and issued a credit.')
        ->and($entry->policy)->toBe('All billing disputes must be escalated to management within 24 hours.')
        ->and($entry->category)->toBe('Billing')
        ->and($entry->tags)->toBe(['billing', 'dispute', 'escalation'])
        ->and($entry->effective_date->format('Y-m-d'))->toBe('2026-07-01')
        ->and($entry->review_date->format('Y-m-d'))->toBe('2026-12-31')
        ->and($entry->status)->toBe(PolicyJournalStatus::Active)
        ->and($entry->created_by)->toBe($admin->id);
});

test('admin cannot create a policy journal entry without required fields', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->set('title', '')
        ->set('incident', '')
        ->set('resolution', '')
        ->set('policy', '')
        ->set('status', '')
        ->call('saveEntry')
        ->assertHasErrors(['title', 'incident', 'resolution', 'policy', 'status']);

    expect(PolicyJournal::count())->toBe(0);
});

test('admin can create an entry with file attachments', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();

    $file = TemporaryUploadedFile::fake()->image('policy.png');

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->set('title', 'Attached Policy')
        ->set('incident', 'Incident with screenshot.')
        ->set('resolution', 'Resolved.')
        ->set('policy', 'Policy.')
        ->set('category', 'IT')
        ->set('status', PolicyJournalStatus::Active->value)
        ->set('newAttachments', [$file])
        ->call('saveEntry')
        ->assertHasNoErrors();

    $entry = PolicyJournal::first();

    expect($entry)->not->toBeNull()
        ->and($entry->attachments)->toHaveCount(1)
        ->and($entry->attachments[0]['original_name'])->toBe('policy.png');

    Storage::disk('local')->assertExists($entry->attachments[0]['path']);
});

test('admin can download an attachment', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $file = UploadedFile::fake()->image('policy.png');
    $path = $file->store('policy-journals/1', 'local');

    $entry = PolicyJournal::factory()->for($admin, 'creator')->create([
        'attachments' => [
            ['path' => $path, 'original_name' => 'policy.png', 'size' => $file->getSize()],
        ],
    ]);

    $response = $this->actingAs($admin)->get(route('admin.policy-journals.download', [
        'policyJournal' => $entry->id,
        'index' => 0,
    ]));

    $response->assertOk();
});

test('non-admins cannot download attachments', function (UserRole $role) {
    Storage::fake('local');
    $user = User::factory()->{$role->value}()->create();
    $admin = User::factory()->admin()->create();
    $file = UploadedFile::fake()->image('policy.png');
    $path = $file->store('policy-journals/1', 'local');

    $entry = PolicyJournal::factory()->for($admin, 'creator')->create([
        'attachments' => [
            ['path' => $path, 'original_name' => 'policy.png', 'size' => $file->getSize()],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('admin.policy-journals.download', [
        'policyJournal' => $entry->id,
        'index' => 0,
    ]));

    $response->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
    'supervisor' => [UserRole::Supervisor],
]);

test('guests cannot download attachments', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $file = UploadedFile::fake()->image('policy.png');
    $path = $file->store('policy-journals/1', 'local');

    $entry = PolicyJournal::factory()->for($admin, 'creator')->create([
        'attachments' => [
            ['path' => $path, 'original_name' => 'policy.png', 'size' => $file->getSize()],
        ],
    ]);

    $response = $this->get(route('admin.policy-journals.download', [
        'policyJournal' => $entry->id,
        'index' => 0,
    ]));

    $response->assertRedirect(route('login'));
});

test('search returns matching policy entries', function () {
    $admin = User::factory()->admin()->create();
    $matching = PolicyJournal::factory()->for($admin, 'creator')->create([
        'title' => 'Refund Policy',
        'policy' => 'All refunds require manager approval.',
    ]);
    PolicyJournal::factory()->for($admin, 'creator')->create([
        'title' => 'Scheduling Policy',
        'policy' => 'Appointments must be confirmed.',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->set('search', 'refund')
        ->assertSee('Refund Policy')
        ->assertDontSee('Scheduling Policy');
});

test('category filter returns only matching entries', function () {
    $admin = User::factory()->admin()->create();
    PolicyJournal::factory()->for($admin, 'creator')->category('Billing')->create(['title' => 'Billing Entry']);
    PolicyJournal::factory()->for($admin, 'creator')->category('Clinical')->create(['title' => 'Clinical Entry']);

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->set('categoryFilter', 'Billing')
        ->assertSee('Billing Entry')
        ->assertDontSee('Clinical Entry');
});

test('status filter returns only matching entries', function () {
    $admin = User::factory()->admin()->create();
    PolicyJournal::factory()->for($admin, 'creator')->status(PolicyJournalStatus::Active)->create(['title' => 'Active Entry']);
    PolicyJournal::factory()->for($admin, 'creator')->status(PolicyJournalStatus::Archived)->create(['title' => 'Archived Entry']);

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->set('statusFilter', PolicyJournalStatus::Archived->value)
        ->assertSee('Archived Entry')
        ->assertDontSee('Active Entry');
});

test('admin can edit a policy journal entry', function () {
    $admin = User::factory()->admin()->create();
    $entry = PolicyJournal::factory()->for($admin, 'creator')->create([
        'title' => 'Old Title',
        'status' => PolicyJournalStatus::Draft,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->call('editEntry', $entry->id)
        ->set('title', 'Updated Title')
        ->set('status', PolicyJournalStatus::Active->value)
        ->call('saveEntry')
        ->assertHasNoErrors();

    $entry->refresh();

    expect($entry->title)->toBe('Updated Title')
        ->and($entry->status)->toBe(PolicyJournalStatus::Active);
});

test('admin can delete a policy journal entry', function () {
    $admin = User::factory()->admin()->create();
    $entry = PolicyJournal::factory()->for($admin, 'creator')->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->call('confirmDelete', $entry->id)
        ->call('deleteEntry')
        ->assertHasNoErrors();

    expect(PolicyJournal::find($entry->id))->toBeNull();
});

test('deleting an entry removes stored attachments', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $file = UploadedFile::fake()->image('policy.png');
    $path = $file->store('policy-journals/1', 'local');

    $entry = PolicyJournal::factory()->for($admin, 'creator')->create([
        'attachments' => [
            ['path' => $path, 'original_name' => 'policy.png', 'size' => $file->getSize()],
        ],
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->call('confirmDelete', $entry->id)
        ->call('deleteEntry');

    Storage::disk('local')->assertMissing($path);
});

test('policy journal page displays entries', function () {
    $admin = User::factory()->admin()->create();
    $entry = PolicyJournal::factory()->for($admin, 'creator')->create(['title' => 'Displayed Entry']);

    Livewire::actingAs($admin)
        ->test('pages::admin.policy-journal')
        ->assertSee('Displayed Entry');
});
