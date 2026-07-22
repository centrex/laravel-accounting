<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class BankStatementLine extends Model implements Auditable
{
    use AuditableTrait;
    use AddTablePrefix;
    use HasFactory;

    protected function getTableSuffix(): string
    {
        return 'bank_statement_lines';
    }

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'bank_reconciliation_id', 'transaction_date', 'description', 'amount', 'type',
        'external_reference', 'matched_journal_entry_line_id', 'matched_at',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount'            => 'decimal:2',
        'matched_at'        => 'datetime',
    ];

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function matchedJournalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'matched_journal_entry_line_id');
    }
}
