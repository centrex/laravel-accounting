<?php

declare(strict_types = 1);

use Centrex\Accounting\Facades\Accounting;
use Illuminate\Support\Facades\DB;

function balanceAggregateQueries(callable $fn): int
{
    $count = 0;
    DB::listen(function ($q) use (&$count): void {
        if (str_contains($q->sql, 'journal_entry_lines') && str_contains($q->sql, 'group by')) {
            $count++;
        }
    });
    $fn();

    return $count;
}

it('computes the journal-line aggregate once per distinct period in a balance sheet', function (): void {
    Accounting::initializeChartOfAccounts();

    expect(balanceAggregateQueries(fn () => Accounting::getBalanceSheet('2025-12-31')))->toBe(1);
});

it('computes the journal-line aggregate once per distinct period in a cash flow statement', function (): void {
    Accounting::initializeChartOfAccounts();

    // Three genuinely distinct periods — the opening balance sheet (up to the day before
    // the start), the closing balance sheet (up to the end), and the income statement
    // (start..end) — rather than the thirteen the un-memoised version issued.
    expect(balanceAggregateQueries(fn () => Accounting::getCashFlowStatement('2025-01-01', '2025-12-31')))->toBe(3);
});

it('shares one memo scope across a whole report pack', function (): void {
    Accounting::initializeChartOfAccounts();

    $service = app(Centrex\Accounting\Accounting::class);

    $shared = balanceAggregateQueries(fn () => $service->withSharedReportCache(static function () use ($service): void {
        $service->getTrialBalance('2025-01-01', '2025-12-31');
        $service->getBalanceSheet('2025-12-31');
        $service->getIncomeStatement('2025-01-01', '2025-12-31');
    }));

    $unshared = balanceAggregateQueries(static function () use ($service): void {
        $service->getTrialBalance('2025-01-01', '2025-12-31');
        $service->getBalanceSheet('2025-12-31');
        $service->getIncomeStatement('2025-01-01', '2025-12-31');
    });

    expect($shared)->toBeLessThan($unshared);
});
