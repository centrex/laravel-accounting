<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\QuickBooksToken;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;

/**
 * QuickBooks Online connection status — only rendered when a client_id is configured,
 * so hosts that don't use QBO never see it. Lazy-loaded to match the other dashboard
 * cards; the token lookup is cheap but there's no reason to block initial paint on it.
 */
class AccountingQboStatusCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        $this->cacheTtl = 300;
    }

    /** @return array<string, mixed> */
    public function qboStatus(): array
    {
        return $this->rememberCache(
            $this->cacheKey('accounting', 'qbo-status-card'),
            function (): array {
                $configured = (string) config('accounting.quickbooks.client_id', '') !== '';

                if (!$configured) {
                    return ['configured' => false];
                }

                $realmId = (string) config('accounting.quickbooks.default_realm_id', '');
                $token = $realmId !== ''
                    ? QuickBooksToken::where('realm_id', $realmId)->first()
                    : QuickBooksToken::latest()->first();

                if (!$token) {
                    return [
                        'configured'  => true,
                        'connected'   => false,
                        'connect_url' => route('accounting.qbo.connect'),
                    ];
                }

                return [
                    'configured'         => true,
                    'connected'          => true,
                    'realm_id'           => $token->realm_id,
                    'access_expired'     => $token->isExpired(),
                    'refresh_expired'    => $token->isRefreshExpired(),
                    'expires_at'         => $token->expires_at,
                    'refresh_expires_at' => $token->refresh_token_expires_at,
                    'connect_url'        => route('accounting.qbo.connect'),
                ];
            },
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading" class="rounded-2xl border border-base-200 bg-base-100 h-full p-4 animate-pulse space-y-3">
                <div class="h-3 w-20 rounded bg-base-300"></div>
                <div class="h-6 w-28 rounded bg-base-300"></div>
                <div class="h-4 w-32 rounded bg-base-300"></div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('accounting::livewire.accounting-qbo-status-card', [
            'qboStatus' => $this->qboStatus(),
        ]);
    }
}
