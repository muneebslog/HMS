<?php

use App\Enums\FinanceShiftPeriod;
use App\Models\AppSetting;
use App\Models\FinanceExpense;
use App\Models\ShiftSettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('finance-owner:127.0.0.1');
});

test('the owner finance page opens without logging in and asks for a password', function () {
    AppSetting::set(AppSetting::FinanceOwnerPassword, Hash::make('secret123'));
    ShiftSettlement::factory()->create(['business_date' => now()->toDateString(), 'received_amount' => 12345]);

    $this->get(route('finance.owner'))
        ->assertOk()
        ->assertSee(__('Enter password'))
        ->assertDontSee('12,345');
});

test('the page says it is not available when no password is set', function () {
    Livewire::test('pages::finance.owner')
        ->assertSee(__('Not available'))
        ->set('password', 'anything')
        ->call('unlock')
        ->assertHasErrors('password')
        ->assertSet('unlocked', false);
});

test('a wrong password is rejected and repeated tries are throttled', function () {
    AppSetting::set(AppSetting::FinanceOwnerPassword, Hash::make('secret123'));

    $component = Livewire::test('pages::finance.owner');

    foreach (range(1, 5) as $attempt) {
        $component->set('password', 'wrong')->call('unlock')->assertHasErrors('password');
    }

    $component->set('password', 'secret123')
        ->call('unlock')
        ->assertHasErrors('password')
        ->assertSet('unlocked', false);
});

test('the right password shows the month stats and the ledger for the range', function () {
    $this->travelTo('2026-09-24 12:00:00');
    AppSetting::set(AppSetting::FinanceOwnerPassword, Hash::make('secret123'));

    ShiftSettlement::factory()->create(['business_date' => '2026-09-24', 'period' => FinanceShiftPeriod::Night, 'received_amount' => 39500]);
    ShiftSettlement::factory()->create(['business_date' => '2026-09-24', 'period' => FinanceShiftPeriod::Morning, 'received_amount' => 80770]);
    ShiftSettlement::factory()->create(['business_date' => '2026-09-10', 'period' => FinanceShiftPeriod::Evening, 'received_amount' => 1000]);
    ShiftSettlement::factory()->create(['business_date' => '2026-08-30', 'period' => FinanceShiftPeriod::Evening, 'received_amount' => 5000]);
    FinanceExpense::factory()->create(['expense_date' => '2026-09-24', 'name' => 'Sidra', 'amount' => 10000]);

    $component = Livewire::test('pages::finance.owner')
        ->set('password', 'secret123')
        ->call('unlock')
        ->assertHasNoErrors()
        ->assertSet('unlocked', true)
        ->assertSet('month.received', 121270.0)
        ->assertSet('month.expenses', 10000.0)
        ->assertSet('month.net', 111270.0)
        ->assertSet('from', '2026-09-18')
        ->assertSet('to', '2026-09-24')
        ->assertSee('Sidra')
        ->assertSee('39,500');

    $ledger = $component->instance()->ledger;
    $today = $ledger->first();

    expect($ledger)->toHaveCount(7)
        ->and($today['date']->toDateString())->toBe('2026-09-24')
        ->and($today['night'])->toBe(39500.0)
        ->and($today['morning'])->toBe(80770.0)
        ->and($today['evening'])->toBeNull()
        ->and($today['net'])->toBe(110270.0);

    $component->call('setRange', 'last-month')
        ->assertSet('from', '2026-08-01')
        ->assertSet('to', '2026-08-31')
        ->assertSet('rangeTotals.received', 5000.0);
});

test('changing the password locks out people who used the old one', function () {
    AppSetting::set(AppSetting::FinanceOwnerPassword, Hash::make('secret123'));

    $component = Livewire::test('pages::finance.owner')
        ->set('password', 'secret123')
        ->call('unlock')
        ->assertSet('unlocked', true);

    AppSetting::set(AppSetting::FinanceOwnerPassword, Hash::make('new-secret'));

    $component->call('$refresh')->assertSet('unlocked', false)->assertSee(__('Enter password'));
});

test('the date range is kept in order', function () {
    $this->travelTo('2026-09-24 12:00:00');
    AppSetting::set(AppSetting::FinanceOwnerPassword, Hash::make('secret123'));

    Livewire::test('pages::finance.owner')
        ->set('password', 'secret123')
        ->call('unlock')
        ->set('from', '2026-09-30')
        ->assertSet('from', '2026-09-24')
        ->assertSet('to', '2026-09-30');
});

test('management CRUD can set, change and switch off the owner link password', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::management.crud')
        ->call('switchTab', 'notifications')
        ->assertSee(route('finance.owner'))
        ->set('financeOwnerPassword', 'abc')
        ->call('saveFinanceOwnerPassword')
        ->assertHasErrors(['financeOwnerPassword' => 'min'])
        ->set('financeOwnerPassword', 'boss-2026')
        ->call('saveFinanceOwnerPassword')
        ->assertHasNoErrors()
        ->assertSet('financeOwnerPassword', '');

    expect(Hash::check('boss-2026', AppSetting::financeOwnerPasswordHash()))->toBeTrue();

    Livewire::actingAs($admin)
        ->test('pages::management.crud')
        ->call('disableFinanceOwnerLink');

    expect(AppSetting::financeOwnerPasswordHash())->toBeNull();
});
