<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Controllers\Api;

use Centrex\Accounting\Models\QuickBooksToken;
use Centrex\Accounting\QuickBooks\{QuickBooksClient, QuickBooksReportFormatter, QuickBooksSyncService};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\{Gate, Log, Session};

/**
 * QuickBooks Online integration endpoints.
 *
 * OAuth2 flow  (web middleware):
 *   GET  /accounting/qbo/connect        → redirect to Intuit authorization URL
 *   GET  /accounting/qbo/callback       → exchange code, store token, redirect back
 *   POST /accounting/qbo/disconnect     → revoke tokens + delete record
 *
 * API endpoints (api middleware):
 *   GET  /api/accounting/qbo/status     → connection status for current realm
 *   POST /api/accounting/qbo/sync       → trigger push sync (accounts, customers, etc.)
 *   GET  /api/accounting/qbo/reports/{report} → pull named report from QBO
 *   POST /api/accounting/qbo/webhook    → receive QBO change-data-capture webhooks
 */
class QuickBooksController extends Controller
{
    public function __construct(
        private readonly QuickBooksClient       $client,
        private readonly QuickBooksSyncService  $sync,
        private readonly QuickBooksReportFormatter $formatter,
    ) {}

    // -----------------------------------------------------------------------
    // OAuth2 — web routes
    // -----------------------------------------------------------------------

    /** Redirect user to Intuit's authorization page. */
    public function connect(Request $request): RedirectResponse
    {
        Gate::authorize('accounting.qbo.connect');

        $state = \Illuminate\Support\Str::random(32);
        Session::put('qbo_oauth_state', $state);

        return redirect()->away($this->client->authorizationUrl($state));
    }

