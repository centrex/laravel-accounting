<?php

declare(strict_types = 1);

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\FinancialReports;
use Centrex\Accounting\Models\Account;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    app(Accounting::class)->initializeChartOfAccounts();
});

function loadFinancialReportSpreadsheet(Symfony\Component\HttpFoundation\StreamedResponse $response): PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'financial-report-export');

    ob_start();
    $response->sendContent();
    file_put_contents($tmpFile, ob_get_clean());

    $spreadsheet = IOFactory::load($tmpFile);
    @unlink($tmpFile);

    return $spreadsheet;
}

it('exports the currently generated report as its own single-sheet .xlsx', function (): void {
    $revenue = Account::where('code', '4000')->first();
    $cash = Account::where('code', '1000')->first();

    app(Accounting::class)->createJournalEntry([
        'date'        => today(),
        'reference'   => 'REF-EXPORT-1',
        'type'        => 'general',
        'description' => 'Sale',
        'currency'    => 'BDT',
        'lines'       => [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 500],
            ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 500],
        ],
    ])->post();

    $component = new FinancialReports();
    $component->mount();
    $component->reportType = 'trial_balance';
    $component->startDate = today()->startOfMonth()->format('Y-m-d');
    $component->endDate = today()->format('Y-m-d');

    $response = $component->exportExcel();

    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('trial-balance-')
        ->toContain('.xlsx');

    $spreadsheet = loadFinancialReportSpreadsheet($response);

    expect($spreadsheet->getSheetNames())->toBe(['Trial Balance']);

    $sheet = $spreadsheet->getSheetByName('Trial Balance');
    expect($sheet->getCell('A1')->getValue())->toBe('Code')
        ->and($sheet->getCell('B1')->getValue())->toBe('Account');
});

it('exports every report type as one combined multi-sheet .xlsx workbook', function (): void {
    $component = new FinancialReports();
    $component->mount();

    $response = $component->exportAllExcel();

    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('financial-reports-')
        ->toContain('.xlsx');

    $spreadsheet = loadFinancialReportSpreadsheet($response);

    expect($spreadsheet->getSheetNames())->toBe([
        'Summary', 'Trial Balance', 'Balance Sheet', 'Income Statement', 'Cash Flow', 'Cash Book', 'Sales Tax Liability',
    ]);

    $summary = $spreadsheet->getSheetByName('Summary');
    expect($summary->getCell('A1')->getValue())->toBe('Report')
        ->and($summary->getCell('B1')->getValue())->toBe('Metric');
});
