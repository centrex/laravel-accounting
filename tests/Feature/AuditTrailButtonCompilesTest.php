<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Livewire\JournalEntries;
use Centrex\Accounting\Models\{Account, JournalEntry};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * @js() never expands inside a Blade component tag's plain attribute string (only
 * {{ }}/{!! !!} echoes are processed there) — it silently rendered as literal "@js(...)"
 * text in the wire:click attribute, which is invalid JS and made every "Audit trail"
 * button in the accounting UI a no-op. Assert the fix: Illuminate\Support\Js::from()
 * via {{ }} actually compiles to a real JS string literal in the rendered wire:click.
 */
class AuditTrailButtonCompilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_entries_audit_trail_button_wire_click_compiles(): void
    {
        $cash = Account::factory()->create(['code' => '1000', 'type' => 'asset']);
        $revenue = Account::factory()->create(['code' => '4000', 'type' => 'revenue']);

        $entry = JournalEntry::create([
            'entry_number' => 'JV-TEST-1',
            'date'         => now(),
            'type'         => 'general',
            'description'  => 'Test entry',
            'currency'     => 'BDT',
            'status'       => 'draft',
        ]);
        $entry->lines()->create(['account_id' => $cash->id, 'type' => 'debit', 'amount' => 100]);
        $entry->lines()->create(['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 100]);

        $html = Livewire::test(JournalEntries::class)->html();

        $this->assertStringNotContainsString('@js(', $html);
        $this->assertStringContainsString(
            "openAuditTrail('Centrex\\\\Accounting\\\\Models\\\\JournalEntry', {$entry->id}, 'JV-TEST-1')",
            $html,
        );
    }
}
