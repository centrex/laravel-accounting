<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Exceptions\AccountNotFoundException;
use Centrex\Accounting\Models\{Account, Bill, Expense, Invoice};
use Centrex\Accounting\Support\DayRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cross-cutting helpers every other Accounting concern depends on: rounding tolerance,
 * currency conversion, SBU-code resolution, account lookups, and the shared
 * journal-line balance aggregate (with its per-report memoisation) that every
 * financial report is ultimately built from.
 *
 * Declared first among the traits `use`'d by the Accounting class — everything else
 * assumes these methods and $reportMemo already exist.
 */
trait HasSharedAccountingHelpers
{
    /** Rounding tolerance used consistently across all balance checks. */
    private function tolerance(): float
    {
        return (float) config('accounting.rounding_tolerance', 0.005);
    }

    private function baseCurrency(): string
    {
        return strtoupper((string) config('accounting.base_currency', 'BDT'));
    }

    private function normalizeExchangeRate(float|int|string|null $exchangeRate): float
    {
        $rate = (float) ($exchangeRate ?? 1);

        return $rate > 0 ? $rate : 1.0;
    }

    private function convertToBaseAmount(float|int|string|null $amount, ?string $currency = null, float|int|string|null $exchangeRate = null): float
    {
        $value = (float) ($amount ?? 0);
        $sourceCurrency = strtoupper((string) ($currency ?: $this->baseCurrency()));

        if ($sourceCurrency === $this->baseCurrency()) {
            return round($value, 2);
        }

        return round($value * $this->normalizeExchangeRate($exchangeRate), 2);
    }

    private function normalizeSbuCode(?string $sbuCode): ?string
    {
        $value = strtoupper(trim((string) $sbuCode));

        return $value !== '' ? $value : null;
    }

    private function applySbuFilter(mixed $query, ?string $sbuCode, string $journalEntryAlias = 'je'): mixed
    {
        $sbuCode = $this->normalizeSbuCode($sbuCode);

        if ($sbuCode === null) {
            return $query;
        }

        return $query->where("{$journalEntryAlias}.sbu_code", $sbuCode);
    }

    private function extractSbuCodeFromMeta(mixed $meta): ?string
    {
        if (!is_array($meta)) {
            return null;
        }

        return $this->normalizeSbuCode(
            $meta['default_sbu'] ?? $meta['sbu_code'] ?? $meta['sbu'] ?? null,
        );
    }

    private function resolveModelSbuCode(mixed $model): ?string
    {
        if (!is_object($model)) {
            return null;
        }

        // Use getAttributes() to guard against MissingAttributeException on models
        // (e.g. App\Models\User) that don't have a meta column.
        if (method_exists($model, 'getAttributes')) {
            $meta = array_key_exists('meta', $model->getAttributes())
                ? $model->getAttribute('meta')
                : null;
        } else {
            $meta = $model->meta ?? null;
        }

        return $this->extractSbuCodeFromMeta($meta);
    }

    /** Resolve a semantic account key to a code from config, with a hardcoded fallback. */
    private function accountCode(string $key): string
    {
        return (string) config("accounting.accounts.{$key}");
    }

    private function resolveInvoiceSbuCode(Invoice $invoice): ?string
    {
        $existingEntrySbu = $this->normalizeSbuCode($invoice->journalEntry?->sbu_code);

        if ($existingEntrySbu !== null) {
            return $existingEntrySbu;
        }

        $documentSbu = $this->normalizeSbuCode($invoice->sbu_code);

        if ($documentSbu !== null) {
            return $documentSbu;
        }

        $customer = $invoice->relationLoaded('customer') ? $invoice->customer : $invoice->customer()->with('modelable')->first();

        return $this->resolveModelSbuCode($customer?->modelable) ?? $this->resolveModelSbuCode($customer);
    }

    private function resolveBillSbuCode(Bill $bill): ?string
    {
        $existingEntrySbu = $this->normalizeSbuCode($bill->journalEntry?->sbu_code);

        if ($existingEntrySbu !== null) {
            return $existingEntrySbu;
        }

        $documentSbu = $this->normalizeSbuCode($bill->sbu_code);

        if ($documentSbu !== null) {
            return $documentSbu;
        }

        $vendor = $bill->relationLoaded('vendor') ? $bill->vendor : $bill->vendor()->with('modelable')->first();

        return $this->resolveModelSbuCode($vendor?->modelable) ?? $this->resolveModelSbuCode($vendor);
    }

