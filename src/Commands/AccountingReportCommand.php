<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Commands;

use Centrex\LaravelAccounting\Accounting;
use Illuminate\Console\Command;

final class AccountingReportCommand extends Command
{
    protected $signature = 'accounting:report
        {type=all : report type (trial-balance|balance-sheet|income-statement|cash-flow|all)}
        {--start= : Start date (YYYY-MM-DD) for period reports}
        {--end= : End date (YYYY-MM-DD) for period reports}
        {--date= : Single date (YYYY-MM-DD) for balance-sheet}
        {--format=table : output format (table|csv|json)}
        {--output= : (optional) file path to save output; if omitted, prints to stdout}';

    protected $description = 'Generate accounting reports (trial balance, balance sheet, income statement, cash flow).';

    public function handle(Accounting $acct): int
    {
        $type = strtolower($this->argument('type'));
        $format = strtolower($this->option('format') ?? 'table');
        $outputPath = $this->option('output') ?: null;

        $start = $this->option('start') ?: null;
        $end = $this->option('end') ?: null;
        $date = $this->option('date') ?: null;

        // Validate format
        if (!in_array($format, ['table', 'csv', 'json'], true)) {
            $this->error("Invalid format: {$format}. Use table, csv or json.");

            return self::FAILURE;
        }

        $results = [];

        // Gather requested reports
        $typesToRun = $type === 'all' ? ['trial-balance', 'balance-sheet', 'income-statement', 'cash-flow'] : [$type];

        foreach ($typesToRun as $t) {
            switch ($t) {
                case 'trial-balance':
                    $this->line('<fg=cyan>--- Trial Balance ---</>');
                    $data = $acct->getTrialBalance($start, $end);
                    $results['trial_balance'] = $data;
                    $this->renderTrialBalance($data, $format, $outputPath, $fileSuffix = 'trial_balance');

                    break;

                case 'balance-sheet':
                    $useDate = $date ?: $end ?: now()->toDateString();
                    $this->line("<fg=cyan>--- Balance Sheet ({$useDate}) ---</>");
                    $data = $acct->getBalanceSheet($useDate);
                    $results['balance_sheet'] = $data;
                    $this->renderBalanceSheet($data, $format, $outputPath, 'balance_sheet');

                    break;

                case 'income-statement':
                    if (!$start || !$end) {
                        $this->warn('Income Statement requires --start and --end; skipping.');

                        break;
                    }
                    $this->line("<fg=cyan>--- Income Statement ({$start} → {$end}) ---</>");
                    $data = $acct->getIncomeStatement($start, $end);
                    $results['income_statement'] = $data;
                    $this->renderIncomeStatement($data, $format, $outputPath, 'income_statement');

                    break;

                case 'cash-flow':
                    if (!$start || !$end) {
                        $this->warn('Cash Flow requires --start and --end; skipping.');

                        break;
                    }
                    $this->line("<fg=cyan>--- Cash Flow ({$start} → {$end}) ---</>");
                    $data = $acct->getCashFlowStatement($start, $end);
                    $results['cash_flow'] = $data;
                    $this->renderCashFlow($data, $format, $outputPath, 'cash_flow');

                    break;

                default:
                    $this->warn("Unknown report type: {$t}");

                    break;
            }

            // spacing between reports
            $this->newLine();
        }

        // If multiple reports and outputPath provided, we may have created separate files.
        // Also return the full data in json when format=json and no output path was given.
        if ($format === 'json' && !$outputPath && $type === 'all') {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /* ------------------------------ Renderers ------------------------------ */

    private function renderTrialBalance(array $data, string $format, ?string $outputPath = null, string $suffix = 'trial_balance'): void
    {
        $rows = [];

        foreach ($data['accounts'] as $r) {
            $rows[] = [
                'code'   => $r['account']->code,
                'name'   => $r['account']->name,
                'debit'  => number_format($r['debit'], 2),
                'credit' => number_format($r['credit'], 2),
            ];
        }

        $headers = ['Code', 'Account', 'Debit', 'Credit'];

        $this->renderOutput($rows, $headers, $format, $outputPath, $suffix, function () use ($rows, $headers, $data): void {
            // pretty terminal
            $this->table($headers, array_map('array_values', $rows));
            $this->line('<fg=green>Total Debits : ' . number_format($data['total_debits'], 2) . '</>');
            $this->line('<fg=green>Total Credits: ' . number_format($data['total_credits'], 2) . '</>');
            $this->line('<fg=yellow>Balanced?    : ' . ($data['is_balanced'] ? 'YES' : 'NO') . '</>');
        });
    }

    private function renderBalanceSheet(array $data, string $format, ?string $outputPath = null, string $suffix = 'balance_sheet'): void
    {
        // Build flattened rows: section, code, account, balance
        $rows = [];

        foreach (['assets', 'liabilities', 'equity'] as $section) {
            $sectionData = $data[$section] ?? ['accounts' => [], 'total' => 0];

            foreach ($sectionData['accounts'] as $item) {
                $rows[] = [
                    'section' => strtoupper($section),
                    'code'    => $item['account']->code,
                    'name'    => $item['account']->name,
                    'balance' => number_format($item['balance'], 2),
                ];
            }
            // separator row per section to show subtotal in table output
            $rows[] = [
                'section' => strtoupper($section),
                'code'    => '',
                'name'    => 'Subtotal',
                'balance' => number_format($sectionData['total'] ?? 0, 2),
            ];
            $rows[] = ['section' => '', 'code' => '', 'name' => '', 'balance' => ''];
        }

        $headers = ['Section', 'Code', 'Account', 'Balance'];

        $this->renderOutput($rows, $headers, $format, $outputPath, $suffix, function () use ($data, $headers, $rows): void {
            // pretty terminal: group by section
            $this->table($headers, array_map('array_values', $rows));
            $this->line('<fg=green>Assets Total: ' . number_format($data['assets']['total'] ?? 0, 2) . '</>');
            $this->line('<fg=green>Liabilities Total: ' . number_format($data['liabilities']['total'] ?? 0, 2) . '</>');
            $this->line('<fg=green>Equity Total: ' . number_format($data['equity']['total'] ?? 0, 2) . '</>');
            $this->line('<fg=yellow>Balanced? : ' . ($data['is_balanced'] ? 'YES' : 'NO') . '</>');
        });
    }

    private function renderIncomeStatement(array $data, string $format, ?string $outputPath = null, string $suffix = 'income_statement'): void
    {
        $rows = [];

        foreach ($data['revenue']['accounts'] as $item) {
            $rows[] = [
                'section' => 'REVENUE',
                'code'    => $item['account']->code,
                'name'    => $item['account']->name,
                'amount'  => number_format($item['balance'], 2),
            ];
        }
        $rows[] = ['section' => '', 'code' => '', 'name' => '', 'amount' => ''];

        foreach ($data['expenses']['accounts'] as $item) {
            $rows[] = [
                'section' => 'EXPENSE',
                'code'    => $item['account']->code,
                'name'    => $item['account']->name,
                'amount'  => number_format($item['balance'], 2),
            ];
        }

        $headers = ['Section', 'Code', 'Account', 'Amount'];

        $this->renderOutput($rows, $headers, $format, $outputPath, $suffix, function () use ($data, $headers, $rows): void {
            $this->table($headers, array_map('array_values', $rows));
            $this->line('<fg=green>Gross Profit: ' . number_format($data['gross_profit'], 2) . '</>');
            $this->line('<fg=green>Net Income  : ' . number_format($data['net_income'], 2) . '</>');
        });
    }

    private function renderCashFlow(array $data, string $format, ?string $outputPath = null, string $suffix = 'cash_flow'): void
    {
        $rows = [
            ['category' => 'Operating Activities', 'amount' => number_format($data['operating_activities'], 2)],
            ['category' => 'Investing Activities', 'amount' => number_format($data['investing_activities'], 2)],
            ['category' => 'Financing Activities', 'amount' => number_format($data['financing_activities'], 2)],
            ['category' => 'Net Change in Cash', 'amount' => number_format($data['net_change'], 2)],
        ];

        $headers = ['Category', 'Amount'];

        $this->renderOutput($rows, $headers, $format, $outputPath, $suffix, function () use ($rows, $headers): void {
            $this->table($headers, array_map('array_values', $rows));
        });
    }

    /* ------------------------------ Output helpers ------------------------------ */

    /**
     * Generic: render data either to console (via $printer callback) or to file (csv/json)
     *
     * @param  array  $rows  - array of associative rows
     * @param  array  $headers  - header titles
     * @param  string  $format  - table|csv|json
     * @param  string|null  $outputPath  - if provided, write file (appends suffix)
     * @param  string  $suffix  - filename suffix
     * @param  callable  $printer  - how to print to terminal when format=table
     */
    private function renderOutput(array $rows, array $headers, string $format, ?string $outputPath, string $suffix, callable $printer): void
    {
        if ($format === 'table') {
            $printer();

            return;
        }

        // prepare data as numeric-indexed rows for CSV and JSON
        $dataRows = array_map(fn ($r): array => array_values($r), $rows);
        $headerRow = $headers;

        // if output path provided: write file; otherwise print to STDOUT
        if ($outputPath) {
            // ensure extension based on format
            $ext = $format === 'csv' ? 'csv' : 'json';
            $path = rtrim($outputPath, DIRECTORY_SEPARATOR);

            // if path is a directory, create a file named by suffix
            if (is_dir($path) || substr($path, -1) === DIRECTORY_SEPARATOR) {
                $file = $path . DIRECTORY_SEPARATOR . $suffix . '.' . $ext;
            } else {
                // if provided path has extension, respect it; otherwise append ext
                $file = pathinfo($path, PATHINFO_EXTENSION) !== '' && pathinfo($path, PATHINFO_EXTENSION) !== '0' ? $path : $path . '.' . $ext;
            }

            if ($format === 'csv') {
                $this->writeCsv($file, $headerRow, $dataRows);
            } else {
                $this->writeJson($file, $rows);
            }

            $this->info("<fg=green>Saved to:</> {$file}");

            return;
        }

        // no output path — print to stdout in selected format
        if ($format === 'csv') {
            // print CSV to stdout
            $this->line($this->arrayToCsvLine($headerRow));

            foreach ($dataRows as $r) {
                $this->line($this->arrayToCsvLine($r));
            }

            return;
        }

        // json to stdout
        $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeCsv(string $file, array $headers, array $rows): void
    {
        $handle = fopen($file, 'w');

        if ($handle === false) {
            $this->error("Unable to open file for writing: {$file}");

            return;
        }
        // header
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    private function writeJson(string $file, array $rows): void
    {
        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function arrayToCsvLine(array $arr): string
    {
        // basic CSV line generator (no external libs)
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $arr);
        rewind($fp);
        $line = stream_get_contents($fp);
        fclose($fp);

        return rtrim($line, "\n");
    }
}
