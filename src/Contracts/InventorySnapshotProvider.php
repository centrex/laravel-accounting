<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Contracts;

use Centrex\Accounting\Models\FiscalPeriod;

interface InventorySnapshotProvider
{
    /**
     * @return array{rows: list<array<string, mixed>>, inventory_account_code?: string|null}
     */
    public function snapshotForPeriod(FiscalPeriod $period, string $currency): array;
}
