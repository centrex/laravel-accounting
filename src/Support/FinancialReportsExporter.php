<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Support;

use Centrex\Accounting\Accounting;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports FinancialReports Livewire data as .xlsx — either the single report
 * currently on screen (downloadReport) or every report type combined into one
 * multi-sheet workbook for the given period (downloadAll), mirroring the
 * report shapes produced by Accounting::getTrialBalance()/getBalanceSheet()/
 * getIncomeStatement()/getCashFlowStatement()/getSalesTaxLiabilityReport().
 */
final class FinancialReportsExporter
{
    /**
     * @param  array<string, mixed>  $reportData
     */
    public static function downloadReport(string $reportType, array $reportData, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        self::writeReportSheet($spreadsheet, 0, $reportType, $reportData);

        return self::stream($spreadsheet, $filename);
    }

    public static function downloadAll(string $startDate, string $endDate, ?string $sbuCode, string $filename): StreamedResponse
    {
        $service = app(Accounting::class);

        $reports = [
            'trial_balance'       => $service->getTrialBalance($startDate, $endDate, $sbuCode),
            'balance_sheet'       => $service->getBalanceSheet($endDate, $sbuCode),
            'income_statement'    => $service->getIncomeStatement($startDate, $endDate, $sbuCode),
            'cash_flow'           => $service->getCashFlowStatement($startDate, $endDate, $sbuCode),
            'sales_tax_liability' => $service->getSalesTaxLiabilityReport($startDate, $endDate, $sbuCode),
        ];

        $spreadsheet = new Spreadsheet();

        self::writeSummarySheet($spreadsheet, 0, $startDate, $endDate, $sbuCode, $reports);

        $index = 1;

        foreach ($reports as $reportType => $reportData) {
            self::writeReportSheet($spreadsheet, $index, $reportType, $reportData);
            $index++;
        }

        $spreadsheet->setActiveSheetIndex(0);

        return self::stream($spreadsheet, $filename);
    }

