<?php

declare(strict_types = 1);

namespace Centrex\Accounting\QuickBooks;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, Bill, Customer, Invoice, JournalEntry, QuickBooksToken, Vendor};
use Illuminate\Support\{Carbon, Collection};

/**
 * High-level two-way sync service between laravel-accounting and QuickBooks Online.
 *
 * Push (our system → QBO):
 *   syncAccounts()     — Chart of Accounts
 *   syncCustomers()    — Customer list
 *   syncVendors()      — Vendor list
 *   syncInvoices()     — Posted invoices (since a given date)
 *   syncBills()        — Posted bills
 *   syncJournalEntries() — Posted journal entries
 *
 * Pull (QBO → our system):
 *   pullQboReport()    — Fetch any named QBO report
 *   pullQboAccounts()  — Import QBO account list (returns raw QBO data, no DB write)
 *
 * Webhook:
 *   handleWebhook()    — Process an incoming QBO webhook payload
 */
final class QuickBooksSyncService
{
    public function __construct(
        private readonly QuickBooksClient        $client,
        private readonly QuickBooksAccountTypeMapper $mapper,
        private readonly QuickBooksReportFormatter   $formatter,
        private readonly Accounting              $accounting,
    ) {}

    // -----------------------------------------------------------------------
    // Push: Our system → QBO
    // -----------------------------------------------------------------------

    /**
     * Push Chart of Accounts to QBO.
     * Creates missing accounts; updates existing ones (matched by AcctNum = our code).
     *
     * @return array{created: int, updated: int, skipped: int, errors: array}
     */
    public function syncAccounts(string $realmId): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        // Fetch existing QBO accounts indexed by AcctNum
        $qboAccounts = $this->fetchQboAccountsByCode($realmId);

