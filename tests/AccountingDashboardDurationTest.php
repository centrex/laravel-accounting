<?php

declare(strict_types = 1);

use Centrex\Accounting\Livewire\AccountingDashboard;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('computes last month as the full previous calendar month', function (): void {
    Carbon::setTestNow('2026-03-15 10:00:00');

    Livewire::test(AccountingDashboard::class)
        ->set('dateRange', 'last_month')
        ->assertSet('startDate', fn (mixed $startDate) => $startDate->toDateString() === '2026-02-01')
        ->assertSet('endDate', fn (mixed $endDate) => $endDate->toDateString() === '2026-02-28');
});

it('does not overflow into a later month when the current month has more days than last month', function (): void {
    // Mar 31 minus one calendar month must land on Feb 28 (2026 is not a leap year), not
    // spill into March — subMonthNoOverflow() clamps to the shorter month's last day
    // instead of Carbon's default subMonth() behaviour (Mar 31 - 1 month => Mar 3).
    Carbon::setTestNow('2026-03-31 10:00:00');

    Livewire::test(AccountingDashboard::class)
        ->set('dateRange', 'last_month')
        ->assertSet('startDate', fn (mixed $startDate) => $startDate->toDateString() === '2026-02-01')
        ->assertSet('endDate', fn (mixed $endDate) => $endDate->toDateString() === '2026-02-28');
});

it('rolls back across a year boundary', function (): void {
    Carbon::setTestNow('2026-01-10 10:00:00');

    Livewire::test(AccountingDashboard::class)
        ->set('dateRange', 'last_month')
        ->assertSet('startDate', fn (mixed $startDate) => $startDate->toDateString() === '2025-12-01')
        ->assertSet('endDate', fn (mixed $endDate) => $endDate->toDateString() === '2025-12-31');
});

it('lists last month as a duration option in the dashboard select', function (): void {
    $html = html_entity_decode(Livewire::test(AccountingDashboard::class)->html());

    expect($html)->toContain('<option value="last_month">Last Month</option>');
});
