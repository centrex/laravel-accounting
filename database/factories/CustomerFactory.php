<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'code'          => 'CUST-' . $this->faker->unique()->numberBetween(1000, 999999),
            'name'          => $this->faker->name(),
            'email'         => $this->faker->unique()->safeEmail(),
            'phone'         => $this->faker->phoneNumber(),
            'currency'      => 'BDT',
            'credit_limit'  => 0,
            'payment_terms' => 30,
            'is_active'     => true,
        ];
    }
}