    private function resolveExpenseSbuCode(Expense $expense, ?array $paymentData = null): ?string
    {
        $paymentSbu = $this->normalizeSbuCode($paymentData['sbu_code'] ?? null);

        if ($paymentSbu !== null) {
            return $paymentSbu;
        }

        return $this->normalizeSbuCode($expense->journalEntry?->sbu_code);
    }

    /**
     * Normalize a journal payload into base currency before persistence.
     *
     * @param  array{currency?: string, exchange_rate?: float, lines?: array<int, array{amount?: float|int|string}>}  $data
     * @return array<string, mixed>
     */
    private function normalizeJournalPayload(array $data): array
    {
        $currency = strtoupper((string) ($data['currency'] ?? $this->baseCurrency()));
        $exchangeRate = $this->normalizeExchangeRate($data['exchange_rate'] ?? 1);

        $data['currency'] = $this->baseCurrency();
        $data['exchange_rate'] = 1.0;
        $data['lines'] = collect($data['lines'] ?? [])
            ->map(function (array $line) use ($currency, $exchangeRate): array {
                $line['amount'] = $this->convertToBaseAmount($line['amount'] ?? 0, $currency, $exchangeRate);

                return $line;
            })
            ->all();

        return $data;
    }

    /** Resolve a required account by code or throw a typed exception. */
    private function requireAccount(string $code): Account
    {
        return Account::where('code', $code)->where('is_active', true)->first()
            ?? throw AccountNotFoundException::forCode($code);
    }

    /**
     * Find the next unused account code within [rangeStart, rangeEnd].
     * Used to allocate per-lender sub-accounts sequentially.
     */
    private function nextSubAccountCode(string $rangeStart, string $rangeEnd): string
    {
        $existing = Account::whereBetween('code', [$rangeStart, $rangeEnd])
            ->pluck('code')
            ->map(fn ($c): int => (int) $c)
            ->sort()
            ->values();

        $start = (int) $rangeStart;
        $end = (int) $rangeEnd;

        for ($code = $start + 1; $code <= $end; $code++) {
            if (!$existing->contains($code)) {
                return (string) $code;
            }
        }

        throw new \RuntimeException(
            "No available account codes between {$rangeStart} and {$rangeEnd}. Add more range capacity.",
        );
    }

    /**
     * Per-report memo for buildBalanceMap()/account-list lookups.
     *
     * null means memoisation is off — see {@see withReportMemo()} for why this is
     * scoped to one report rather than kept for the life of the (singleton) instance.
     *
     * @var array<string, mixed>|null
     */
    private ?array $reportMemo = null;

    /**
     * Run $report with balance-map and account-list lookups memoised.
     *
     * The composite reports re-derive the same aggregate over and over: a balance sheet
     * asks for it five times with identical arguments (three account types, plus two more
     * inside getNetIncome()), a cash flow statement thirteen times, and the all-sheets
     * Excel export around twenty — each one a full GROUP BY across every posted journal
     * entry line, plus a repeat of the same Account lookup.
     *
     * This is deliberately *not* a request-lifetime cache. `accounting` is bound as a
     * singleton, so holding results across a whole request would serve pre-write numbers
     * to any report generated after a posting in the same request. Scoped to a single
     * report call the underlying data cannot change, so the memo is always consistent.
     *
     * Nested calls (getCashFlowStatement() -> getBalanceSheet()) share the outer scope
     * rather than opening their own, which is what collapses the repeats.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $report
     * @return TReturn
     */
    private function withReportMemo(callable $report): mixed
    {
        if ($this->reportMemo !== null) {
            return $report();
        }

        $this->reportMemo = [];

        try {
            return $report();
        } finally {
            $this->reportMemo = null;
        }
    }

    /**
     * Generate several reports for the same period under one shared memo scope, so the
     * journal-line aggregate and account lookups they have in common are computed once
     * across the whole batch rather than once per report.
     *
     * Intended for callers that emit a full report pack in a single pass (the all-sheets
     * Excel export, the `accounting:report --type=all` command).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $reports
     * @return TReturn
     */
    public function withSharedReportCache(callable $reports): mixed
    {
        return $this->withReportMemo($reports);
    }

