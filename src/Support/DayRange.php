<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Half-open day bounds for matching a single calendar day on a date column.
 *
 * whereDate() is correct but not sargable — it compiles to `date(payment_date) = ?`, which
 * wraps the column in a function and so discards its index.
 *
 * A plain equality comparison is not a safe replacement either. These columns are declared
 * `date` in the schema but cast to `date` on the model, so what is actually stored depends
 * on the driver: MySQL keeps a bare DATE, while SQLite stores whatever the model serialises,
 * which is `Y-m-d H:i:s`. Comparing `payment_date = '2025-06-01'` therefore matches on one
 * driver and silently matches nothing on the other.
 *
 * Bounding the day from both sides is correct under either storage format and still lets the
 * index serve the range.
 */
final class DayRange
{
    /**
     * Constrain $column to the single calendar day containing $date.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function onDay(Builder $query, string $column, mixed $date): Builder
    {
        $start = self::parse($date)->startOfDay();

        return $query
            ->where($column, '>=', $start)
            ->where($column, '<', $start->copy()->addDay());
    }

    /**
     * Inclusive-day upper bound, expressed exclusively: midnight starting the day after
     * $date. Pair with `<`, never `<=`.
     *
     * `where('date', '<=', '2025-12-31')` drops everything recorded on the 31st itself
     * wherever the stored value carries a time component, which for a date-cast attribute
     * depends on the driver. Comparing against the next midnight includes the whole day
     * either way.
     */
    public static function endOfDay(mixed $date): Carbon
    {
        return self::parse($date)->startOfDay()->addDay();
    }

    /** Inclusive-day lower bound: midnight starting $date. Pair with `>=`. */
    public static function startOfDay(mixed $date): Carbon
    {
        return self::parse($date)->startOfDay();
    }

    private static function parse(mixed $date): Carbon
    {
        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date);
        }

        return Carbon::parse(is_scalar($date) ? (string) $date : null);
    }
}
