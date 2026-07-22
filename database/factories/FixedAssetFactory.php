<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Database\Factories;

use Centrex\Accounting\Models\{Account, FixedAsset};
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FixedAsset> */
class FixedAssetFactory extends Factory
{
    protected $model = FixedAsset::class;

    public function definition(): array
    {
        $cost = $this->faker->randomFloat(2, 50_000, 5_000_000);

        return [
            'name'                                => $this->faker->words(3, true),
            'asset_class'                         => $this->faker->randomElement(['computer_equipment', 'furniture', 'vehicle']),
            'asset_account_id'                    => Account::factory()->create(['type' => 'asset', 'subtype' => 'fixed_asset']),
            'accumulated_depreciation_account_id' => Account::factory()->create(['type' => 'asset', 'subtype' => 'contra_account']),
            'acquisition_cost'                    => $cost,
            'salvage_value'                       => 0,
            'useful_life_months'                  => $this->faker->randomElement([24, 36, 48, 60]),
            'depreciation_method'                 => 'straight_line',
            'acquired_at'                         => now()->subMonths($this->faker->numberBetween(0, 12))->toDateString(),
            'is_active'                           => true,
        ];
    }
}
