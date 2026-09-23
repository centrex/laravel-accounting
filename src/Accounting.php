<?php

declare(strict_types = 1);

namespace Centrex\Accounting;

class Accounting
{
    use Concerns\GeneratesAgingReports;
    use Concerns\GeneratesFinancialReports;
    use Concerns\HasSharedAccountingHelpers;
    use Concerns\ManagesBankReconciliation;
    use Concerns\ManagesBills;
    use Concerns\ManagesBudgets;
    use Concerns\ManagesChartOfAccounts;
    use Concerns\ManagesCreditMemos;
    use Concerns\ManagesExpenses;
    use Concerns\ManagesFiscalYear;
    use Concerns\ManagesFixedAssets;
    use Concerns\ManagesInventoryFinancing;
    use Concerns\ManagesInvoices;
    use Concerns\ManagesJournalEntries;
    use Concerns\ManagesLoanFacilities;
    use Concerns\ManagesOwnerEquity;
    use Concerns\ManagesPeriodClosing;
    use Concerns\ManagesRequisitions;
}
