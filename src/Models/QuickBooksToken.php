<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores QuickBooks Online OAuth2 tokens per realm (company).
 *
 * @property int         $id
 * @property string      $realm_id                   QBO company realm ID
 * @property string      $access_token               Short-lived JWT (1 hour)
 * @property string      $token_type                 Always 'Bearer'
 * @property string      $refresh_token              Long-lived refresh token (101 days)
 * @property \Carbon\Carbon $expires_at              When access_token expires
 * @property \Carbon\Carbon|null $refresh_token_expires_at
 * @property array|null  $meta                       Raw extra data (e.g. company info)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class QuickBooksToken extends Model
{
    use AddTablePrefix;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at'                => 'datetime',
            'refresh_token_expires_at'  => 'datetime',
            'meta'                      => 'array',
        ];
    }

    protected function getTableSuffix(): string
    {
        return 'quickbooks_tokens';
    }

    /** True when the access token is expired or within 60 s of expiry. */
    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->subSeconds(60)->isPast();
    }

    /** True when the refresh token itself is expired (requires full re-auth). */
    public function isRefreshExpired(): bool
    {
        return $this->refresh_token_expires_at !== null && $this->refresh_token_expires_at->isPast();
    }
}
