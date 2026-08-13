<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Models\QuickBooksToken;
use Centrex\Accounting\QuickBooks\QuickBooksClient;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class QuickBooksClientTest extends TestCase
{
    use RefreshDatabase;

    private QuickBooksClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new QuickBooksClient(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            redirectUri: 'https://example.test/qbo/callback',
            sandbox: true,
        );
    }

    public function test_authorization_url_includes_client_id_and_redirect_uri(): void
    {
        $url = $this->client->authorizationUrl('my-state');

        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('state=my-state', $url);
        $this->assertStringContainsString(rawurlencode('https://example.test/qbo/callback'), $url);
    }

    public function test_authorization_url_generates_a_random_state_when_none_given(): void
    {
        $url1 = $this->client->authorizationUrl();
        $url2 = $this->client->authorizationUrl();

        $this->assertNotSame($url1, $url2);
    }

    public function test_exchange_code_stores_the_returned_tokens(): void
    {
        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response([
                'access_token'               => 'access-123',
                'refresh_token'              => 'refresh-123',
                'token_type'                 => 'Bearer',
                'expires_in'                 => 3600,
                'x_refresh_token_expires_in' => 8726400,
            ]),
        ]);

        $token = $this->client->exchangeCode('auth-code', 'realm-1');

        $this->assertSame('realm-1', $token->realm_id);
        $this->assertSame('access-123', $token->access_token);
        $this->assertSame('refresh-123', $token->refresh_token);
        $this->assertDatabaseHas('acct_quickbooks_tokens', ['realm_id' => 'realm-1']);
    }

    public function test_get_request_auto_refreshes_an_expired_access_token_and_retries(): void
    {
        $token = QuickBooksToken::create([
            'realm_id'                 => 'realm-2',
            'access_token'             => 'stale-token',
            'refresh_token'            => 'refresh-2',
            'expires_at'               => now()->subHour(),
            'refresh_token_expires_at' => now()->addDays(90),
        ]);

        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response([
                'access_token'  => 'fresh-token',
                'refresh_token' => 'refresh-2',
                'token_type'    => 'Bearer',
                'expires_in'    => 3600,
            ]),
            'sandbox-quickbooks.api.intuit.com/*' => Http::response(['QueryResponse' => []]),
        ]);

        $this->client->get('realm-2', 'companyinfo/realm-2');

        $token->refresh();
        $this->assertSame('fresh-token', $token->access_token);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'sandbox-quickbooks.api.intuit.com')
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_revoke_token_deletes_the_stored_record(): void
    {
        $token = QuickBooksToken::create([
            'realm_id'                 => 'realm-3',
            'access_token'             => 'access-3',
            'refresh_token'            => 'refresh-3',
            'expires_at'               => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
        ]);

        Http::fake([
            'developer.api.intuit.com/*' => Http::response([]),
        ]);

        $this->client->revokeToken($token);

        $this->assertDatabaseMissing('acct_quickbooks_tokens', ['realm_id' => 'realm-3']);
    }

    public function test_failed_response_throws_a_runtime_exception_with_context(): void
    {
        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token exchange failed');

        $this->client->exchangeCode('bad-code', 'realm-4');
    }
}
