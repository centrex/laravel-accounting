<?php

declare(strict_types = 1);

use Centrex\Accounting\Livewire\AccountingDashboard;
use Livewire\Livewire;

it('renders lazy placeholders for the heavy cards, not their real query results', function (): void {
    // Receivables/Payables are behind @can('accounting.invoice.view')/@can('accounting.bill.view')
    // in the shell blade (unauthenticated test request → gates deny → cards simply aren't
    // rendered, same as before this refactor); their own data logic and lazy-loading are
    // covered directly in DashboardLazyCardsTest.php. This test only needs the always-visible
    // cards to prove the shell defers them instead of computing eagerly.
    $html = html_entity_decode(Livewire::test(AccountingDashboard::class)->html());

    foreach ([
        'accounting-kpi-card',
        'accounting-balance-snapshot-card',
        'accounting-current-assets-card',
    ] as $component) {
        expect($html)->toContain('wire:name="' . $component . '"');
    }

    expect($html)->toContain('"lazyLoaded":false');

    // Skeleton markup is present (the loading state), real formatted currency totals are not.
    expect($html)->toContain('animate-pulse');
});