    private static function stream(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $reports
     */
    private static function writeSummarySheet(Spreadsheet $spreadsheet, int $index, string $startDate, string $endDate, ?string $sbuCode, array $reports): void
    {
        $trialBalance = $reports['trial_balance'];
        $balanceSheet = $reports['balance_sheet'];
        $incomeStatement = $reports['income_statement'];
        $cashFlow = $reports['cash_flow'];
        $salesTax = $reports['sales_tax_liability'];

        $rows = [
            ['Period', 'Start', $startDate],
            ['Period', 'End', $endDate],
            ['Period', 'SBU', $sbuCode ?? 'All'],
            ['Trial Balance', 'Total Debits', round((float) $trialBalance['total_debits'], 2)],
            ['Trial Balance', 'Total Credits', round((float) $trialBalance['total_credits'], 2)],
            ['Trial Balance', 'Balanced?', $trialBalance['is_balanced'] ? 'Yes' : 'No'],
            ['Balance Sheet', 'Total Assets', round((float) ($balanceSheet['assets']['total'] ?? 0), 2)],
            ['Balance Sheet', 'Total Liabilities', round((float) ($balanceSheet['liabilities']['total'] ?? 0), 2)],
            ['Balance Sheet', 'Total Equity (+Net Income)', round((float) ($balanceSheet['equity']['total_with_income'] ?? 0), 2)],
            ['Balance Sheet', 'Balanced?', $balanceSheet['is_balanced'] ? 'Yes' : 'No'],
            ['Income Statement', 'Total Revenue', round((float) ($incomeStatement['revenue']['total'] ?? 0), 2)],
            ['Income Statement', 'Gross Profit', round((float) $incomeStatement['gross_profit'], 2)],
            ['Income Statement', 'Net Income', round((float) $incomeStatement['net_income'], 2)],
            ['Cash Flow', 'Operating Activities', round((float) ($cashFlow['operating_activities'] ?? 0), 2)],
            ['Cash Flow', 'Investing Activities', round((float) ($cashFlow['investing_activities'] ?? 0), 2)],
            ['Cash Flow', 'Financing Activities', round((float) ($cashFlow['financing_activities'] ?? 0), 2)],
            ['Cash Flow', 'Net Change', round((float) ($cashFlow['net_change'] ?? 0), 2)],
            ['Sales Tax Liability', 'Total Collected', round((float) $salesTax['total_collected'], 2)],
            ['Sales Tax Liability', 'Total Paid', round((float) $salesTax['total_paid'], 2)],
            ['Sales Tax Liability', 'Net Payable', round((float) $salesTax['total_net_payable'], 2)],
        ];

        self::writeSheet($spreadsheet, $index, 'Summary', ['Report', 'Metric', 'Value'], $rows);
    }

    /**
     * @param  array<string, mixed>  $reportData
     */
    private static function writeReportSheet(Spreadsheet $spreadsheet, int $index, string $reportType, array $reportData): void
    {
        match ($reportType) {
            'trial_balance'       => self::writeTrialBalanceSheet($spreadsheet, $index, $reportData),
            'balance_sheet'       => self::writeBalanceSheetSheet($spreadsheet, $index, $reportData),
            'income_statement'    => self::writeIncomeStatementSheet($spreadsheet, $index, $reportData),
            'cash_flow'           => self::writeCashFlowSheet($spreadsheet, $index, $reportData),
            'sales_tax_liability' => self::writeSalesTaxLiabilitySheet($spreadsheet, $index, $reportData),
            default               => self::writeSheet($spreadsheet, $index, 'Report', ['No data'], []),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function writeTrialBalanceSheet(Spreadsheet $spreadsheet, int $index, array $data): void
    {
        $rows = [];

        foreach ($data['accounts'] as $item) {
            $rows[] = [$item['account']->code, $item['account']->name, round((float) $item['debit'], 2), round((float) $item['credit'], 2)];
        }
        $rows[] = ['', '', '', ''];
        $rows[] = ['', 'TOTAL', round((float) $data['total_debits'], 2), round((float) $data['total_credits'], 2)];
        $rows[] = ['', 'Balanced?', $data['is_balanced'] ? 'Yes' : 'No', ''];

        self::writeSheet($spreadsheet, $index, 'Trial Balance', ['Code', 'Account', 'Debit', 'Credit'], $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function writeBalanceSheetSheet(Spreadsheet $spreadsheet, int $index, array $data): void
    {
        $rows = [];

        foreach (['assets' => 'ASSETS', 'liabilities' => 'LIABILITIES', 'equity' => 'EQUITY'] as $key => $label) {
            $section = $data[$key] ?? ['accounts' => [], 'total' => 0];

            foreach ($section['accounts'] as $item) {
                $rows[] = [$label, $item['account']->code, $item['account']->name, round((float) $item['balance'], 2)];
            }

            if ($key === 'equity' && isset($data['equity']['net_income'])) {
                $rows[] = [$label, '', 'Net Income (Current Period)', round((float) $data['equity']['net_income'], 2)];
            }

            $totalKey = $key === 'equity' ? 'total_with_income' : 'total';
            $rows[] = [$label, '', 'Subtotal', round((float) ($section[$totalKey] ?? 0), 2)];
            $rows[] = ['', '', '', ''];
        }

        $rows[] = ['', '', 'Total Liabilities & Equity', round((float) ($data['liabilities']['total'] ?? 0) + (float) ($data['equity']['total_with_income'] ?? 0), 2)];
        $rows[] = ['', '', 'Balanced?', $data['is_balanced'] ? 'Yes' : 'No'];

        self::writeSheet($spreadsheet, $index, 'Balance Sheet', ['Section', 'Code', 'Account', 'Balance'], $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function writeIncomeStatementSheet(Spreadsheet $spreadsheet, int $index, array $data): void
    {
        $rows = [];

        foreach ($data['revenue']['accounts'] as $item) {
            $rows[] = ['REVENUE', $item['account']->code, $item['account']->name, round((float) $item['balance'], 2)];
        }
        $rows[] = ['REVENUE', '', 'Total Revenue', round((float) $data['revenue']['total'], 2)];
        $rows[] = ['', '', '', ''];

        foreach (($data['cogs']['accounts'] ?? []) as $item) {
            $rows[] = ['COGS', $item['account']->code, $item['account']->name, round((float) $item['balance'], 2)];
        }

        if (!empty($data['cogs']['accounts'])) {
            $rows[] = ['COGS', '', 'Total COGS', round((float) $data['cogs']['total'], 2)];
            $rows[] = ['', '', 'GROSS PROFIT', round((float) $data['gross_profit'], 2)];
            $rows[] = ['', '', '', ''];
        }

        foreach (($data['expenses']['accounts'] ?? []) as $item) {
            $rows[] = ['OPEX', $item['account']->code, $item['account']->name, round((float) $item['balance'], 2)];
        }

        if (!empty($data['expenses']['accounts'])) {
            $rows[] = ['OPEX', '', 'Total Operating Expenses', round((float) $data['expenses']['total'], 2)];
        }

        $rows[] = ['', '', $data['net_income'] >= 0 ? 'NET INCOME' : 'NET LOSS', round((float) $data['net_income'], 2)];

        self::writeSheet($spreadsheet, $index, 'Income Statement', ['Section', 'Code', 'Account', 'Amount'], $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function writeCashFlowSheet(Spreadsheet $spreadsheet, int $index, array $data): void
    {
        $rows = [
            ['Operating Activities', round((float) ($data['operating_activities'] ?? 0), 2)],
            ['Investing Activities', round((float) ($data['investing_activities'] ?? 0), 2)],
            ['Financing Activities', round((float) ($data['financing_activities'] ?? 0), 2)],
            ['Net Change in Cash', round((float) ($data['net_change'] ?? 0), 2)],
        ];

        self::writeSheet($spreadsheet, $index, 'Cash Flow', ['Section', 'Amount'], $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function writeSalesTaxLiabilitySheet(Spreadsheet $spreadsheet, int $index, array $data): void
    {
        $rows = [];

        foreach ($data['rows'] as $row) {
            $rows[] = [
                $row['name'],
                $row['code'] ?? '',
                $row['rate'] !== null ? round((float) $row['rate'], 2) : null,
                round((float) $row['collected'], 2),
                round((float) $row['paid'], 2),
                round((float) $row['net_payable'], 2),
            ];
        }
        $rows[] = ['', '', '', '', '', ''];
        $rows[] = ['TOTAL', '', '', round((float) $data['total_collected'], 2), round((float) $data['total_paid'], 2), round((float) $data['total_net_payable'], 2)];

        self::writeSheet($spreadsheet, $index, 'Sales Tax Liability', ['Tax Rate', 'Code', 'Rate %', 'Collected', 'Paid', 'Net Payable'], $rows);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private static function writeSheet(Spreadsheet $spreadsheet, int $index, string $title, array $headers, array $rows): Worksheet
    {
        $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray([$headers, ...$rows], null, 'A1');

        $lastColumn = $sheet->getHighestColumn();
        $headerRange = "A1:{$lastColumn}1";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->freezePane('A2');

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $sheet;
    }
}
