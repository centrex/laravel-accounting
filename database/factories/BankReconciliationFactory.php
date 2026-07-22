<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\{Account, BankReconciliation};
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankReconciliation> */
class BankReconciliationFactory extends Factory
{
    protected $model = BankReconciliation::class;

    public function definition(): array
    {
        return [
            'account_id'               => Account::factory(),
            'statement_date'           => now()->toDateString(),
            'opening_balance'          => 0,
            'statement_ending_balance' => 0,
            'status'                   => 'draft',
        ];
    }
}
