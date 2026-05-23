<?php

declare(strict_types = 1);

namespace Centrex\Accounting\QuickBooks;

/**
 * Reformats laravel-accounting report arrays into the QuickBooks Online (QBO)
 * report structure and terminology.
 *
 * QBO P&L sections:
 *   Income → COGS → Gross Profit → Expenses → Net Operating Income →
 *   Other Income → Other Expenses → Net Other Income → Net Income
 *
 * QBO Balance Sheet sections:
 *   Current Assets (Bank, AR, Other Current) → Fixed Assets → Other Assets → Total Assets
 *   Current Liabilities (AP, CC, Other Current) → Long-Term → Total Liabilities
 *   Equity → Total Liabilities & Equity
 */
final class QuickBooksReportFormatter
{
    public function __construct(
        private readonly QuickBooksAccountTypeMapper $mapper,
    ) {}

    // -----------------------------------------------------------------------
    // Profit & Loss
    // -----------------------------------------------------------------------

    /**
     * Reformat an income-statement array (from Accounting::getIncomeStatement)
     * into the QBO Profit & Loss structure.
     */
    public function profitAndLoss(array $data): array
    {
        $incomeAccounts      = [];
        $cogsAccounts        = [];
        $expenseAccounts     = [];
        $otherIncomeAccounts = [];
        $otherExpenseAccounts = [];

        // Revenue accounts → Income or Other Income
        foreach ($data['revenue']['accounts'] ?? [] as $item) {
            $section = $this->mapper->section($item['account']);
            if ($section === 'other_income') {
                $otherIncomeAccounts[] = $this->formatLine($item);
            } else {
                $incomeAccounts[] = $this->formatLine($item);
            }
        }

        // Expense accounts → COGS, Expense, or Other Expense
        foreach ($data['expenses']['accounts'] ?? [] as $item) {
            $section = $this->mapper->section($item['account']);
            match ($section) {
                'cogs'          => $cogsAccounts[]         = $this->formatLine($item),
                'other_expense' => $otherExpenseAccounts[] = $this->formatLine($item),
                default         => $expenseAccounts[]      = $this->formatLine($item),
            };
        }

        $totalIncome      = array_sum(array_column($incomeAccounts, 'amount'));
        $totalCogs        = array_sum(array_column($cogsAccounts, 'amount'));
        $grossProfit      = $totalIncome - $totalCogs;
        $totalExpenses    = array_sum(array_column($expenseAccounts, 'amount'));
        $netOpIncome      = $grossProfit - $totalExpenses;
        $totalOtherIncome = array_sum(array_column($otherIncomeAccounts, 'amount'));
        $totalOtherExp    = array_sum(array_column($otherExpenseAccounts, 'amount'));
        $netOtherIncome   = $totalOtherIncome - $totalOtherExp;
        $netIncome        = $netOpIncome + $netOtherIncome;

        return [
            'report_name'      => 'ProfitAndLoss',
            'period'           => $data['period'] ?? [],
            'sbu_code'         => $data['sbu_code'] ?? null,

            'income' => [
                'label'    => 'Income',
                'accounts' => $incomeAccounts,
                'total'    => $totalIncome,
            ],
            'cost_of_goods_sold' => [
                'label'    => 'Cost of Goods Sold',
                'accounts' => $cogsAccounts,
                'total'    => $totalCogs,
            ],
            'gross_profit' => $grossProfit,

            'expenses' => [
                'label'    => 'Expenses',
                'accounts' => $expenseAccounts,
                'total'    => $totalExpenses,
            ],
            'net_operating_income' => $netOpIncome,

            'other_income' => [
                'label'    => 'Other Income',
                'accounts' => $otherIncomeAccounts,
                'total'    => $totalOtherIncome,
            ],
            'other_expenses' => [
                'label'    => 'Other Expenses',
                'accounts' => $otherExpenseAccounts,
                'total'    => $totalOtherExp,
            ],
            'net_other_income' => $netOtherIncome,

            'net_income' => $netIncome,
        ];
    }

    // -----------------------------------------------------------------------
    // Balance Sheet
    // -----------------------------------------------------------------------

