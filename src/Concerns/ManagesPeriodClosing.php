<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Contracts\InventorySnapshotProvider;
use Centrex\Accounting\Exceptions\InvalidStatusTransitionException;
use Centrex\Accounting\Models\{Account, AccountBalance, FiscalPeriod, PeriodInventorySnapshot};
use Centrex\Accounting\Support\DayRange;
use Illuminate\Support\Facades\DB;

trait ManagesPeriodClosing
{
    /**
     * Pre-close checks for a fiscal period.
     * Returns counts of items that block or warn before closing.
     */
    public function getPeriodCloseChecks(FiscalPeriod $period): array
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $db = DB::connection($connection);

        $unpostedJournals = $db->table("{$prefix}journal_entries")
            ->whereNull('deleted_at')
            ->where('status', 'draft')
            ->where('date', '>=', $period->start_date)
            ->where('date', '<=', $period->end_date)
            ->count();

        $openInvoices = $db->table("{$prefix}invoices")
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'sent'])
            ->where('invoice_date', '>=', $period->start_date)
            ->where('invoice_date', '<=', $period->end_date)
            ->count();

        $openBills = $db->table("{$prefix}bills")
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'sent'])
            ->where('bill_date', '>=', $period->start_date)
            ->where('bill_date', '<=', $period->end_date)
            ->count();

        return [
            'unposted_journals' => $unpostedJournals,
            'open_invoices'     => $openInvoices,
            'open_bills'        => $openBills,
            'has_blockers'      => $unpostedJournals > 0,
            'has_warnings'      => $openInvoices > 0 || $openBills > 0,
        ];
    }

    /**
     * Close a fiscal period: snapshot GL balances, optionally snapshot inventory WAC/qty, then lock the period.
     *
     * @return array{period: FiscalPeriod, inventory: array|null}
     */
    public function closeFiscalPeriod(FiscalPeriod $period, bool $snapshotInventory = true): array
    {
        return DB::transaction(function () use ($period, $snapshotInventory): array {
            $period = FiscalPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->is_closed) {
                throw InvalidStatusTransitionException::make('FiscalPeriod', 'closed', 'closed');
            }

            $inventoryResult = null;

            if ($snapshotInventory && $this->inventoryIntegrationEnabled()) {
                $inventoryResult = $this->takeInventoryPeriodSnapshot($period);
            }

            $this->upsertAccountBalancesForPeriod($period);

            $period->update(['is_closed' => true]);

            return [
                'period'    => $period->fresh(),
                'inventory' => $inventoryResult,
            ];
        });
    }

    private function inventoryIntegrationEnabled(): bool
    {
        return $this->inventorySnapshotProvider() instanceof InventorySnapshotProvider;
    }

    /**
     * Snapshot WAC and qty_on_hand per warehouse+product at period-end and reconcile against GL.
     */
    private function takeInventoryPeriodSnapshot(FiscalPeriod $period): array
    {
        $currency = $this->baseCurrency();
        $snapshot = $this->inventorySnapshotProvider()?->snapshotForPeriod($period, $currency) ?? [];

        // Idempotent: remove any previous snapshot for this period before re-inserting
        PeriodInventorySnapshot::where('fiscal_period_id', $period->id)->delete();

        $rows = collect($snapshot['rows'] ?? [])
            ->map(function (array $row) use ($period, $currency): array {
                return array_merge($row, [
                    'fiscal_period_id' => $period->id,
                    'currency'         => $row['currency'] ?? $currency,
                    'snapshot_date'    => $row['snapshot_date'] ?? $period->end_date,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            })
            ->toArray();

        if ($rows !== []) {
            PeriodInventorySnapshot::insert($rows);
        }

        $physicalValue = (float) collect($rows)->sum('total_value');
        $inventoryAccountCode = (string) ($snapshot['inventory_account_code'] ?? $this->accountCode('inventory'));
        $inventoryAccountCode = $inventoryAccountCode !== '' ? $inventoryAccountCode : '1300';
        $glBalance = $this->getAccountGlBalance($inventoryAccountCode, $period->end_date);
        $variance = round($physicalValue - $glBalance, 2);

        return [
            'snapshot_count' => count($rows),
            'physical_value' => $physicalValue,
            'gl_balance'     => $glBalance,
            'variance'       => $variance,
            'is_reconciled'  => abs($variance) < 1.0,
            'currency'       => $currency,
        ];
    }

    private function inventorySnapshotProvider(): ?InventorySnapshotProvider
    {
        $providerClass = config('accounting.integrations.inventory.snapshot_provider');

        if (!is_string($providerClass) || $providerClass === '' || !class_exists($providerClass)) {
            return null;
        }

        $provider = app($providerClass);

        return $provider instanceof InventorySnapshotProvider ? $provider : null;
    }

    /** Get the net GL running balance of an account code up to (and including) a given date. */
    private function getAccountGlBalance(string $accountCode, mixed $asOfDate): float
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $account = Account::where('code', $accountCode)->first();

        if (!$account) {
            return 0.0;
        }

        $row = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereNull('je.deleted_at')
            ->where('l.account_id', $account->id)
            ->when($asOfDate, fn ($q) => $q->where('je.date', '<', DayRange::endOfDay($asOfDate)))
            ->selectRaw("SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE -l.amount END) as balance")
            ->first();

        return round((float) ($row->balance ?? 0), 2);
    }

    /** Compute period-level debit/credit totals and upsert them into acct_account_balances. */
    private function upsertAccountBalancesForPeriod(FiscalPeriod $period): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        $rows = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereNull('je.deleted_at')
            ->where('je.date', '>=', $period->start_date)
            ->where('je.date', '<=', $period->end_date)
            ->select([
                'l.account_id',
                DB::raw("SUM(CASE WHEN l.type = 'debit'  THEN l.amount ELSE 0 END) as debit"),
                DB::raw("SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as credit"),
            ])
            ->groupBy('l.account_id')
            ->get();

        foreach ($rows as $row) {
            AccountBalance::updateOrCreate(
                ['account_id' => $row->account_id, 'fiscal_period_id' => $period->id],
                [
                    'debit'   => $row->debit,
                    'credit'  => $row->credit,
                    'balance' => (float) $row->debit - (float) $row->credit,
                ],
            );
        }
    }
}
