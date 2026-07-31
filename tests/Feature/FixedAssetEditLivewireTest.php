<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\FixedAssets;
use Centrex\Accounting\Models\{Account, FixedAsset};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class FixedAssetEditLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    public function test_editing_a_freshly_registered_asset_updates_every_field_including_cost(): void
    {
        $asset = $this->createAsset(name: 'Old Name', cost: 300, life: 3);

        Livewire::test(FixedAssets::class)
            ->call('openEdit', $asset->id)
            ->assertSet('editCostLocked', false)
            ->set('edit_name', 'New Name')
            ->set('edit_acquisition_cost', '450')
            ->set('edit_salvage_value', '50')
            ->set('edit_useful_life_months', '6')
            ->set('edit_location', 'Warehouse B')
            ->call('submitEdit');

        $fresh = $asset->fresh();
        $this->assertEquals('New Name', $fresh->name);
        $this->assertEquals('450.00', $fresh->acquisition_cost);
        $this->assertEquals('50.00', $fresh->salvage_value);
        $this->assertEquals(6, $fresh->useful_life_months);
        $this->assertEquals('Warehouse B', $fresh->location);
    }

    public function test_editing_an_asset_with_posted_depreciation_locks_acquisition_cost(): void
    {
        $asset = $this->depreciatedAsset();
        $originalCost = (float) $asset->acquisition_cost;

        Livewire::test(FixedAssets::class)
            ->call('openEdit', $asset->id)
            ->assertSet('editCostLocked', true)
            ->set('edit_acquisition_cost', '999999') // attempted tamper, should be ignored
            ->set('edit_name', 'Renamed After Depreciation')
            ->set('edit_useful_life_months', '5') // still allowed — prospective estimate change
            ->call('submitEdit');

        $fresh = $asset->fresh();
        $this->assertEquals($originalCost, (float) $fresh->acquisition_cost, 'acquisition_cost must not change once depreciation has posted');
        $this->assertEquals('Renamed After Depreciation', $fresh->name);
        $this->assertEquals(5, $fresh->useful_life_months, 'useful_life_months stays editable as a prospective estimate change');
    }

    public function test_editing_a_disposed_asset_locks_acquisition_cost(): void
    {
        $asset = $this->createAsset(cost: 300, life: 3);
        $this->accounting->capitalizeFixedAsset($asset, now()->toDateString(), 'FA-CAP-DISP')->post();
        $this->accounting->disposeAsset($asset->fresh(), now()->toDateString(), 100.0)->post();

        Livewire::test(FixedAssets::class)
            ->call('openEdit', $asset->id)
            ->assertSet('editCostLocked', true);
    }

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1100', 'name' => 'Bank',                    'type' => 'asset',   'subtype' => 'current_asset'],
            ['code' => '1700', 'name' => 'Fixed Assets',             'type' => 'asset',   'subtype' => 'fixed_asset'],
            ['code' => '1800', 'name' => 'Accumulated Depreciation', 'type' => 'asset',   'subtype' => 'fixed_asset'],
            ['code' => '4910', 'name' => 'Gain/Loss on Disposal',    'type' => 'revenue', 'subtype' => 'non_operating_revenue'],
            ['code' => '6600', 'name' => 'Depreciation',             'type' => 'expense', 'subtype' => 'depreciation_expense'],
        ];

        foreach ($accounts as $data) {
            Account::create($data);
        }
    }

    private function createAsset(string $name = 'Test Asset', float $cost = 3_600_000, int $life = 36): FixedAsset
    {
        return $this->accounting->addFixedAsset(
            name: $name,
            acquisitionCost: $cost,
            usefulLifeMonths: $life,
        );
    }

    /** An asset capitalized at 3,000,000 with one 3-month-life depreciation run posted. */
    private function depreciatedAsset(): FixedAsset
    {
        $asset = $this->createAsset(cost: 3_000_000, life: 3);
        $this->accounting->capitalizeFixedAsset($asset, now()->toDateString(), 'FA-CAP-DEP')->post();
        $this->accounting->depreciateAsset($asset->fresh())->post();

        return $asset->fresh();
    }
}