        Account::where('is_active', true)->orderBy('code')->chunk(50, function (Collection $accounts) use ($realmId, $qboAccounts, &$stats): void {
            foreach ($accounts as $account) {
                try {
                    $payload = $this->buildQboAccountPayload($account);

                    if (isset($qboAccounts[$account->code])) {
                        // Update
                        $existing = $qboAccounts[$account->code];
                        $payload['Id']       = $existing['Id'];
                        $payload['SyncToken'] = $existing['SyncToken'];
                        $this->client->post($realmId, 'account', $payload);
                        $stats['updated']++;
                    } else {
                        // Create
                        $this->client->post($realmId, 'account', $payload);
                        $stats['created']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Account {$account->code}: " . $e->getMessage();
                    $stats['skipped']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Push Customers to QBO.
     * Matched by DisplayName (our customer name).
     *
     * @return array{created: int, updated: int, skipped: int, errors: array}
     */
    public function syncCustomers(string $realmId): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $qboCustomers = $this->fetchQboEntityByName($realmId, 'Customer', 'DisplayName');

        Customer::chunk(50, function (Collection $customers) use ($realmId, $qboCustomers, &$stats): void {
            foreach ($customers as $customer) {
                try {
                    $payload = [
                        'DisplayName'  => $customer->name,
                        'PrimaryEmailAddr' => $customer->email ? ['Address' => $customer->email] : null,
                        'PrimaryPhone'     => $customer->phone  ? ['FreeFormNumber' => $customer->phone] : null,
                    ];
                    $payload = array_filter($payload);

                    if (isset($qboCustomers[$customer->name])) {
                        $existing = $qboCustomers[$customer->name];
                        $payload['Id']        = $existing['Id'];
                        $payload['SyncToken'] = $existing['SyncToken'];
                        $this->client->post($realmId, 'customer', $payload);
                        $stats['updated']++;
                    } else {
                        $this->client->post($realmId, 'customer', $payload);
                        $stats['created']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Customer {$customer->name}: " . $e->getMessage();
                    $stats['skipped']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Push Vendors to QBO.
     *
     * @return array{created: int, updated: int, skipped: int, errors: array}
     */
    public function syncVendors(string $realmId): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $qboVendors = $this->fetchQboEntityByName($realmId, 'Vendor', 'DisplayName');

        Vendor::chunk(50, function (Collection $vendors) use ($realmId, $qboVendors, &$stats): void {
            foreach ($vendors as $vendor) {
                try {
                    $payload = array_filter([
                        'DisplayName'      => $vendor->name,
                        'PrimaryEmailAddr' => $vendor->email ? ['Address' => $vendor->email] : null,
                        'PrimaryPhone'     => $vendor->phone  ? ['FreeFormNumber' => $vendor->phone] : null,
                    ]);

                    if (isset($qboVendors[$vendor->name])) {
                        $existing = $qboVendors[$vendor->name];
                        $payload['Id']        = $existing['Id'];
                        $payload['SyncToken'] = $existing['SyncToken'];
                        $this->client->post($realmId, 'vendor', $payload);
                        $stats['updated']++;
                    } else {
                        $this->client->post($realmId, 'vendor', $payload);
                        $stats['created']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Vendor {$vendor->name}: " . $e->getMessage();
                    $stats['skipped']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Push posted invoices to QBO as QBO Invoices.
     *
     * @param  string|null  $since  ISO date; only invoices created/updated on or after this date
     * @return array{created: int, updated: int, skipped: int, errors: array}
     */
    public function syncInvoices(string $realmId, ?string $since = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        // Fetch QBO Customer name → Id map
        $qboCustomerIds = $this->fetchQboEntityIdByName($realmId, 'Customer', 'DisplayName');

        $query = Invoice::with(['customer', 'items'])
            ->whereIn('status', ['sent', 'issued', 'partially_settled', 'settled'])
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since));

        $query->chunk(25, function (Collection $invoices) use ($realmId, $qboCustomerIds, &$stats): void {
            foreach ($invoices as $invoice) {
                try {
                    $customerQboId = $qboCustomerIds[$invoice->customer?->name ?? ''] ?? null;

                    if (!$customerQboId) {
                        $stats['errors'][] = "Invoice {$invoice->invoice_number}: customer not found in QBO";
                        $stats['skipped']++;
                        continue;
                    }

                    $lines = [];
                    foreach ($invoice->items as $item) {
                        $lines[] = [
                            'Amount'     => (float) $item->total,
                            'DetailType' => 'SalesItemLineDetail',
                            'Description' => $item->description,
                            'SalesItemLineDetail' => [
                                'Qty'        => (float) ($item->quantity ?? 1),
                                'UnitPrice'  => (float) ($item->unit_price ?? $item->total),
                            ],
                        ];
                    }

                    $payload = [
                        'DocNumber'    => $invoice->invoice_number,
                        'TxnDate'      => $invoice->invoice_date instanceof \DateTimeInterface
                            ? $invoice->invoice_date->toDateString()
                            : (string) $invoice->invoice_date,
                        'DueDate'      => $invoice->due_date instanceof \DateTimeInterface
                            ? $invoice->due_date->toDateString()
                            : (string) ($invoice->due_date ?? ''),
                        'CustomerRef'  => ['value' => $customerQboId],
                        'Line'         => $lines,
                    ];

                    $this->client->post($realmId, 'invoice', $payload);
                    $stats['created']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Invoice {$invoice->invoice_number}: " . $e->getMessage();
                    $stats['skipped']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Push posted bills to QBO as QBO Bills.
     *
     * @return array{created: int, updated: int, skipped: int, errors: array}
     */
    public function syncBills(string $realmId, ?string $since = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $qboVendorIds = $this->fetchQboEntityIdByName($realmId, 'Vendor', 'DisplayName');

        $query = Bill::with(['vendor', 'items'])
            ->whereIn('status', ['issued', 'partially_settled', 'settled'])
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since));

        $query->chunk(25, function (Collection $bills) use ($realmId, $qboVendorIds, &$stats): void {
            foreach ($bills as $bill) {
                try {
                    $vendorQboId = $qboVendorIds[$bill->vendor?->name ?? ''] ?? null;

                    if (!$vendorQboId) {
                        $stats['errors'][] = "Bill {$bill->bill_number}: vendor not found in QBO";
                        $stats['skipped']++;
                        continue;
                    }

                    $lines = [];
                    foreach ($bill->items as $item) {
                        $lines[] = [
                            'Amount'      => (float) $item->total,
                            'DetailType'  => 'AccountBasedExpenseLineDetail',
                            'Description' => $item->description,
                            'AccountBasedExpenseLineDetail' => [
                                'AccountRef' => ['value' => '1'],  // AP account; resolved by QBO default
                            ],
                        ];
                    }

                    $payload = [
                        'DocNumber'  => $bill->bill_number,
                        'TxnDate'    => $bill->bill_date instanceof \DateTimeInterface
                            ? $bill->bill_date->toDateString()
                            : (string) $bill->bill_date,
                        'DueDate'    => $bill->due_date instanceof \DateTimeInterface
                            ? $bill->due_date->toDateString()
                            : (string) ($bill->due_date ?? ''),
                        'VendorRef'  => ['value' => $vendorQboId],
                        'Line'       => $lines,
                    ];

                    $this->client->post($realmId, 'bill', $payload);
                    $stats['created']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Bill {$bill->bill_number}: " . $e->getMessage();
                    $stats['skipped']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Push posted journal entries to QBO as QBO JournalEntries.
     *
     * QBO requires account references by QBO Id. We match accounts by AcctNum (our code).
     *
     * @return array{created: int, updated: int, skipped: int, errors: array}
     */
    public function syncJournalEntries(string $realmId, ?string $since = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        // Build code → QBO Id map
        $qboAccountMap = $this->fetchQboAccountsByCode($realmId);

        $query = JournalEntry::with('lines.account')
            ->where('status', 'posted')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since));

        $query->chunk(25, function (Collection $entries) use ($realmId, $qboAccountMap, &$stats): void {
            foreach ($entries as $entry) {
                try {
                    $lines = [];
                    foreach ($entry->lines as $line) {
                        $code    = $line->account?->code;
                        $qboAcct = $code ? ($qboAccountMap[$code] ?? null) : null;

                        if (!$qboAcct) {
                            throw new \RuntimeException("Account {$code} not found in QBO");
                        }

                        $lines[] = [
                            'Amount'                  => (float) $line->amount,
                            'DetailType'              => 'JournalEntryLineDetail',
                            'Description'             => $line->description ?? $entry->description,
                            'JournalEntryLineDetail'  => [
                                'PostingType' => ucfirst($line->type),   // Debit | Credit
                                'AccountRef'  => ['value' => $qboAcct['Id'], 'name' => $qboAcct['Name']],
                            ],
                        ];
                    }

                    $this->client->post($realmId, 'journalentry', [
                        'DocNumber'   => $entry->entry_number,
                        'TxnDate'     => $entry->date instanceof \DateTimeInterface
                            ? $entry->date->toDateString()
                            : (string) $entry->date,
                        'PrivateNote' => $entry->description ?? '',
                        'Line'        => $lines,
                    ]);

                    $stats['created']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "JE {$entry->entry_number}: " . $e->getMessage();
                    $stats['skipped']++;
                }
            }
        });

        return $stats;
    }

    // -----------------------------------------------------------------------
    // Pull: QBO → Our system
    // -----------------------------------------------------------------------

    /**
     * Fetch a QBO report and return it formatted in our aligned structure.
     *
     * $reportName: ProfitAndLoss | BalanceSheet | CashFlow | TrialBalance |
     *              AgedReceivables | AgedPayables | GeneralLedger
     */
    public function pullQboReport(string $realmId, string $reportName, array $params = []): array
    {
        return $this->client->report($realmId, $reportName, $params);
    }

    /**
     * Fetch QBO accounts list and return raw QBO data.
     * Use this to reconcile your Chart of Accounts with QBO.
     */
    public function pullQboAccounts(string $realmId): array
    {
        $result = $this->client->query($realmId, 'SELECT * FROM Account WHERE Active = true MAXRESULTS 1000');

        return $result['QueryResponse']['Account'] ?? [];
    }

    // -----------------------------------------------------------------------
    // Webhook handler
    // -----------------------------------------------------------------------

    /**
     * Process an incoming QBO webhook payload.
     *
     * QBO sends webhook events when data changes in the connected company.
     * This method logs/dispatches events; extend to trigger targeted re-syncs.
     *
     * Returns a list of processed event summaries.
     */
    public function handleWebhook(array $payload): array
    {
        $processed = [];

        foreach ($payload['eventNotifications'] ?? [] as $notification) {
            $realmId = $notification['realmId'] ?? '';

            foreach ($notification['dataChangeEvent']['entities'] ?? [] as $entity) {
                $processed[] = [
                    'realm_id'  => $realmId,
                    'entity'    => $entity['name'] ?? '',
                    'id'        => $entity['id'] ?? '',
                    'operation' => $entity['operation'] ?? '',
                    'updated_at' => $entity['lastUpdated'] ?? '',
                ];
            }
        }

        return $processed;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Fetch QBO accounts indexed by AcctNum (our account code). */
    private function fetchQboAccountsByCode(string $realmId): array
    {
        $result   = $this->client->query($realmId, 'SELECT * FROM Account MAXRESULTS 1000');
        $accounts = $result['QueryResponse']['Account'] ?? [];

        return collect($accounts)->keyBy('AcctNum')->all();
    }

    /** Fetch QBO entities (Customer, Vendor, etc.) indexed by a name field. */
    private function fetchQboEntityByName(string $realmId, string $entity, string $nameField): array
    {
        $result   = $this->client->query($realmId, "SELECT * FROM {$entity} MAXRESULTS 1000");
        $items    = $result['QueryResponse'][$entity] ?? [];

        return collect($items)->keyBy($nameField)->all();
    }

    /** Fetch QBO entity name → QBO Id map. */
    private function fetchQboEntityIdByName(string $realmId, string $entity, string $nameField): array
    {
        return collect($this->fetchQboEntityByName($realmId, $entity, $nameField))
            ->map(fn ($item) => $item['Id'])
            ->all();
    }

    /** Build a QBO Account create/update payload from one of our Account models. */
    private function buildQboAccountPayload(Account $account): array
    {
        $qbo = $this->mapper->map($account);

        return [
            'Name'           => $account->name,
            'AcctNum'        => $account->code,
            'AccountType'    => $qbo['AccountType'],
            'AccountSubType' => $qbo['AccountSubType'],
            'Active'         => $account->is_active,
            'Description'    => $account->description ?? '',
        ];
    }
}
