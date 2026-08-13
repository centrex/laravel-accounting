<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Livewire\AccountingQboStatusCard;
use Centrex\Accounting\Models\QuickBooksToken;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class AccountingQboStatusCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_nothing_when_qbo_is_not_configured(): void
    {
        config(['accounting.quickbooks.client_id' => '']);

        Livewire::test(AccountingQboStatusCard::class)
            ->assertOk()
            ->assertDontSee('QuickBooks Online');
    }

    public function test_shows_not_connected_when_configured_without_a_token(): void
    {
        config(['accounting.quickbooks.client_id' => 'client-123']);

        Livewire::test(AccountingQboStatusCard::class)
            ->assertOk()
            ->assertSee('QuickBooks Online')
            ->assertSee('Not Connected');
    }

    public function test_shows_connected_status_with_realm_id_when_a_token_exists(): void
    {
        config([
            'accounting.quickbooks.client_id'        => 'client-123',
            'accounting.quickbooks.default_realm_id' => 'realm-9',
        ]);

        QuickBooksToken::create([
            'realm_id'                 => 'realm-9',
            'access_token'             => 'access-9',
            'refresh_token'            => 'refresh-9',
            'expires_at'               => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
        ]);

        Livewire::test(AccountingQboStatusCard::class)
            ->assertOk()
            ->assertSee('Connected')
            ->assertSee('realm-9')
            ->assertSee('Active');
    }
}
