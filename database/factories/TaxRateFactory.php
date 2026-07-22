<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxRate> */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        return [
            'name'        => $this->faker->unique()->words(2, true) . ' Tax',
            'code'        => 'TAX-' . $this->faker->unique()->numberBetween(1000, 999999),
            'rate'        => $this->faker->randomFloat(2, 0, 25),
            'is_compound' => false,
            'is_active'   => true,
        ];
    }
}
