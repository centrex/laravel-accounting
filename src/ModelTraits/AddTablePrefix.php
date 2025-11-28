<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\ModelTraits;
/**
 * A model that has accounting table with prefix.
 */
trait AddTablePrefix
{
    public function getTable()
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        return $prefix . $this->getTableSuffix();
    }
    
    abstract protected function getTableSuffix();
}