<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Commands;

use Centrex\Accounting\QuickBooks\QuickBooksSyncService;
use Illuminate\Console\Command;

/**
 * Artisan command to trigger a QuickBooks Online sync from the CLI.
 *
 * Usage:
 *   php artisan accounting:qbo-sync                                   # sync all entities
 *   php artisan accounting:qbo-sync --entity=accounts                 # one entity
 *   php artisan accounting:qbo-sync --entity=invoices --since=2025-01-01
 *   php artisan accounting:qbo-sync --pull=ProfitAndLoss --start=2025-01-01 --end=2025-12-31
 */
final class QuickBooksSyncCommand extends Command
{
    protected $signature = 'accounting:qbo-sync
        {--realm=       : QBO company realm ID (falls back to accounting.quickbooks.default_realm_id)}
        {--entity=*     : Entities to push: accounts,customers,vendors,invoices,bills,journal_entries}
        {--since=       : Only push records updated on/after this date (YYYY-MM-DD)}
        {--pull=        : Pull a named QBO report instead of pushing (e.g. ProfitAndLoss)}
        {--start=       : Report start date for --pull}
        {--end=         : Report end date for --pull}';

    protected $description = 'Sync data with QuickBooks Online (push our data to QBO, or pull a QBO report).';

    public function handle(QuickBooksSyncService $sync): int
    {
        $realmId = $this->option('realm')
            ?: (string) config('accounting.quickbooks.default_realm_id', '');

        if (!$realmId) {
            $this->error('Realm ID is required. Use --realm=<id> or set accounting.quickbooks.default_realm_id in config.');

            return self::FAILURE;
        }

        // Pull mode
        if ($pullReport = $this->option('pull')) {
            return $this->runPull($sync, $realmId, (string) $pullReport);
        }

        // Push mode
        return $this->runPush($sync, $realmId);
    }

    private function runPush(QuickBooksSyncService $sync, string $realmId): int
    {
        $entities = (array) $this->option('entity');
        $since    = $this->option('since') ?: null;

        if (empty($entities)) {
            $entities = ['accounts', 'customers', 'vendors', 'invoices', 'bills', 'journal_entries'];
        }

        $this->info("Syncing to QBO realm: {$realmId}");
        $totalErrors = 0;

        $map = [
            'accounts'        => fn () => $sync->syncAccounts($realmId),
            'customers'       => fn () => $sync->syncCustomers($realmId),
            'vendors'         => fn () => $sync->syncVendors($realmId),
            'invoices'        => fn () => $sync->syncInvoices($realmId, $since),
            'bills'           => fn () => $sync->syncBills($realmId, $since),
            'journal_entries' => fn () => $sync->syncJournalEntries($realmId, $since),
        ];

        foreach ($entities as $entity) {
            if (!isset($map[$entity])) {
                $this->warn("Unknown entity: {$entity}. Skipping.");
                continue;
            }

            $this->line("<fg=cyan>Syncing {$entity}…</>");

            try {
                $result = ($map[$entity])();

                $this->table(
                    ['Created', 'Updated', 'Skipped', 'Errors'],
                    [[
                        $result['created'] ?? 0,
                        $result['updated'] ?? 0,
                        $result['skipped'] ?? 0,
                        count($result['errors'] ?? []),
                    ]],
                );

                foreach ($result['errors'] ?? [] as $err) {
                    $this->warn("  ↳ {$err}");
                }

                $totalErrors += count($result['errors'] ?? []);
            } catch (\Throwable $e) {
                $this->error("  ✗ {$entity}: " . $e->getMessage());
                $totalErrors++;
            }

            $this->newLine();
        }

        if ($totalErrors > 0) {
            $this->warn("Sync completed with {$totalErrors} error(s).");

            return self::FAILURE;
        }

        $this->info('Sync completed successfully.');

        return self::SUCCESS;
    }

    private function runPull(QuickBooksSyncService $sync, string $realmId, string $reportName): int
    {
        $params = array_filter([
            'start_date' => $this->option('start'),
            'end_date'   => $this->option('end'),
        ]);

        $this->line("<fg=cyan>Pulling QBO report: {$reportName}</>");

        try {
            $data = $sync->pullQboReport($realmId, $reportName, $params);
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Pull failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
