<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Models\QuickBooksToken;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

class QuickBooksControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_endpoint_reports_not_connected_when_no_token_exists(): void
    {
        config(['accounting.quickbooks.default_realm_id' => 'realm-1']);

        $response = $this->getJson('/api/accounting/qbo/status');

        $response->assertOk()->assertJson(['connected' => false, 'realm_id' => 'realm-1']);
    }

    public function test_status_endpoint_reports_connected_with_token_details(): void
    {
        config(['accounting.quickbooks.default_realm_id' => 'realm-1']);

        QuickBooksToken::create([
            'realm_id'                 => 'realm-1',
            'access_token'             => 'access-1',
            'refresh_token'            => 'refresh-1',
            'expires_at'               => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
        ]);

        $response = $this->getJson('/api/accounting/qbo/status');

        $response->assertOk()
            ->assertJson(['connected' => true, 'realm_id' => 'realm-1', 'access_token_expired' => false]);
    }

    public function test_connect_requires_the_qbo_manage_gate(): void
    {
        $response = $this->get('/accounting/qbo/connect');

        $response->assertForbidden();
    }

    public function test_connect_redirects_to_intuit_when_gate_is_granted(): void
    {
        // Override the ability directly rather than the accounting-admin fallback: the base
        // provider's gate closures take an untyped, no-default $user, so Laravel's guest-callable
        // reflection (Gate::parameterAllowsGuests) never invokes them for these unauthenticated
        // requests — overriding here (with a default-null $user, which IS guest-callable) is the
        // documented way to grant access without adding a real authenticated user to this suite.
        Gate::define('accounting.qbo.manage', fn ($user = null) => true);

        $response = $this->get('/accounting/qbo/connect');

        $response->assertRedirect();
        $this->assertStringContainsString('appcenter.intuit.com', $response->headers->get('Location'));
    }

    public function test_disconnect_requires_the_qbo_manage_gate(): void
    {
        $response = $this->postJson('/api/accounting/qbo/disconnect');

        $response->assertForbidden();
    }

    public function test_disconnect_returns_404_when_no_connection_exists(): void
    {
        // Override the ability directly rather than the accounting-admin fallback: the base
        // provider's gate closures take an untyped, no-default $user, so Laravel's guest-callable
        // reflection (Gate::parameterAllowsGuests) never invokes them for these unauthenticated
        // requests — overriding here (with a default-null $user, which IS guest-callable) is the
        // documented way to grant access without adding a real authenticated user to this suite.
        Gate::define('accounting.qbo.manage', fn ($user = null) => true);

        $response = $this->postJson('/api/accounting/qbo/disconnect', ['realm_id' => 'realm-missing']);

        $response->assertNotFound();
    }
}
