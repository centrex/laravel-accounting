<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Casts\{CurrencyCast, MoneyCast};
use Centrex\LaravelAccounting\ModelTraits\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\{Carbon, Str};
use Money\{Currency, Money};

/**
 * @property string $id
 * @property string $journal_id
 * @property Money|null $credit
 * @property Money|null $debit
 * @property Money $amount returns credit or (debit)
 * @property Currency $currency
 * @property string $currency_code ISO 4217
 * @property string|null $memo
 * @property array $tags
 * @property Journal $journal
 * @property Carbon $post_date
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 */
class JournalTransaction extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix()
    {
        return 'journal_transactions';
    }

    /** @var bool` */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var array */
    protected $guarded = ['id'];

    /** @var array */
    protected $casts = [
        'post_date' => 'datetime',
        'tags'      => 'array',
        'currency'  => CurrencyCast::class . ':currency_code',
        'credit'    => MoneyCast::class . ':currency_code,credit',
        'debit'     => MoneyCast::class . ':currency_code,debit',
    ];

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection'));
    }

    /** Boot. */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $transaction): void {
            $transaction->id = (string) Str::orderedUuid();
        });

        // @todo make these asynchronous so the balance can be updated in the background,
        // and event driven so it can be used or not.
        // The job can also be set up to queue only if a copy of the job is not already
        // queued for the same journal.

        static::saved(function (self $transaction): void {
            $transaction->journal->resetCurrentBalance();
        });

        static::deleted(function (self $transaction): void {
            $transaction->journal->resetCurrentBalance();
        });
    }

    /** Journal relation. */
    public function journal()
    {
        return $this->belongsTo(config('accounting.model-classes.journal'));
    }

    /**
     * Set reference object.
     *
     * @deprecated use the reference relation instead.
     *
     * @param  Model  $object
     */
    public function referencesObject($object): static
    {
        $this->reference_type = $object->getMorphClass();
        $this->reference_id = $object->id;
        $this->save();

        return $this;
    }

    /**
     * Reference the related object as a polymorphic relation.
     *
     * To associate a model with a transaction, use:
     *      $transaction->reference()->associate($model);
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The amount, either credit or (debit).
     */
    public function getAmountAttribute(): Money
    {
        if ($this->attributes['credit'] !== null) {
            return $this->credit;
        }

        if ($this->attributes['debit'] !== null) {
            return $this->debit->multiply(-1);
        }

        return new Money(0, $this->currency);
    }
}
