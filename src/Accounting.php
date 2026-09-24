<?php

declare(strict_types = 1);

namespace Centrex\Accounting;

use Centrex\Accounting\Models\{
    BankReconciliation,
    BankStatementLine,
    FixedAsset,
    InventoryFinancingFacility,
    Invoice,
    JournalEntry,
    JournalEntryLine,
    LoanFacility,
    Payment
};
use Centrex\Accounting\Services\{BankReconciliationService, FixedAssetService, InventoryFinancingService, InvoiceService, LoanFacilityService};
use Illuminate\Support\Collection;

class Accounting
{
    use Concerns\GeneratesAgingReports;
    use Concerns\GeneratesFinancialReports;
    use Concerns\HasSharedAccountingHelpers;
    use Concerns\ManagesBills;
    use Concerns\ManagesBudgets;
    use Concerns\ManagesChartOfAccounts;
    use Concerns\ManagesCreditMemos;
    use Concerns\ManagesExpenses;
    use Concerns\ManagesFiscalYear;
    use Concerns\ManagesJournalEntries;
    use Concerns\ManagesOwnerEquity;
    use Concerns\ManagesPeriodClosing;
    use Concerns\ManagesRequisitions;

    public function __construct(
        private readonly LoanFacilityService $loanFacilities = new LoanFacilityService(),
        private readonly InventoryFinancingService $inventoryFinancing = new InventoryFinancingService(),
        private readonly FixedAssetService $fixedAssets = new FixedAssetService(),
        private readonly BankReconciliationService $bankReconciliation = new BankReconciliationService(),
        private readonly InvoiceService $invoices = new InvoiceService(),
    ) {}

    /** @see InvoiceService::postInvoice() */
    public function postInvoice(Invoice $invoice): JournalEntry
    {
        return $this->invoices->postInvoice($invoice);
    }

    /** @see InvoiceService::recordInvoicePayment() */
    public function recordInvoicePayment(Invoice $invoice, array $paymentData): Payment
    {
        return $this->invoices->recordInvoicePayment($invoice, $paymentData);
    }

    /** @see BankReconciliationService::createBankReconciliation() */
    public function createBankReconciliation(array $data): BankReconciliation
    {
        return $this->bankReconciliation->createBankReconciliation($data);
    }

    /** @see BankReconciliationService::importBankStatementLines() */
    public function importBankStatementLines(BankReconciliation $reconciliation, array $rows): Collection
    {
        return $this->bankReconciliation->importBankStatementLines($reconciliation, $rows);
    }

    /** @see BankReconciliationService::getUnreconciledLines() */
    public function getUnreconciledLines(int $accountId): Collection
    {
        return $this->bankReconciliation->getUnreconciledLines($accountId);
    }

    /** @see BankReconciliationService::matchStatementLine() */
    public function matchStatementLine(BankStatementLine $statementLine, JournalEntryLine $glLine): void
    {
        $this->bankReconciliation->matchStatementLine($statementLine, $glLine);
    }

    /** @see BankReconciliationService::unmatchStatementLine() */
    public function unmatchStatementLine(BankStatementLine $statementLine): void
    {
        $this->bankReconciliation->unmatchStatementLine($statementLine);
    }

    /** @see BankReconciliationService::createAdjustingJournalEntryForStatementLine() */
    public function createAdjustingJournalEntryForStatementLine(BankStatementLine $statementLine, array $data): JournalEntry
    {
        return $this->bankReconciliation->createAdjustingJournalEntryForStatementLine($statementLine, $data);
    }

    /** @see BankReconciliationService::completeBankReconciliation() */
    public function completeBankReconciliation(BankReconciliation $reconciliation): void
    {
        $this->bankReconciliation->completeBankReconciliation($reconciliation);
    }

    /** @see FixedAssetService::addFixedAsset() */
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
        return $this->fixedAssets->addFixedAsset(
            $name, $acquisitionCost, $usefulLifeMonths, $salvageValue,
            $acquiredAt, $assetClass, $sbuCode, $location, $serialNumber, $notes,
        );
    }

    /** @see FixedAssetService::capitalizeFixedAsset() */
    public function capitalizeFixedAsset(
        FixedAsset $asset,
        string $date,
        string $reference,
        ?string $paymentAccountCode = null,
        ?string $description = null,
    ): JournalEntry {
        return $this->fixedAssets->capitalizeFixedAsset($asset, $date, $reference, $paymentAccountCode, $description);
    }

    /** @see FixedAssetService::depreciateAsset() */
    public function depreciateAsset(FixedAsset $asset, ?string $date = null): ?JournalEntry
    {
        return $this->fixedAssets->depreciateAsset($asset, $date);
    }

    /** @see FixedAssetService::depreciateAllAssets() */
    public function depreciateAllAssets(?string $date = null): array
    {
        return $this->fixedAssets->depreciateAllAssets($date);
    }

    /** @see FixedAssetService::disposeAsset() */
    public function disposeAsset(
        FixedAsset $asset,
        string $date,
        float $proceeds = 0.0,
        ?string $reference = null,
    ): JournalEntry {
        return $this->fixedAssets->disposeAsset($asset, $date, $proceeds, $reference);
    }

    /** @see FixedAssetService::getFixedAssetRegister() */
    public function getFixedAssetRegister(?string $sbuCode = null): array
    {
        return $this->fixedAssets->getFixedAssetRegister($sbuCode);
    }

    /** @see InventoryFinancingService::addFinancingFacility() */
    public function addFinancingFacility(
        string $lenderName,
        string $lenderType = 'bank',
        float $monthlyRate = 0.02,
        ?float $creditLimit = null,
        ?string $contact = null,
    ): InventoryFinancingFacility {
        return $this->inventoryFinancing->addFinancingFacility($lenderName, $lenderType, $monthlyRate, $creditLimit, $contact);
    }

    /** @see InventoryFinancingService::drawdownFinancing() */
    public function drawdownFinancing(
        InventoryFinancingFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
    ): JournalEntry {
        return $this->inventoryFinancing->drawdownFinancing($facility, $amount, $date, $reference, $description);
    }

    /** @see InventoryFinancingService::accrueFinancingInterest() */
    public function accrueFinancingInterest(InventoryFinancingFacility $facility, mixed $date = null): ?JournalEntry
    {
        return $this->inventoryFinancing->accrueFinancingInterest($facility, $date);
    }

    /** @see InventoryFinancingService::accrueAllFinancingInterest() */
    public function accrueAllFinancingInterest(mixed $date = null): array
    {
        return $this->inventoryFinancing->accrueAllFinancingInterest($date);
    }

    /** @see InventoryFinancingService::payFinancingInterest() */
    public function payFinancingInterest(
        InventoryFinancingFacility $facility,
        float $amount,
        string $date,
        string $reference,
    ): JournalEntry {
        return $this->inventoryFinancing->payFinancingInterest($facility, $amount, $date, $reference);
    }

    /** @see InventoryFinancingService::repayFinancing() */
    public function repayFinancing(
        InventoryFinancingFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
    ): JournalEntry {
        return $this->inventoryFinancing->repayFinancing($facility, $amount, $date, $reference, $description);
    }

    /** @see InventoryFinancingService::getFinancingSummary() */
    public function getFinancingSummary(): array
    {
        return $this->inventoryFinancing->getFinancingSummary();
    }

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
