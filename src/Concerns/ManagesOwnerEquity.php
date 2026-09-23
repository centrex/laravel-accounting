<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Models\{Account, JournalEntry, Owner};
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;

trait ManagesOwnerEquity
{
    /**
     * Register an owner/partner and auto-create their dedicated Capital and Drawings GL
     * sub-accounts under the standard aggregate 3000/3200 accounts, so per-owner detail rolls
     * up into the existing Balance Sheet equity total automatically (both sub-accounts carry
     * type=equity, same as their parent).
     *
     * Capital sub-accounts allocate under parent 3000, range 3001–3099.
     * Drawings sub-accounts allocate under parent 3200, range 3201–3299.
     *
     * @param  array{code: string, name: string, email?: ?string, ownership_percentage?: ?float, notes?: ?string, is_active?: bool}  $data
     */
    public function addOwner(array $data): Owner
    {
        return DB::transaction(function () use ($data): Owner {
            $capitalParent = $this->requireAccount(config('accounting.accounts.capital', '3000'));
            $drawingsParent = $this->requireAccount(config('accounting.accounts.owner_drawings', '3200'));

            $capitalCode = $this->nextSubAccountCode($capitalParent->code, '3099');
            $drawingsCode = $this->nextSubAccountCode($drawingsParent->code, '3299');

            $shortName = Str::limit($data['name'], 30, '');

            $capitalAccount = Account::create([
                'code'      => $capitalCode,
                'name'      => "Capital — {$shortName}",
                'type'      => 'equity',
                'subtype'   => 'capital_account',
                'parent_id' => $capitalParent->id,
                'is_system' => false,
            ]);

            $drawingsAccount = Account::create([
                'code'      => $drawingsCode,
                'name'      => "Drawings — {$shortName}",
                'type'      => 'equity',
                'subtype'   => 'drawings_account',
                'parent_id' => $drawingsParent->id,
                'is_system' => false,
            ]);

            return Owner::create([
                'code'                 => $data['code'],
                'name'                 => $data['name'],
                'email'                => $data['email'] ?? null,
                'ownership_percentage' => $data['ownership_percentage'] ?? null,
                'capital_account_id'   => $capitalAccount->id,
                'drawings_account_id'  => $drawingsAccount->id,
                'notes'                => $data['notes'] ?? null,
                'is_active'            => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Record a capital contribution from a specific owner — DR the deposit account / CR that
     * owner's own Capital sub-account (see addOwner()), instead of the aggregate 3000 account.
     *
     * A foreign owner may contribute in their own currency: pass `currency`/`exchange_rate`
     * and `amount` is treated as that currency, converted to the accounting base currency for
     * the journal entry (same handling as Invoice/Bill/LoanFacility). Unlike a loan, equity has
     * no running balance to reconcile against, so this is a one-off per-transaction conversion —
     * there's nothing persisted on Owner itself.
     *
     * @param  array{amount: float, date?: string, deposit_account_code?: string, description?: ?string, currency?: ?string, exchange_rate?: float|int|string|null}  $data
     */
    public function recordOwnerContribution(Owner $owner, array $data): JournalEntry
    {
        $depositAccount = $this->requireAccount($data['deposit_account_code'] ?? config('accounting.accounts.bank', '1100'));

        $entry = $this->createJournalEntry([
            'date'          => $data['date'] ?? now()->toDateString(),
            'reference'     => 'CAP-' . $owner->code . '-' . now()->format('YmdHis'),
            'type'          => 'general',
            'description'   => $data['description'] ?? "Capital contribution — {$owner->name}",
            'currency'      => $data['currency'] ?? $this->baseCurrency(),
            'exchange_rate' => $this->normalizeExchangeRate($data['exchange_rate'] ?? 1),
            'lines'         => [
                ['account_id' => $depositAccount->id, 'type' => 'debit', 'amount' => (float) $data['amount']],
                ['account_id' => $owner->capital_account_id, 'type' => 'credit', 'amount' => (float) $data['amount']],
            ],
        ]);
        $entry->post();

        return $entry;
    }

    /**
     * Record a drawing/withdrawal for a specific owner — DR that owner's own Drawings
     * sub-account / CR the source account, instead of the aggregate 3200 account.
     *
     * A foreign owner may draw in their own currency: pass `currency`/`exchange_rate` and
     * `amount` is treated as that currency, converted to the accounting base currency for the
     * journal entry (same handling as recordOwnerContribution()).
     *
     * @param  array{amount: float, date?: string, source_account_code?: string, description?: ?string, currency?: ?string, exchange_rate?: float|int|string|null}  $data
     */
    public function recordOwnerDrawing(Owner $owner, array $data): JournalEntry
    {
        $sourceAccount = $this->requireAccount($data['source_account_code'] ?? config('accounting.accounts.bank', '1100'));

        $entry = $this->createJournalEntry([
            'date'          => $data['date'] ?? now()->toDateString(),
            'reference'     => 'DRAW-' . $owner->code . '-' . now()->format('YmdHis'),
            'type'          => 'general',
            'description'   => $data['description'] ?? "Owner drawing — {$owner->name}",
            'currency'      => $data['currency'] ?? $this->baseCurrency(),
            'exchange_rate' => $this->normalizeExchangeRate($data['exchange_rate'] ?? 1),
            'lines'         => [
                ['account_id' => $owner->drawings_account_id, 'type' => 'debit', 'amount' => (float) $data['amount']],
                ['account_id' => $sourceAccount->id, 'type' => 'credit', 'amount' => (float) $data['amount']],
            ],
        ]);
        $entry->post();

        return $entry;
    }

    /**
     * Per-owner capital/drawings/net-equity breakdown for reporting — the "By Owner" table
     * on the Owner's Equity page.
     *
     * @return Collection<int, array{owner: Owner, capital_balance: float, drawings_balance: float, net_equity: float}>
     */
    public function getOwnerEquitySummary(): Collection
    {
        return Owner::query()
            ->where('is_active', true)
            ->with(['capitalAccount', 'drawingsAccount'])
            ->orderBy('name')
            ->get()
            ->map(fn (Owner $owner): array => [
                'owner'            => $owner,
                'capital_balance'  => $owner->capitalAccount->getCurrentBalance(),
                'drawings_balance' => $owner->drawingsAccount->getCurrentBalance(),
                'net_equity'       => $owner->equityBalance(),
            ]);
    }
}
