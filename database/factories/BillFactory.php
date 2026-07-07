<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\{Bill, Vendor};
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bill> */
class BillFactory extends Factory
{
    protected $model = Bill::class;

    public function definition(): array
    {
        $subtotal = (float) $this->faker->randomFloat(2, 100, 1000);
        $tax = round($subtotal * 0.1, 2);

        return [
            'vendor_id'            => Vendor::factory(),
            'bill_date'            => now()->toDateString(),
            'due_date'             => now()->addDays(30)->toDateString(),
            'subtotal'             => $subtotal,
            'tax_amount'           => $tax,
            'discount_amount'      => 0,
            'shipping_amount'      => 0,
            'other_charges_amount' => 0,
            'total'                => $subtotal + $tax,
            'paid_amount'          => 0,
            'currency'             => 'BDT',
            'exchange_rate'        => 1.0,
            'status'               => 'draft',
        ];
    }
}
