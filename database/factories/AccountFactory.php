<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'code'      => (string) $this->faker->unique()->numberBetween(1000, 999999),
            'name'      => $this->faker->words(2, true),
            'type'      => $this->faker->randomElement(['asset', 'liability', 'equity', 'revenue', 'expense']),
            'subtype'   => null,
            'currency'  => 'BDT',
            'is_active' => true,
            'is_system' => false,
            'level'     => 1,
        ];
    }
}