    /**
     * Reformat a balance-sheet array (from Accounting::getBalanceSheet)
     * into the QBO Balance Sheet structure.
     */
    public function balanceSheet(array $data): array
    {
        $bankAccounts            = [];
        $arAccounts              = [];
        $otherCurrentAssets      = [];
        $fixedAssets             = [];
        $otherAssets             = [];

        $apAccounts              = [];
        $creditCards             = [];
        $otherCurrentLiabilities = [];
        $longTermLiabilities     = [];

        $equityAccounts          = [];

        // Assets
        foreach ($data['assets']['accounts'] ?? [] as $item) {
            $section = $this->mapper->section($item['account']);
            match ($section) {
                'bank'               => $bankAccounts[]       = $this->formatLine($item),
                'accounts_receivable' => $arAccounts[]         = $this->formatLine($item),
                'fixed_asset'        => $fixedAssets[]         = $this->formatLine($item),
                'other_asset'        => $otherAssets[]         = $this->formatLine($item),
                default              => $otherCurrentAssets[]  = $this->formatLine($item),
            };
        }

        // Liabilities
        foreach ($data['liabilities']['accounts'] ?? [] as $item) {
            $section = $this->mapper->section($item['account']);
            match ($section) {
                'accounts_payable'        => $apAccounts[]              = $this->formatLine($item),
                'credit_card'             => $creditCards[]              = $this->formatLine($item),
                'long_term_liability'     => $longTermLiabilities[]      = $this->formatLine($item),
                default                   => $otherCurrentLiabilities[]  = $this->formatLine($item),
            };
        }

        // Equity
        foreach ($data['equity']['accounts'] ?? [] as $item) {
            $equityAccounts[] = $this->formatLine($item);
        }

        // Totals
        $totalBank               = array_sum(array_column($bankAccounts, 'amount'));
        $totalAr                 = array_sum(array_column($arAccounts, 'amount'));
        $totalOtherCurrent       = array_sum(array_column($otherCurrentAssets, 'amount'));
        $totalCurrentAssets      = $totalBank + $totalAr + $totalOtherCurrent;
        $totalFixed              = array_sum(array_column($fixedAssets, 'amount'));
        $totalOtherAssets        = array_sum(array_column($otherAssets, 'amount'));
        $totalAssets             = $totalCurrentAssets + $totalFixed + $totalOtherAssets;

        $totalAp                 = array_sum(array_column($apAccounts, 'amount'));
        $totalCc                 = array_sum(array_column($creditCards, 'amount'));
        $totalOtherCurrLiab      = array_sum(array_column($otherCurrentLiabilities, 'amount'));
        $totalCurrentLiabilities = $totalAp + $totalCc + $totalOtherCurrLiab;
        $totalLongTerm           = array_sum(array_column($longTermLiabilities, 'amount'));
        $totalLiabilities        = $totalCurrentLiabilities + $totalLongTerm;

        $totalEquityAccounts     = array_sum(array_column($equityAccounts, 'amount'));
        $netIncome               = (float) ($data['equity']['net_income'] ?? 0);
        $totalEquity             = $totalEquityAccounts + $netIncome;

        return [
            'report_name' => 'BalanceSheet',
            'date'        => $data['date'] ?? null,
            'sbu_code'    => $data['sbu_code'] ?? null,
            'is_balanced' => $data['is_balanced'] ?? false,

            'assets' => [
                'label'          => 'Assets',
                'current_assets' => [
                    'label'               => 'Current Assets',
                    'bank_accounts'       => ['label' => 'Bank Accounts',       'accounts' => $bankAccounts,       'total' => $totalBank],
                    'accounts_receivable' => ['label' => 'Accounts Receivable (A/R)', 'accounts' => $arAccounts,   'total' => $totalAr],
                    'other_current_assets' => ['label' => 'Other Current Assets', 'accounts' => $otherCurrentAssets, 'total' => $totalOtherCurrent],
                    'total'               => $totalCurrentAssets,
                ],
                'fixed_assets'   => ['label' => 'Fixed Assets',  'accounts' => $fixedAssets,  'total' => $totalFixed],
                'other_assets'   => ['label' => 'Other Assets',  'accounts' => $otherAssets,  'total' => $totalOtherAssets],
                'total'          => $totalAssets,
            ],

            'liabilities_and_equity' => [
                'label' => 'Liabilities and Equity',

                'liabilities' => [
                    'label'               => 'Liabilities',
                    'current_liabilities' => [
                        'label'                    => 'Current Liabilities',
                        'accounts_payable'          => ['label' => 'Accounts Payable (A/P)', 'accounts' => $apAccounts,              'total' => $totalAp],
                        'credit_cards'              => ['label' => 'Credit Cards',            'accounts' => $creditCards,             'total' => $totalCc],
                        'other_current_liabilities' => ['label' => 'Other Current Liabilities', 'accounts' => $otherCurrentLiabilities, 'total' => $totalOtherCurrLiab],
                        'total'                    => $totalCurrentLiabilities,
                    ],
                    'long_term_liabilities' => ['label' => 'Long-Term Liabilities', 'accounts' => $longTermLiabilities, 'total' => $totalLongTerm],
                    'total' => $totalLiabilities,
                ],

                'equity' => [
                    'label'           => 'Equity',
                    'accounts'        => $equityAccounts,
                    'retained_earnings' => (float) ($data['equity']['retained_earnings'] ?? 0),
                    'net_income'      => $netIncome,
                    'total'           => $totalEquity,
                ],

                'total' => $totalLiabilities + $totalEquity,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Cash Flow
    // -----------------------------------------------------------------------

    /**
     * Reformat a cash-flow array (from Accounting::getCashFlowStatement)
     * into the QBO Statement of Cash Flows structure.
     */
    public function cashFlow(array $data): array
    {
        return [
            'report_name' => 'CashFlow',
            'period'      => $data['period'] ?? [],
            'sbu_code'    => $data['sbu_code'] ?? null,

            'operating_activities' => [
                'label' => 'Operating Activities',
                'net_cash' => (float) ($data['operating_activities'] ?? 0),
            ],
            'investing_activities' => [
                'label' => 'Investing Activities',
                'net_cash' => (float) ($data['investing_activities'] ?? 0),
            ],
            'financing_activities' => [
                'label' => 'Financing Activities',
                'net_cash' => (float) ($data['financing_activities'] ?? 0),
            ],
            'net_change_in_cash' => (float) ($data['net_change'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Trial Balance (aligned to QBO column labels)
    // -----------------------------------------------------------------------

    public function trialBalance(array $data): array
    {
        $rows = [];

        foreach ($data['accounts'] ?? [] as $item) {
            $qbo = $this->mapper->map($item['account']);
            $rows[] = [
                'account_number' => $item['account']->code ?? '',
                'account_name'   => $item['account']->name ?? '',
                'account_type'   => $qbo['AccountType'],
                'debit'          => (float) ($item['debit'] ?? 0),
                'credit'         => (float) ($item['credit'] ?? 0),
            ];
        }

        return [
            'report_name'   => 'TrialBalance',
            'period'        => ['start' => $data['accounts'][0]['start_date'] ?? null, 'end' => null],
            'sbu_code'      => $data['sbu_code'] ?? null,
            'rows'          => $rows,
            'total_debits'  => (float) ($data['total_debits'] ?? 0),
            'total_credits' => (float) ($data['total_credits'] ?? 0),
            'is_balanced'   => (bool) ($data['is_balanced'] ?? false),
        ];
    }

    // -----------------------------------------------------------------------
    // A/R & A/P Aging (QBO Aged Receivables / Aged Payables structure)
    // -----------------------------------------------------------------------

    /**
     * Reformat A/R aging data into QBO AgedReceivables report structure.
     * $aging comes from Accounting::getArAging().
     */
    public function arAging(array $aging): array
    {
        return $this->formatAging($aging, 'AgedReceivables', 'Customer');
    }

    /**
     * Reformat A/P aging data into QBO AgedPayables report structure.
     * $aging comes from Accounting::getApAging().
     */
    public function apAging(array $aging): array
    {
        return $this->formatAging($aging, 'AgedPayables', 'Vendor');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function formatLine(array $item): array
    {
        $account = $item['account'];
        $qbo     = $this->mapper->map($account);

        return [
            'account_number'  => $account->code ?? '',
            'account_name'    => $account->name ?? '',
            'qbo_type'        => $qbo['AccountType'],
            'qbo_subtype'     => $qbo['AccountSubType'],
            'amount'          => (float) ($item['balance'] ?? $item['amount'] ?? 0),
        ];
    }

    private function formatAging(array $aging, string $reportName, string $entityLabel): array
    {
        $rows = [];

        foreach ($aging['rows'] ?? [] as $row) {
            $rows[] = [
                $entityLabel           => $row['name'] ?? '',
                'current'              => (float) ($row['current'] ?? 0),
                '1_30_days'            => (float) ($row['1_30'] ?? 0),
                '31_60_days'           => (float) ($row['31_60'] ?? 0),
                '61_90_days'           => (float) ($row['61_90'] ?? 0),
                'over_90_days'         => (float) ($row['over_90'] ?? 0),
                'total'                => (float) ($row['total'] ?? 0),
            ];
        }

        return [
            'report_name'    => $reportName,
            'as_of_date'     => $aging['as_of_date'] ?? now()->toDateString(),
            'sbu_code'       => $aging['sbu_code'] ?? null,
            'rows'           => $rows,
            'totals' => [
                'current'      => array_sum(array_column($rows, 'current')),
                '1_30_days'    => array_sum(array_column($rows, '1_30_days')),
                '31_60_days'   => array_sum(array_column($rows, '31_60_days')),
                '61_90_days'   => array_sum(array_column($rows, '61_90_days')),
                'over_90_days' => array_sum(array_column($rows, 'over_90_days')),
                'total'        => array_sum(array_column($rows, 'total')),
            ],
        ];
    }
}
