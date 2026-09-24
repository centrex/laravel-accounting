<?php

declare(strict_types = 1);

namespace Centrex\Accounting;

use Centrex\Accounting\Models\{JournalEntry, LoanFacility};
use Centrex\Accounting\Services\LoanFacilityService;

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
    use Concerns\ManagesOwnerEquity;
    use Concerns\ManagesPeriodClosing;
    use Concerns\ManagesRequisitions;

    public function __construct(
        private readonly LoanFacilityService $loanFacilities = new LoanFacilityService(),
    ) {}

    /** @see LoanFacilityService::addLoanFacility() */
    public function addLoanFacility(
        string $lenderName,
        string $loanType = 'term_loan',
        string $loanTerm = 'short_term',
        float $monthlyRate = 0.02,
        ?string $sbuCode = null,
        ?float $loanAmount = null,
        ?string $disbursedAt = null,
        ?string $dueAt = null,
        ?int $tenureMonths = null,
        ?string $contact = null,
        ?string $currency = null,
        float|int|string|null $exchangeRate = null,
    ): LoanFacility {
        return $this->loanFacilities->addLoanFacility(
            $lenderName, $loanType, $loanTerm, $monthlyRate, $sbuCode, $loanAmount,
            $disbursedAt, $dueAt, $tenureMonths, $contact, $currency, $exchangeRate,
        );
    }

    /** @see LoanFacilityService::drawdownLoan() */
    public function drawdownLoan(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
        ?string $sbuCode = null,
        ?string $accountCode = null,
    ): JournalEntry {
        return $this->loanFacilities->drawdownLoan($facility, $amount, $date, $reference, $description, $sbuCode, $accountCode);
    }

    /** @see LoanFacilityService::accrueLoanInterest() */
    public function accrueLoanInterest(LoanFacility $facility, mixed $date = null): ?JournalEntry
    {
        return $this->loanFacilities->accrueLoanInterest($facility, $date);
    }

    /** @see LoanFacilityService::accrueAllLoanInterest() */
    public function accrueAllLoanInterest(mixed $date = null): array
    {
        return $this->loanFacilities->accrueAllLoanInterest($date);
    }

    /** @see LoanFacilityService::payLoanInterest() */
    public function payLoanInterest(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $accountCode = null,
    ): JournalEntry {
        return $this->loanFacilities->payLoanInterest($facility, $amount, $date, $reference, $accountCode);
    }

    /** @see LoanFacilityService::repayLoan() */
    public function repayLoan(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
        ?string $sbuCode = null,
        ?string $accountCode = null,
    ): JournalEntry {
        return $this->loanFacilities->repayLoan($facility, $amount, $date, $reference, $description, $sbuCode, $accountCode);
    }

    /** @see LoanFacilityService::getLoanSummary() */
    public function getLoanSummary(?string $sbuCode = null): array
    {
        return $this->loanFacilities->getLoanSummary($sbuCode);
    }
}
