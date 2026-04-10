<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Exceptions;

class DuplicatePaymentException extends AccountingException
{
    public static function forInvoice(int $invoiceId, float $amount, string $date): self
    {
        return new self("A payment of {$amount} on {$date} already exists for invoice #{$invoiceId}.");
    }

    public static function forBill(int $billId, float $amount, string $date): self
    {
        return new self("A payment of {$amount} on {$date} already exists for bill #{$billId}.");
    }

    public static function forExpense(int $expenseId, float $amount, string $date): self
    {
        return new self("A payment of {$amount} on {$date} already exists for expense #{$expenseId}.");
    }
}
