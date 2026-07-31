<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Models\Account;
use Illuminate\Support\Collection;

/**
 * Accounting::getAccountsByType()/getBalanceSheet() embed the full Account Eloquent
 * model in each accounts[] row ('account' => $account) — fine for callers that use the
 * result immediately, but fatal for anything that caches it: Laravel's cache stores
 * (file/redis/database) serialize the value with PHP's native serialize(), and if the
 * Account class isn't autoloaded yet by the time a later request unserializes it, PHP
 * silently swaps in __PHP_Incomplete_Class instead of erroring — so a later ->code
 * access throws "tried to access a property on an incomplete object" deep inside an
 * unrelated closure, with no obvious link back to the cache.
 *
 * Any component that caches a getBalanceSheet()-shaped array (AccountingCurrentAssetsCard,
 * AccountingBalanceSnapshotCard) must run the result through this first so only plain
 * arrays — never model instances — cross the cache serialization boundary. Mirrors
 * ReportController::formatAccountGroups(), which does the same for JSON responses.
 */
trait SerializesBalanceSheetAccounts
{
    /**
     * @param  array<string, mixed>  $balanceSheet
     * @return array<string, mixed>
     */
    protected function serializeBalanceSheetAccounts(array $balanceSheet): array
    {
        foreach (['assets', 'liabilities', 'equity'] as $section) {
            if (!isset($balanceSheet[$section]['accounts'])) {
                continue;
            }

            $balanceSheet[$section]['accounts'] = Collection::make($balanceSheet[$section]['accounts'])
                ->map(function (array $row): array {
                    $account = $row['account'];

                    $row['account'] = $account instanceof Account ? [
                        'id'      => $account->id,
                        'code'    => $account->code,
                        'name'    => $account->name,
                        'type'    => $account->type,
                        'subtype' => $account->subtype,
                    ] : $account;

                    return $row;
                })
                ->all();
        }

        return $balanceSheet;
    }
}
