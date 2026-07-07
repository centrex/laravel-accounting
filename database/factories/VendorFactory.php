<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vendor> */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'code'          => 'VEND-' . $this->faker->unique()->numberBetween(1000, 999999),
            'name'          => $this->faker->company(),
            'email'         => $this->faker->unique()->safeEmail(),
            'phone'         => $this->faker->phoneNumber(),
            'currency'      => 'BDT',
            'payment_terms' => 30,
            'is_active'     => true,
        ];
    }
}
