<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Services;

use Centrex\Accounting\Concerns\{HasSharedAccountingHelpers, ManagesJournalEntries};
use Centrex\Accounting\Models\{Account, FixedAsset, JournalEntry};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FixedAssetService
{
    use HasSharedAccountingHelpers;
    use ManagesJournalEntries;

    /**
     * Register a fixed asset and auto-create its dedicated GL sub-accounts.
     *
     * Cost sub-accounts allocate under parent 1700 ("Fixed Assets"), range 1701–1799.
     * Accumulated-depreciation sub-accounts allocate under parent 1800, range 1801–1899.
     * Does not post a journal entry — see capitalizeFixedAsset() for that.
     */
    public function addFixedAsset(
        string $name,
        float $acquisitionCost,
        int $usefulLifeMonths,
        float $salvageValue = 0.0,
        ?string $acquiredAt = null,
        ?string $assetClass = null,
        ?string $sbuCode = null,
        ?string $location = null,
        ?string $serialNumber = null,
        ?string $notes = null,
    ): FixedAsset {
        return DB::transaction(function () use (
            $name, $acquisitionCost, $usefulLifeMonths, $salvageValue,
            $acquiredAt, $assetClass, $sbuCode, $location, $serialNumber, $notes,
        ): FixedAsset {
            $assetParent = $this->requireAccount('1700');
            $contraParent = $this->requireAccount('1800');

            $assetCode = $this->nextSubAccountCode('1700', '1799');
            $contraCode = $this->nextSubAccountCode('1800', '1899');

            $shortName = Str::limit($name, 40, '');

            $assetAccount = Account::create([
                'code'      => $assetCode,
                'name'      => $shortName,
                'type'      => 'asset',
                'subtype'   => 'fixed_asset',
                'parent_id' => $assetParent->id,
                'is_system' => false,
            ]);

            $contraAccount = Account::create([
                'code'      => $contraCode,
                'name'      => "Accum. Depr. — {$shortName}",
                'type'      => 'asset',
                'subtype'   => 'contra_account',
                'parent_id' => $contraParent->id,
                'is_system' => false,
            ]);

            return FixedAsset::create([
                'name'                                => $name,
                'asset_class'                         => $assetClass,
                'sbu_code'                            => $sbuCode ? strtoupper(trim($sbuCode)) : null,
                'asset_account_id'                    => $assetAccount->id,
                'accumulated_depreciation_account_id' => $contraAccount->id,
                'acquisition_cost'                    => $acquisitionCost,
                'salvage_value'                       => $salvageValue,
                'useful_life_months'                  => $usefulLifeMonths,
                'depreciation_method'                 => 'straight_line',
                'acquired_at'                         => $acquiredAt ?? now()->toDateString(),
                'location'                            => $location,
                'serial_number'                       => $serialNumber,
                'notes'                               => $notes,
                'is_active'                           => true,
                'created_by'                          => auth()->id(),
            ]);
        });
    }

    /**
     * Capitalize the asset: record the outlay against its GL cost account.
     *
     * DR asset account (its own 170x code) / CR payment source (bank by default,
     * or another account code such as accounts_payable for credit purchases).
     */
    public function capitalizeFixedAsset(
        FixedAsset $asset,
        string $date,
        string $reference,
        ?string $paymentAccountCode = null,
        ?string $description = null,
    ): JournalEntry {
        $paymentAccount = $this->requireAccount($paymentAccountCode ?? $this->accountCode('bank'));

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => $reference,
            'type'        => 'general',
            'sbu_code'    => $asset->sbu_code,
            'description' => $description ?? "Fixed asset capitalized — {$asset->name} ({$asset->asset_code})",
            'lines'       => [
                ['account_id' => $asset->asset_account_id, 'type' => 'debit',  'amount' => (float) $asset->acquisition_cost],
                ['account_id' => $paymentAccount->id,       'type' => 'credit', 'amount' => (float) $asset->acquisition_cost],
            ],
        ]);
    }

    /**
     * Post one period's straight-line depreciation for a single asset.
     *
     * DR Depreciation Expense (config: depreciation_expense, default 6600) / CR the
     * asset's own accumulated-depreciation account. Returns null when the asset is
     * inactive, already disposed, or fully depreciated. The final period is capped so
     * accumulated depreciation never exceeds the depreciable base.
     */
    public function depreciateAsset(FixedAsset $asset, ?string $date = null): ?JournalEntry
    {
        return DB::transaction(function () use ($asset, $date): ?JournalEntry {
            // Locked for the duration — without it, two concurrent depreciation runs for
            // the same asset (e.g. an overlapping scheduler run) could each compute
            // "remaining" from the same pre-write snapshot and each post a full period's
            // depreciation, together exceeding the depreciable base for that period.
            $asset = FixedAsset::lockForUpdate()->findOrFail($asset->id);

            if (!$asset->is_active || $asset->isDisposed() || $asset->isFullyDepreciated()) {
                return null;
            }

            // No reference-based duplicate guard here (unlike the interest-accrual
            // methods) — depreciateAsset() is designed to be called once per intended
            // period by the caller, not strictly once per calendar month; the remaining
            // vs. depreciable-base cap below is what prevents over-depreciation, and tests
            // rely on calling this method repeatedly within a single request to post
            // several periods' worth of depreciation in immediate succession.
            $remaining = round($asset->depreciableBase() - $asset->accumulatedDepreciation(), 2);
            $amount = min($asset->monthlyDepreciationAmount(), $remaining);

            if ($amount <= 0.0) {
                return null;
            }

            $date ??= now()->endOfMonth()->toDateString();
            $expenseAccount = $this->requireAccount($this->accountCode('depreciation_expense'));

            return $this->createJournalEntry([
                'date'        => $date,
                'reference'   => 'FA-DEPR-' . now()->format('Y-m') . '-' . $asset->id,
                'type'        => 'general',
                'sbu_code'    => $asset->sbu_code,
                'description' => sprintf(
                    'Depreciation — %s (%s) — %s',
                    $asset->name,
                    $asset->asset_code,
                    now()->format('F Y'),
                ),
                'lines' => [
                    ['account_id' => $expenseAccount->id,                          'type' => 'debit',  'amount' => $amount],
                    ['account_id' => $asset->accumulated_depreciation_account_id,  'type' => 'credit', 'amount' => $amount],
                ],
            ]);
        });
    }

    /**
     * Depreciate all active, non-disposed assets.
     * Returns array keyed by asset id → JournalEntry|null.
     */
    public function depreciateAllAssets(?string $date = null): array
    {
        $results = [];

        FixedAsset::where('is_active', true)->whereNull('disposed_at')
            ->each(function (FixedAsset $asset) use ($date, &$results): void {
                $results[$asset->id] = $this->depreciateAsset($asset, $date);
            });

        return $results;
    }

    /**
     * Dispose of an asset: remove it and its accumulated depreciation from the GL,
     * record any cash proceeds, and plug the gain or loss on disposal.
     *
     * netBookValue = acquisition_cost − accumulated_depreciation
     * gainOrLoss   = proceeds − netBookValue   (positive = gain, negative = loss)
     *
     * Lines: CR asset account (acquisition_cost) / DR accumulated-depreciation account
     * (its balance, if any) / DR bank (proceeds, if any) / plug the gain (credit) or
     * loss (debit) to config('accounting.accounts.gain_loss_on_disposal') (default 4910).
     */
    public function disposeAsset(
        FixedAsset $asset,
        string $date,
        float $proceeds = 0.0,
        ?string $reference = null,
    ): JournalEntry {
        return DB::transaction(function () use ($asset, $date, $proceeds, $reference): JournalEntry {
            // Locked and status-checked inside the transaction — the check used to run
            // against an $asset loaded before the transaction opened, so two concurrent
            // disposals of the same asset could both pass and both post a disposal entry,
            // double-crediting the asset account.
            $asset = FixedAsset::lockForUpdate()->findOrFail($asset->id);

            if ($asset->isDisposed()) {
                throw new \RuntimeException("Fixed asset '{$asset->asset_code}' has already been disposed.");
            }

            $accumulatedDepreciation = $asset->accumulatedDepreciation();
            $acquisitionCost = (float) $asset->acquisition_cost;
            $bookValue = round($acquisitionCost - $accumulatedDepreciation, 2);
            $gainOrLoss = round($proceeds - $bookValue, 2);

            $lines = [
                ['account_id' => $asset->asset_account_id, 'type' => 'credit', 'amount' => $acquisitionCost],
            ];

            if ($accumulatedDepreciation > 0) {
                $lines[] = ['account_id' => $asset->accumulated_depreciation_account_id, 'type' => 'debit', 'amount' => $accumulatedDepreciation];
            }

            if ($proceeds > 0) {
                $bank = $this->requireAccount($this->accountCode('bank'));
                $lines[] = ['account_id' => $bank->id, 'type' => 'debit', 'amount' => $proceeds];
            }

            if (abs($gainOrLoss) > 0.001) {
                $gainLossAccount = $this->requireAccount($this->accountCode('gain_loss_on_disposal'));
                $lines[] = $gainOrLoss > 0
                    ? ['account_id' => $gainLossAccount->id, 'type' => 'credit', 'amount' => $gainOrLoss]
                    : ['account_id' => $gainLossAccount->id, 'type' => 'debit',  'amount' => abs($gainOrLoss)];
            }

            $entry = $this->createJournalEntry([
                'date'        => $date,
                'reference'   => $reference ?? 'FA-DISPOSAL-' . now()->format('Y-m') . '-' . $asset->id,
                'type'        => 'general',
                'sbu_code'    => $asset->sbu_code,
                'description' => sprintf(
                    'Disposal of %s (%s) — book value %s, proceeds %s, %s %s',
                    $asset->name,
                    $asset->asset_code,
                    number_format($bookValue, 2),
                    number_format($proceeds, 2),
                    $gainOrLoss >= 0 ? 'gain' : 'loss',
                    number_format(abs($gainOrLoss), 2),
                ),
                'lines' => $lines,
            ]);

            $asset->update([
                'disposed_at'               => $date,
                'disposal_proceeds'         => $proceeds,
                'disposal_journal_entry_id' => $entry->id,
                'is_active'                 => false,
                'disposed_by'               => auth()->id(),
            ]);

            return $entry;
        });
    }

    /**
     * Fixed asset register: all assets with live GL-computed depreciation figures,
     * optionally filtered by SBU.
     */
    public function getFixedAssetRegister(?string $sbuCode = null): array
    {
        $query = FixedAsset::with(['assetAccount', 'accumulatedDepreciationAccount'])
            ->orderBy('asset_class')
            ->orderBy('name');

        if ($sbuCode !== null) {
            $query->where('sbu_code', strtoupper(trim($sbuCode)));
        }

        return $query->get()
            ->map(fn (FixedAsset $a): array => [
                'id'                               => $a->id,
                'asset_code'                       => $a->asset_code,
                'name'                             => $a->name,
                'asset_class'                      => $a->asset_class,
                'sbu_code'                         => $a->sbu_code,
                'is_active'                        => $a->is_active,
                'acquisition_cost'                 => (float) $a->acquisition_cost,
                'salvage_value'                    => (float) $a->salvage_value,
                'useful_life_months'               => $a->useful_life_months,
                'depreciation_method'              => $a->depreciation_method,
                'acquired_at'                      => $a->acquired_at?->toDateString(),
                'disposed_at'                      => $a->disposed_at?->toDateString(),
                'accumulated_depreciation'         => $a->accumulatedDepreciation(),
                'monthly_depreciation'             => $a->monthlyDepreciationAmount(),
                'net_book_value'                   => $a->netBookValue(),
                'is_fully_depreciated'             => $a->isFullyDepreciated(),
                'asset_account'                    => $a->assetAccount?->code . ' ' . $a->assetAccount?->name,
                'accumulated_depreciation_account' => $a->accumulatedDepreciationAccount?->code . ' ' . $a->accumulatedDepreciationAccount?->name,
            ])
            ->all();
    }
}
