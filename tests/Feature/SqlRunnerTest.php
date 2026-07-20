<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.sql-runner'));

    $response->assertRedirect(route('login'));
});

test('non admin users cannot access the sql runner page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('admin.sql-runner'));

    $response->assertForbidden();
});

test('admins can visit the sql runner page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.sql-runner'));

    $response->assertOk();
});

test('admins can run a select query and see results', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sql-runner')
        ->set('sql', 'SELECT id, email FROM users WHERE id = '.$admin->id)
        ->call('run')
        ->assertSet('error', null)
        ->assertSet('resultCount', 1)
        ->assertSet('requiresConfirmation', false)
        ->assertSee($admin->email);
});

test('non select queries require confirmation', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sql-runner')
        ->set('sql', 'UPDATE users SET name = "Confirmed Name" WHERE id = '.$admin->id)
        ->call('run')
        ->assertSet('requiresConfirmation', true)
        ->assertSet('detectedCommand', 'UPDATE')
        ->assertSee('UPDATE');

    expect($admin->fresh()->name)->not->toBe('Confirmed Name');
});

test('confirmed non select queries execute', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sql-runner')
        ->set('sql', 'UPDATE users SET name = "Confirmed Name" WHERE id = '.$admin->id)
        ->call('run')
        ->call('confirmRun')
        ->assertSet('requiresConfirmation', false)
        ->assertSet('rowsAffected', 1)
        ->assertSet('error', null);

    expect($admin->fresh()->name)->toBe('Confirmed Name');
});

test('cancelled non select queries do not execute', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sql-runner')
        ->set('sql', 'UPDATE users SET name = "Cancelled Name" WHERE id = '.$admin->id)
        ->call('run')
        ->call('cancelRun')
        ->assertSet('requiresConfirmation', false);

    expect($admin->fresh()->name)->not->toBe('Cancelled Name');
});

test('invalid sql shows an error', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sql-runner')
        ->set('sql', 'SELECT * FROM missing_table_xyz')
        ->call('run')
        ->assertSet('results', null)
        ->assertSet('error', fn ($error) => filled($error));
});

test('insert query can be confirmed and executed', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.sql-runner')
        ->set('sql', 'INSERT INTO users (name, email, role, password, created_at, updated_at) VALUES ("SQL User", "sql@example.com", "user", "secret", datetime("now"), datetime("now"))')
        ->call('run')
        ->assertSet('requiresConfirmation', true)
        ->call('confirmRun')
        ->assertSet('rowsAffected', 1)
        ->assertSet('error', null);

    expect(DB::table('users')->where('email', 'sql@example.com')->exists())->toBeTrue();
});