    /** Handle Intuit's callback after the user approves access. */
    public function callback(Request $request): RedirectResponse
    {
        $request->validate([
            'code'     => ['required', 'string'],
            'realmId'  => ['required', 'string'],
            'state'    => ['nullable', 'string'],
        ]);

        // CSRF state check
        $expected = Session::pull('qbo_oauth_state');
        if ($expected && $request->state !== $expected) {
            return redirect(config('accounting.web_prefix', 'accounting') . '/dashboard')
                ->with('error', 'QuickBooks authorization state mismatch. Please try again.');
        }

        try {
            $this->client->exchangeCode($request->code, $request->realmId);

            return redirect(config('accounting.web_prefix', 'accounting') . '/dashboard')
                ->with('success', 'QuickBooks Online connected successfully.');
        } catch (\Throwable $e) {
            Log::error('QBO OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect(config('accounting.web_prefix', 'accounting') . '/dashboard')
                ->with('error', 'Failed to connect QuickBooks: ' . $e->getMessage());
        }
    }

    /** Revoke tokens and remove the connection. */
    public function disconnect(Request $request): JsonResponse
    {
        Gate::authorize('accounting.qbo.connect');

        $realmId = $request->string('realm_id')->toString()
            ?: (string) config('accounting.quickbooks.default_realm_id', '');

        $token = QuickBooksToken::where('realm_id', $realmId)->first();

        if (!$token) {
            return response()->json(['message' => 'No active QuickBooks connection found.'], 404);
        }

        try {
            $this->client->revokeToken($token);

            return response()->json(['message' => 'QuickBooks disconnected successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------------
    // Status — API route
    // -----------------------------------------------------------------------

    /** Return connection status for the given (or configured) realm. */
    public function status(Request $request): JsonResponse
    {
        $realmId = $request->string('realm_id')->toString()
            ?: (string) config('accounting.quickbooks.default_realm_id', '');

        $token = $realmId ? QuickBooksToken::where('realm_id', $realmId)->first() : null;

        if (!$token) {
            return response()->json(['connected' => false, 'realm_id' => $realmId]);
        }

        return response()->json([
            'connected'                  => true,
            'realm_id'                   => $token->realm_id,
            'access_token_expires_at'    => $token->expires_at?->toIso8601String(),
            'access_token_expired'       => $token->isExpired(),
            'refresh_token_expires_at'   => $token->refresh_token_expires_at?->toIso8601String(),
            'refresh_token_expired'      => $token->isRefreshExpired(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Sync — API route
    // -----------------------------------------------------------------------

    /**
     * Trigger a push sync from our system to QBO.
     *
     * Body (all optional):
     *   realm_id  string   — QBO company realm ID (falls back to config default)
     *   entities  string[] — which to sync: accounts, customers, vendors, invoices, bills, journal_entries
     *   since     date     — only sync records updated on/after this date
     */
    public function sync(Request $request): JsonResponse
    {
        Gate::authorize('accounting.qbo.sync');

        $request->validate([
            'realm_id' => ['nullable', 'string'],
            'entities' => ['nullable', 'array'],
            'entities.*' => ['string', 'in:accounts,customers,vendors,invoices,bills,journal_entries'],
            'since'    => ['nullable', 'date'],
        ]);

        $realmId  = $request->string('realm_id')->toString()
            ?: (string) config('accounting.quickbooks.default_realm_id', '');
        $entities = $request->input('entities', ['accounts', 'customers', 'vendors', 'invoices', 'bills', 'journal_entries']);
        $since    = $request->input('since');

        if (!$realmId) {
            return response()->json(['message' => 'realm_id is required (or set accounting.quickbooks.default_realm_id).'], 422);
        }

        $results = [];

        try {
            if (in_array('accounts', $entities, true)) {
                $results['accounts'] = $this->sync->syncAccounts($realmId);
            }
            if (in_array('customers', $entities, true)) {
                $results['customers'] = $this->sync->syncCustomers($realmId);
            }
            if (in_array('vendors', $entities, true)) {
                $results['vendors'] = $this->sync->syncVendors($realmId);
            }
            if (in_array('invoices', $entities, true)) {
                $results['invoices'] = $this->sync->syncInvoices($realmId, $since);
            }
            if (in_array('bills', $entities, true)) {
                $results['bills'] = $this->sync->syncBills($realmId, $since);
            }
            if (in_array('journal_entries', $entities, true)) {
                $results['journal_entries'] = $this->sync->syncJournalEntries($realmId, $since);
            }

            return response()->json(['data' => $results]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------------
    // Pull reports — API route
    // -----------------------------------------------------------------------

    /**
     * Fetch a named report directly from QBO and return it.
     *
     * Route: GET /api/accounting/qbo/reports/{report}
     *
     * Recognized report names (case-insensitive):
     *   profit-and-loss | balance-sheet | cash-flow | trial-balance |
     *   aged-receivables | aged-payables | general-ledger
     *
     * Additional QBO query parameters can be appended: start_date, end_date, date_macro, etc.
     */
    public function pullReport(Request $request, string $report): JsonResponse
    {
        Gate::authorize('accounting.reports.view');

        $realmId = $request->string('realm_id')->toString()
            ?: (string) config('accounting.quickbooks.default_realm_id', '');

        if (!$realmId) {
            return response()->json(['message' => 'realm_id is required.'], 422);
        }

        // Map slug → QBO report name
        $qboName = match (strtolower($report)) {
            'profit-and-loss', 'pl', 'income-statement' => 'ProfitAndLoss',
            'balance-sheet'                              => 'BalanceSheet',
            'cash-flow'                                  => 'CashFlow',
            'trial-balance'                              => 'TrialBalance',
            'aged-receivables', 'ar-aging'               => 'AgedReceivables',
            'aged-payables', 'ap-aging'                  => 'AgedPayables',
            'general-ledger'                             => 'GeneralLedger',
            default                                      => $report,
        };

        // Forward allowed QBO query params
        $params = $request->only(['start_date', 'end_date', 'date_macro', 'accounting_method', 'customer', 'vendor', 'account']);

        try {
            $data = $this->sync->pullQboReport($realmId, $qboName, $params);

            return response()->json(['data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------------
    // Webhook — API route
    // -----------------------------------------------------------------------

    /**
     * Receive QBO change-data-capture webhook events.
     *
     * QBO signs each request with HMAC-SHA256 using the webhook verifier token
     * (config accounting.quickbooks.webhook_verifier_token).
     *
     * Route: POST /api/accounting/qbo/webhook  (no auth middleware — verified by signature)
     */
    public function webhook(Request $request): JsonResponse
    {
        $verifierToken = (string) config('accounting.quickbooks.webhook_verifier_token', '');

        if ($verifierToken !== '') {
            $signature = $request->header('intuit-signature', '');
            $computed  = base64_encode(hash_hmac('sha256', $request->getContent(), $verifierToken, true));

            if (!hash_equals($computed, (string) $signature)) {
                return response()->json(['message' => 'Invalid webhook signature.'], 401);
            }
        }

        try {
            $processed = $this->sync->handleWebhook($request->all());
            Log::info('QBO webhook processed', ['events' => count($processed)]);

            return response()->json(['processed' => count($processed)]);
        } catch (\Throwable $e) {
            Log::error('QBO webhook error', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
