<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Scopes;

use Centrex\Accounting\Support\TenantContext;
use Illuminate\Database\Eloquent\{Builder, Model, Scope};

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! config('accounting.tenant.enabled', true)) {
            return;
        }

        $tenantId = TenantContext::get();

        if ($tenantId !== null) {
            $column = config('accounting.tenant.column', 'tenant_id');
            $builder->where($model->getTable() . '.' . $column, $tenantId);
        }
    }
}
