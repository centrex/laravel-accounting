<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Support;

use Illuminate\Support\Facades\{Gate, Route};

/**
 * Sidebar navigation + quick-create data for the accounting app shell.
 *
 * Self-contained (no dependency on the wider monorepo's ERP shell) so both this
 * package's own workbench and any host app that adopts
 * resources/views/layouts/shell.blade.php can use it directly.
 */
class AccountingWorkspace
{
    /** @return array<int, array{section: string, items: array}> */
    public static function sidebarNavigation(): array
    {
        $groups = [
            [
                'section' => 'Bookkeeping',
                'items'   => [
                    ['label' => 'Dashboard', 'route' => 'accounting.dashboard', 'icon' => 'o-chart-bar-square'],
                    ['label' => 'Chart of Accounts', 'route' => 'accounting.accounts', 'icon' => 'o-list-bullet', 'permission' => 'accounting.accounts.view'],
                    ['label' => 'Journal Entries', 'route' => 'accounting.journal', 'icon' => 'o-pencil-square', 'permission' => 'accounting.journal.view'],
                    ['label' => 'General Ledger', 'route' => 'accounting.ledger', 'icon' => 'o-book-open', 'permission' => 'accounting.ledger.view'],
                ],
            ],
            [
                'section' => 'Sales',
                'items'   => [
                    ['label' => 'Invoices', 'route' => 'accounting.invoices', 'icon' => 'o-document-text', 'permission' => 'accounting.invoice.view'],
                    ['label' => 'Credit Memos', 'route' => 'accounting.credit-memos', 'icon' => 'o-receipt-refund', 'permission' => 'accounting.credit-memo.view'],
                    ['label' => 'Customers', 'route' => 'accounting.customers', 'icon' => 'o-users', 'permission' => 'accounting.customers.view'],
                    ['label' => 'Customer Ledger', 'route' => 'accounting.ledger.customers', 'icon' => 'o-book-open', 'permission' => 'accounting.ledger.view'],
                ],
            ],
            [
                'section' => 'Expenses',
                'items'   => [
                    ['label' => 'Bills', 'route' => 'accounting.bills', 'icon' => 'o-inbox-arrow-down', 'permission' => 'accounting.bill.view'],
                    ['label' => 'Vendors', 'route' => 'accounting.vendors', 'icon' => 'o-building-storefront', 'permission' => 'accounting.vendors.view'],
                    ['label' => 'Vendor Ledger', 'route' => 'accounting.ledger.vendors', 'icon' => 'o-book-open', 'permission' => 'accounting.ledger.view'],
                    ['label' => 'Expenses', 'route' => 'accounting.expenses', 'icon' => 'o-credit-card', 'permission' => 'accounting.expense.view'],
                    ['label' => 'Requisitions', 'route' => 'accounting.requisitions', 'icon' => 'o-clipboard-document', 'permission' => 'accounting.requisitions.view'],
                ],
            ],
            [
                'section' => 'Reports',
                'items'   => [
                    ['label' => 'Financial Reports', 'route' => 'accounting.reports', 'icon' => 'o-chart-pie', 'permission' => 'accounting.reports.view'],
                    ['label' => 'Budgets', 'route' => 'accounting.budgets', 'icon' => 'o-banknotes', 'permission' => 'accounting.budget.view'],
                ],
            ],
            [
                'section' => 'Admin',
                'items'   => [
                    ['label' => 'Period Close', 'route' => 'accounting.period-close', 'icon' => 'o-lock-closed', 'permission' => 'accounting.fiscal-year.close'],
                ],
            ],
        ];

        return collect($groups)
            ->map(fn (array $group): array => [
                'section' => $group['section'],
                'items'   => collect($group['items'])->map(self::mapItem(...))->all(),
            ])
            ->all();
    }

    /** Items for the header's "+ New" quick-create dropdown. */
    public static function quickCreateActions(): array
    {
        $actions = [
            ['label' => 'Invoice', 'description' => 'Bill a customer', 'route' => 'accounting.invoices', 'icon' => 'o-document-text', 'permission' => 'accounting.invoice.create'],
            ['label' => 'Credit Memo', 'description' => 'Credit a customer for a return', 'route' => 'accounting.credit-memos', 'icon' => 'o-receipt-refund', 'permission' => 'accounting.credit-memo.create'],
            ['label' => 'Bill', 'description' => 'Record a vendor bill', 'route' => 'accounting.bills', 'icon' => 'o-inbox-arrow-down', 'permission' => 'accounting.bill.create'],
            ['label' => 'Expense', 'description' => 'Log a cash expense', 'route' => 'accounting.expenses', 'icon' => 'o-credit-card'],
            ['label' => 'Journal Entry', 'description' => 'Post a manual entry', 'route' => 'accounting.journal', 'icon' => 'o-pencil-square', 'permission' => 'accounting.journal.create'],
        ];

        return collect($actions)->map(self::mapItem(...))->all();
    }

    private static function mapItem(array $item): array
    {
        $routeName = $item['route'];
        $available = Route::has($routeName) && self::hasPermission($item['permission'] ?? null);

        return array_merge($item, [
            'available' => $available,
            'url'       => $available ? route($routeName) : '#',
        ]);
    }

    private static function hasPermission(?string $permission): bool
    {
        return $permission === null || Gate::allows($permission);
    }
}
