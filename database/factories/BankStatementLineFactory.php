<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\{BankReconciliation, BankStatementLine};
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankStatementLine> */
class BankStatementLineFactory extends Factory
{
    protected $model = BankStatementLine::class;

    public function definition(): array
    {
        return [
            'bank_reconciliation_id' => BankReconciliation::factory(),
            'transaction_date'       => now()->toDateString(),
            'description'            => $this->faker->sentence(3),
            'amount'                 => $this->faker->randomFloat(2, 10, 500),
            'type'                   => 'debit',
        ];
    }
}
