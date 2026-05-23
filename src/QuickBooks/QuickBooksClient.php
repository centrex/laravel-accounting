<?php

declare(strict_types = 1);

namespace Centrex\Accounting\QuickBooks;

use Centrex\Accounting\Models\QuickBooksToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Low-level HTTP client for the QuickBooks Online v3 REST API.
 *
 * Handles:
 *   - OAuth2 authorization URL construction
 *   - Authorization code → token exchange
 *   - Access-token refresh (1-hour TTL)
 *   - Signed GET / POST / batch requests
 *   - QBO Query Language (SELECT ...) requests
 *
 * Requires config keys under accounting.quickbooks:
 *   client_id, client_secret, redirect_uri, environment (sandbox|production)
 */
final class QuickBooksClient
{
    private const SANDBOX_BASE     = 'https://sandbox-quickbooks.api.intuit.com/v3/company';
    private const PRODUCTION_BASE  = 'https://quickbooks.api.intuit.com/v3/company';
    private const TOKEN_ENDPOINT   = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    private const AUTH_ENDPOINT    = 'https://appcenter.intuit.com/connect/oauth2';
    private const REVOKE_ENDPOINT  = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    private const SCOPES = 'com.intuit.quickbooks.accounting';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly bool   $sandbox = true,
    ) {}

    // -----------------------------------------------------------------------
    // OAuth2 Flow
    // -----------------------------------------------------------------------

    /** Build the Intuit authorization URL to redirect the user to. */
    public function authorizationUrl(string $state = ''): string
    {
        $state = $state ?: Str::random(32);

        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'scope'         => self::SCOPES,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'state'         => $state,
        ]);
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     * Stores the result in acct_quickbooks_tokens for the given realmId.
     */
    public function exchangeCode(string $code, string $realmId): QuickBooksToken
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post(self::TOKEN_ENDPOINT, [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $this->redirectUri,
            ]);

        $this->assertSuccess($response, 'Token exchange failed');

        return $this->persistToken($realmId, $response->json());
    }

    /**
     * Refresh the access token using the stored refresh token.
     * Updates the DB record in place.
     */
    public function refreshToken(QuickBooksToken $token): QuickBooksToken
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post(self::TOKEN_ENDPOINT, [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $token->refresh_token,
            ]);

        $this->assertSuccess($response, 'Token refresh failed');

        return $this->persistToken($token->realm_id, $response->json(), $token);
    }

    /**
     * Revoke the stored tokens (disconnect).
     * Deletes the DB record on success.
     */
    public function revokeToken(QuickBooksToken $token): void
    {
        Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post(self::REVOKE_ENDPOINT, ['token' => $token->refresh_token]);

        $token->delete();
    }

    // -----------------------------------------------------------------------
    // Authenticated API calls
    // -----------------------------------------------------------------------

    /** GET /company/{realmId}/{endpoint} */
    public function get(string $realmId, string $endpoint, array $query = []): array
    {
        return $this->request($realmId, 'GET', $endpoint, query: $query);
    }

    /** POST /company/{realmId}/{endpoint} */
    public function post(string $realmId, string $endpoint, array $body = []): array
    {
        return $this->request($realmId, 'POST', $endpoint, body: $body);
    }

    /**
     * Execute a QBO Query Language SELECT statement.
     *
     * Example: $client->query($realm, "SELECT * FROM Account WHERE Active = true")
     */
    public function query(string $realmId, string $sql): array
    {
        return $this->request($realmId, 'GET', 'query', query: ['query' => $sql]);
    }

    /**
     * Fetch a named report from QBO.
     *
     * @param  array  $params  Query parameters (date_macro, start_date, end_date, etc.)
     */
    public function report(string $realmId, string $reportName, array $params = []): array
    {
        return $this->request($realmId, 'GET', "reports/{$reportName}", query: $params);
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    private function request(
        string $realmId,
        string $method,
        string $endpoint,
        array  $query = [],
        array  $body = [],
    ): array {
        $token = $this->resolveToken($realmId);
        $url   = $this->baseUrl() . "/{$realmId}/{$endpoint}";

        $http = Http::withToken($token->access_token)
            ->accept('application/json')
            ->withHeaders(['Content-Type' => 'application/json']);

        $response = match (strtoupper($method)) {
            'GET'  => $http->get($url, $query),
            'POST' => $http->post($url, $body),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        if ($response->status() === 401) {
            // Try once with a refreshed token
            $token    = $this->refreshToken($token);
            $http     = Http::withToken($token->access_token)->accept('application/json');
            $response = match (strtoupper($method)) {
                'GET'  => $http->get($url, $query),
                'POST' => $http->post($url, $body),
                default => $response,
            };
        }

        $this->assertSuccess($response, "QBO API error [{$method} {$endpoint}]");

        return $response->json() ?? [];
    }

    private function resolveToken(string $realmId): QuickBooksToken
    {
        $token = QuickBooksToken::where('realm_id', $realmId)->firstOrFail();

        if ($token->isExpired()) {
            $token = $this->refreshToken($token);
        }

        return $token;
    }

    private function persistToken(string $realmId, array $data, ?QuickBooksToken $existing = null): QuickBooksToken
    {
        $attributes = [
            'access_token'  => $data['access_token'],
            'token_type'    => $data['token_type'] ?? 'Bearer',
            'expires_at'    => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'refresh_token' => $data['refresh_token'] ?? ($existing?->refresh_token ?? ''),
            'refresh_token_expires_at' => isset($data['x_refresh_token_expires_in'])
                ? now()->addSeconds((int) $data['x_refresh_token_expires_in'])
                : ($existing?->refresh_token_expires_at ?? now()->addDays(101)),
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        return QuickBooksToken::updateOrCreate(['realm_id' => $realmId], $attributes);
    }

    private function assertSuccess(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw new \RuntimeException(
                "{$context}: HTTP {$response->status()} — " . $response->body(),
            );
        }
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
    }
}
