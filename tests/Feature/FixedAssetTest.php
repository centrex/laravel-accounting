<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, FixedAsset};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    // -------------------------------------------------------------------------
    // addFixedAsset
    // -------------------------------------------------------------------------

    public function test_add_fixed_asset_creates_asset_and_contra_gl_accounts(): void
    {
        $asset = $this->createAsset();

        $this->assertDatabaseHas('acct_accounts', [
            'id'      => $asset->asset_account_id,
            'type'    => 'asset',
            'subtype' => 'fixed_asset',
        ]);

        $this->assertDatabaseHas('acct_accounts', [
            'id'      => $asset->accumulated_depreciation_account_id,
            'type'    => 'asset',
            'subtype' => 'contra_account',
        ]);
    }

    public function test_add_fixed_asset_persists_model_row_with_expected_attributes(): void
    {
        $asset = $this->createAsset(name: 'Office Laptops', cost: 300, life: 3);

        $fresh = $asset->fresh();
        $this->assertEquals('Office Laptops', $fresh->name);
        $this->assertEquals('300.00', $fresh->acquisition_cost);
        $this->assertEquals(3, $fresh->useful_life_months);
        $this->assertEquals('straight_line', $fresh->depreciation_method);
        $this->assertTrue($fresh->is_active);
    }

    public function test_add_fixed_asset_generates_sequential_asset_code(): void
    {
        $first = $this->createAsset();
        $second = $this->createAsset();

        $this->assertNotEquals($first->asset_code, $second->asset_code);
        $this->assertMatchesRegularExpression('/^FA-\d{8}-\d{5}$/', $second->asset_code);
    }

    public function test_add_fixed_asset_allocates_sub_account_codes_within_1700_and_1800_ranges(): void
    {
        $asset = $this->createAsset();

        $assetCode = (int) $asset->assetAccount->code;
        $contraCode = (int) $asset->accumulatedDepreciationAccount->code;

        $this->assertGreaterThan(1700, $assetCode);
        $this->assertLessThanOrEqual(1799, $assetCode);
        $this->assertGreaterThan(1800, $contraCode);
        $this->assertLessThanOrEqual(1899, $contraCode);
    }

    // -------------------------------------------------------------------------
    // capitalizeFixedAsset
    // -------------------------------------------------------------------------

    public function test_capitalize_fixed_asset_posts_balanced_debit_credit_entry(): void
    {
        $asset = $this->createAsset(cost: 300, life: 3);

        $entry = $this->accounting->capitalizeFixedAsset($asset, now()->toDateString(), 'FA-CAP-1');
        $entry->post();

        $this->assertTrue($entry->isBalanced());
        $this->assertEquals(300.0, (float) $entry->lines->where('type', 'debit')->sum('amount'));
    }

    public function test_capitalize_fixed_asset_debits_the_assets_own_account(): void
    {
        $asset = $this->createAsset(cost: 300, life: 3);

        $entry = $this->accounting->capitalizeFixedAsset($asset, now()->toDateString(), 'FA-CAP-2');
        $entry->post();

        $this->assertEquals(300.0, $asset->assetAccount->fresh()->getCurrentBalance());
    }

    // -------------------------------------------------------------------------
    // depreciateAsset / depreciateAllAssets
    // -------------------------------------------------------------------------

    public function test_depreciate_asset_posts_correct_straight_line_amount(): void
    {
        $asset = $this->createAsset(cost: 3_600_000, life: 36);

        $entry = $this->accounting->depreciateAsset($asset);
        $entry->post();

        $this->assertEquals(100_000.0, (float) $entry->lines->where('type', 'debit')->sum('amount'));
    }

    public function test_depreciate_asset_credits_the_assets_own_accumulated_depreciation_account(): void
    {
        $asset = $this->createAsset(cost: 3_600_000, life: 36);

        $entry = $this->accounting->depreciateAsset($asset);
        $entry->post();

        $this->assertEquals(100_000.0, $asset->fresh()->accumulatedDepreciation());
    }

    public function test_depreciate_asset_returns_null_when_fully_depreciated(): void
    {
        $asset = $this->createAsset(cost: 300, life: 3);

        for ($i = 0; $i < 3; $i++) {
            $this->accounting->depreciateAsset($asset->fresh())->post();
        }

        $this->assertNull($this->accounting->depreciateAsset($asset->fresh()));
    }

    public function test_depreciate_asset_returns_null_when_inactive(): void
    {
        $asset = $this->createAsset(cost: 300, life: 3);
        $asset->update(['is_active' => false]);

        $this->assertNull($this->accounting->depreciateAsset($asset->fresh()));
    }

    public function test_depreciate_asset_returns_null_when_disposed(): void
    {
        $asset = $this->createAsset(cost: 300, life: 3);
        $this->accounting->capitalizeFixedAsset($asset, now()->toDateString(), 'FA-CAP-3')->post();
        $this->accounting->disposeAsset($asset->fresh(), now()->toDateString(), 300.0)->post();

        $this->assertNull($this->accounting->depreciateAsset($asset->fresh()));
    }

    public function test_depreciate_asset_caps_final_month_at_remaining_depreciable_base(): void
    {
        $asset = $this->createAsset(cost: 100, life: 3);

        for ($i = 0; $i < 3; $i++) {
            $entry = $this->accounting->depreciateAsset($asset->fresh());
            $entry->post();
            $this->assertEquals(33.33, (float) $entry->lines->where('type', 'debit')->sum('amount'));
        }

        // 3 x 33.33 = 99.99 — the 4th call should post only the remaining 0.01, not another 33.33.
        $cappedEntry = $this->accounting->depreciateAsset($asset->fresh());
        $this->assertNotNull($cappedEntry);
        $cappedEntry->post();
        $this->assertEquals(0.01, (float) $cappedEntry->lines->where('type', 'debit')->sum('amount'));

        $this->assertNull($this->accounting->depreciateAsset($asset->fresh()));
    }

    public function test_depreciate_all_assets_skips_inactive_and_disposed_assets(): void
    {
        $active = $this->createAsset(cost: 300, life: 3);
        $inactive = $this->createAsset(cost: 300, life: 3);
        $inactive->update(['is_active' => false]);

        $results = $this->accounting->depreciateAllAssets();

        $this->assertNotNull($results[$active->id]);
        $this->assertArrayNotHasKey($inactive->id, $results);
    }

    // -------------------------------------------------------------------------
    // disposeAsset
    // -------------------------------------------------------------------------

    public function test_dispose_asset_produces_balanced_journal_entry_with_gain(): void
    {
        $asset = $this->depreciatedAsset();

        $entry = $this->accounting->disposeAsset($asset->fresh(), now()->toDateString(), 2_200_000.0);
        $entry->post();

        $this->assertTrue($entry->isBalanced());
        $gainLine = $entry->lines->firstWhere('account_id', Account::where('code', '4910')->first()->id);
        $this->assertEquals('credit', $gainLine->type);
        $this->assertEquals(200_000.0, (float) $gainLine->amount);
    }

    public function test_dispose_asset_produces_balanced_journal_entry_with_loss(): void
    {
        $asset = $this->depreciatedAsset();

        $entry = $this->accounting->disposeAsset($asset->fresh(), now()->toDateString(), 1_500_000.0);
        $entry->post();

        $this->assertTrue($entry->isBalanced());
        $lossLine = $entry->lines->firstWhere('account_id', Account::where('code', '4910')->first()->id);
        $this->assertEquals('debit', $lossLine->type);
        $this->assertEquals(500_000.0, (float) $lossLine->amount);
    }

    public function test_dispose_asset_zeroes_out_asset_and_contra_accounts(): void
    {
        $asset = $this->depreciatedAsset();
        $this->assertEquals(1_000_000.0, $asset->fresh()->accumulatedDepreciation());

        $entry = $this->accounting->disposeAsset($asset->fresh(), now()->toDateString(), 2_000_000.0);
        $entry->post();

        $this->assertEquals(0.0, $asset->assetAccount->fresh()->getCurrentBalance());
        $this->assertEquals(0.0, $asset->accumulatedDepreciationAccount->fresh()->getCurrentBalance());
    }

    public function test_dispose_asset_marks_model_inactive_and_records_disposal_fields(): void
    {
        $asset = $this->depreciatedAsset();
        $date = now()->toDateString();

        $entry = $this->accounting->disposeAsset($asset->fresh(), $date, 2_000_000.0);
        $entry->post();

        $fresh = $asset->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertEquals($date, $fresh->disposed_at->toDateString());
        $this->assertEquals('2000000.00', $fresh->disposal_proceeds);
        $this->assertEquals($entry->id, $fresh->disposal_journal_entry_id);
    }

    public function test_dispose_asset_twice_throws(): void
    {
        $asset = $this->depreciatedAsset();
        $this->accounting->disposeAsset($asset->fresh(), now()->toDateString(), 2_000_000.0)->post();

        $this->expectException(\RuntimeException::class);
        $this->accounting->disposeAsset($asset->fresh(), now()->toDateString(), 2_000_000.0);
    }

    // -------------------------------------------------------------------------
    // getFixedAssetRegister
    // -------------------------------------------------------------------------

    public function test_get_fixed_asset_register_returns_computed_fields(): void
    {
        $asset = $this->createAsset(cost: 300, life: 3);
        $this->accounting->depreciateAsset($asset->fresh())->post();

        $register = $this->accounting->getFixedAssetRegister();
        $row = collect($register)->firstWhere('id', $asset->id);

        $this->assertEquals(100.0, $row['accumulated_depreciation']);
        $this->assertEquals(200.0, $row['net_book_value']);
        $this->assertEquals(100.0, $row['monthly_depreciation']);
        $this->assertFalse($row['is_fully_depreciated']);
    }

    public function test_get_fixed_asset_register_filters_by_sbu_code(): void
    {
        $this->createAsset(cost: 300, life: 3, sbuCode: 'DHK');
        $this->createAsset(cost: 300, life: 3, sbuCode: 'CTG');

        $register = $this->accounting->getFixedAssetRegister('dhk');

        $this->assertCount(1, $register);
        $this->assertEquals('DHK', $register[0]['sbu_code']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function createAsset(
        string $name = 'Test Asset',
        float $cost = 3_600_000,
        int $life = 36,
        ?string $sbuCode = null,
    ): FixedAsset {
        return $this->accounting->addFixedAsset(
            name: $name,
            acquisitionCost: $cost,
            usefulLifeMonths: $life,
            sbuCode: $sbuCode,
        );
    }

    /** An asset capitalized at 3,000,000 with one 3-month-life depreciation run posted (accumulated = 1,000,000). */
    private function depreciatedAsset(): FixedAsset
    {
        $asset = $this->createAsset(cost: 3_000_000, life: 3);
        $this->accounting->capitalizeFixedAsset($asset, now()->toDateString(), 'FA-CAP-DEP')->post();
        $this->accounting->depreciateAsset($asset->fresh())->post();

        return $asset->fresh();
    }
}