    /**
     * Memoise $resolver under $key for the duration of the current withReportMemo() scope.
     * Outside such a scope the value is always recomputed.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $resolver
     * @return TValue
     */
    private function rememberForReport(string $key, callable $resolver): mixed
    {
        if ($this->reportMemo === null) {
            return $resolver();
        }

        /** @var TValue */
        return $this->reportMemo[$key] ??= $resolver();
    }

    /** Stable memo key for a (start, end, sbu) triple that may hold nulls, strings or Carbon instances. */
    private function reportPeriodKey(mixed $startDate, mixed $endDate, ?string $sbuCode): string
    {
        $stringify = static fn (mixed $d): string => match (true) {
            $d === null                      => '',
            $d instanceof \DateTimeInterface => $d->format('Y-m-d'),
            is_scalar($d)                    => (string) $d,
            default                          => serialize($d),
        };

        return $stringify($startDate) . '|' . $stringify($endDate) . '|' . ($sbuCode ?? '');
    }

    /**
     * Single aggregated query for journal-entry-line balances.
     * Replaces the N+1 pattern (3 queries per account) in every report method.
     *
     * Returns a Collection keyed by account_id, each item having
     * `total_debit` and `total_credit` properties.
     *
     * @return Collection<int|string, object>
     */
    private function buildBalanceMap(mixed $startDate, mixed $endDate, ?string $sbuCode = null): Collection
    {
        /** @var Collection<int|string, object> */
        return $this->rememberForReport(
            'balances:' . $this->reportPeriodKey($startDate, $endDate, $sbuCode),
            fn (): Collection => $this->queryBalanceMap($startDate, $endDate, $sbuCode),
        );
    }

    /** @return Collection<int|string, object> */
    private function queryBalanceMap(mixed $startDate, mixed $endDate, ?string $sbuCode): Collection
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        $query = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereNull('je.deleted_at')
            ->when($startDate, fn ($q) => $q->where('je.date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('je.date', '<', DayRange::endOfDay($endDate)))
            ->select([
                'l.account_id',
                DB::raw("SUM(CASE WHEN l.type = 'debit'  THEN l.amount ELSE 0 END) as total_debit"),
                DB::raw("SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as total_credit"),
            ])
            ->groupBy('l.account_id');

        return $this->applySbuFilter($query, $sbuCode)->get()->keyBy('account_id');
    }

    private function requireAccountById(int $accountId): Account
    {
        $account = Account::find($accountId);

        if ($account === null) {
            throw AccountNotFoundException::forCode((string) $accountId);
        }

        return $account;
    }

    protected function getNetIncome(mixed $startDate, mixed $endDate, ?string $sbuCode = null): float
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate, $sbuCode);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate, $sbuCode);

        return (float) (($revenue['total'] ?? 0) - ($expenses['total'] ?? 0));
    }

    /**
     * Get accounts of a given type with their balances.
     * Uses the shared balance map — no per-account queries.
     *
     * @param  string[]  $onlySubtypes  restrict to these subtypes (empty = all)
     * @param  string[]  $excludeSubtypes  exclude these subtypes
     */
    protected function getAccountsByType(string $type, mixed $endDate, mixed $startDate = null, ?string $sbuCode = null, array $onlySubtypes = [], array $excludeSubtypes = []): array
    {
        $tolerance = $this->tolerance();
        $accounts = $this->rememberForReport(
            'accounts:' . $type . '|' . implode(',', $onlySubtypes) . '|' . implode(',', $excludeSubtypes),
            fn (): Collection => Account::where('type', $type)->where('is_active', true)
                ->when($onlySubtypes !== [], fn ($q) => $q->whereIn('subtype', $onlySubtypes))
                ->when($excludeSubtypes !== [], fn ($q) => $q->whereNotIn('subtype', $excludeSubtypes))
                ->orderBy('code')->get(),
        );
        $balanceMap = $this->buildBalanceMap($startDate, $endDate, $sbuCode);

        $accountsData = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            $row = $balanceMap->get($account->id);
            $debits = (float) ($row?->total_debit ?? 0);
            $credits = (float) ($row?->total_credit ?? 0);

            $balance = $account->isDebitAccount()
                ? ($debits - $credits)
                : ($credits - $debits);

            if (abs($balance) > $tolerance) {
                $accountsData[] = ['account' => $account, 'balance' => $balance];
                $total += $balance;
            }
        }

        return ['accounts' => $accountsData, 'total' => $total, 'sbu_code' => $this->normalizeSbuCode($sbuCode)];
    }
}
