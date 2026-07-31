# COGS Issues — Accounting-Side Troubleshooting

Everything on this page is about what happens **once a COGS number reaches the ledger** — the
journal entry itself, its linkage to revenue, period locking, and why two different reports can
legitimately disagree. If the *number itself* looks wrong before it was ever posted (bad WAC,
wrong receipt cost, a shipment's landed-cost split), start with
[laravel-inventory's cogs-scenarios.md](../../laravel-inventory/docs/cogs-scenarios.md) instead —
that's where COGS is actually computed.

## How COGS reaches the ledger

Every fulfillment posts a fixed two-line journal entry via
`ErpIntegration::postSaleFulfillment()`:

```
DR Cost of Goods Sold (5000)   ৳X
CR Inventory (1300)            ৳X
```

tagged with `source_type = SaleOrder::class`, `source_id = $saleOrder->id`,
`source_action = 'sale_fulfillment'` — that triple is how every COGS-recalculation tool finds
"the COGS entry (or entries) for this order." A partial fulfillment (an order shipped in more
than one batch) posts **one JE per batch**, each with its own sequence suffix
(`SO-00001-FUL-01`, `-FUL-02`, …) — there is no single "the COGS JE" for an order with a partial
fulfillment history; see [§3](#3-an-order-with-multiple-fulfillment-jes-cant-be-auto-corrected).

`SaleOrder.cogs_amount` and the posted JE line amount are **two independent numbers** that
happen to agree at posting time — see [§5](#5-two-sources-of-truth-that-can-drift-apart) for why
that matters.

## Symptom → likely cause

| Symptom | Likely cause | Jump to |
| --- | --- | --- |
| COGS is higher than revenue on the Income Statement for a period/order | Orphaned COGS entry — no matching posted revenue | [§1](#1-orphaned-cogs-no-matching-revenue) |
| A migrated/imported order shows revenue but $0 COGS | COGS was never posted for that order | [§2](#2-missing-cogs-the-inverse-of-1) |
| A correction command says "N posted fulfillment journal entries found — not supported" | The order was fulfilled in more than one batch | [§3](#3-an-order-with-multiple-fulfillment-jes-cant-be-auto-corrected) |
| A COGS fix command skipped a line with "accounting period is closed" | The JE's date falls inside a closed `FiscalPeriod` | [§4](#4-period-lock-blocks-the-correction-you-want-to-make) |
| The inventory dashboard and the Income Statement disagree on COGS for the same order | `SaleOrder.cogs_amount` and the JE line drifted apart after a manual edit | [§5](#5-two-sources-of-truth-that-can-drift-apart) |
| Voiding a COGS entry didn't change what the inventory dashboard shows | `JournalEntry::void()` never touches `SaleOrder.cogs_amount` | [§5](#5-two-sources-of-truth-that-can-drift-apart) |
| `accounting:recalculate-cogs` corrected a JE's *amount* but you expected a new JE | This tool deliberately mutates the posted JE's lines in place | [§6](#6-in-place-correction-vs-void--repost) |

---

## 1. Orphaned COGS (no matching revenue)

**Mechanism:** a COGS JE (`source_action = 'sale_fulfillment'`) exists and is `posted`, but the
order's invoice never got its own revenue JE posted (still `draft`, checkout abandoned,
`postInvoice()` failed silently, etc.). The Income Statement sums COGS from every posted JE in
the period regardless of whether a matching revenue JE exists — so this order contributes real
cost with **zero** offsetting revenue, and can single-handedly push COGS above total revenue for
the period.

**Diagnose:**
```bash
php artisan accounting:backfill-cogs --void-orphaned --dry-run
```

**Fix:** drop `--dry-run` to void the orphaned entries (`JournalEntry::void()` — status only,
does **not** reverse the GL balance the way a compensating entry would; see
[journal-entries.md](journal-entries.md#void-a-posted-entry)). Then chase *why* the invoice
never posted — a recurring pattern here usually means a checkout/fulfillment integration bug, not
a one-off.

**Note:** voiding does not reset `SaleOrder.cogs_amount` — see §5.

---

## 2. Missing COGS (the inverse of §1)

**Mechanism:** revenue was posted (the invoice is `issued`/`settled` with a real JE), but no
`sale_fulfillment` JE exists at all — `ErpIntegration::postSaleFulfillment()` was never called
for that order. This is the classic signature of bulk-migrated/imported historical orders that
were inserted directly into the database rather than run through
`Inventory::fulfillSaleOrder()`. Net effect on any margin report: revenue with no cost, i.e. an
**overstated** margin — the opposite direction from §1, but the same root defect (COGS/revenue
linkage never established).

**Diagnose:**
```bash
php artisan accounting:backfill-cogs --so=SO-00001 --dry-run    # single order
php artisan accounting:backfill-cogs --dry-run                  # everything eligible
```
It only considers orders that already have a **posted revenue JE** — deliberately, so COGS and
Revenue always land in the same accounting period; an order with no revenue JE at all is out of
scope for this command (nothing to pair the new COGS against yet).

**Fix:** drop `--dry-run`. It computes COGS from **current** WAC — for old/migrated data this is
an approximation, not the WAC that was actually in effect at the historical fulfillment date;
acceptable for closing a data gap, not for a precise historical restatement.

---

## 3. An order with multiple fulfillment JEs can't be auto-corrected

**Mechanism:** `accounting:recalculate-cogs` only touches an order with **exactly one** posted
`sale_fulfillment` JE. An order fulfilled in two batches at two different WACs has two JEs, each
representing an incremental slice — there's no well-defined way to re-derive "what the combined
COGS *should* be now" and reallocate it back across two already-posted, independently-dated
entries without picking an arbitrary allocation rule. Rather than guess, the command skips the
whole order and reports the JE count.

**Fix:** reconcile manually. Decide (with the same reasoning a human accountant would apply)
whether the correction belongs on the first slice, the last slice, or split — then edit the
specific `JournalEntryLine` amounts and `SaleOrder.cogs_amount` yourself, in a `DB::transaction()`,
the same way `accounting:recalculate-cogs` does it internally (see its source for the exact
pattern — updating both the JE lines and `SaleOrder.cogs_amount` together, never one without the
other).

---

## 4. Period lock blocks the correction you want to make

**Mechanism:** `JournalEntry::post()` refuses to post into a `FiscalPeriod` with
`is_closed = true` unless `bypassPeriodLock: true` is passed. Both COGS-correction commands
(`accounting:recalculate-cogs`, `inventory:recalculate-shipment`) apply the same rule to
*their own* corrections — a line whose existing COGS JE falls inside a closed period is skipped
and reported, not force-corrected.

There is **no `reopenFiscalPeriod()`** in the facade — closing is meant to be close to one-way.
Directly toggling `$period->update(['is_closed' => false])` bypasses whatever safety
`closeFiscalPeriod()` was providing (the GL snapshot it took at close time doesn't get
invalidated or retaken) — treat it as a last resort, and re-run `getPeriodCloseChecks()` /
re-snapshot afterward if you do.

**Fix, if you're sure the correction is warranted:** either wait until the current period closes
and let the correction land there instead (cleanest — no history rewriting), or reopen
deliberately, make the correction, and re-close with a fresh snapshot.

---

## 5. Two sources of truth that can drift apart

**Mechanism:** `SaleOrder.cogs_amount` (read by the inventory dashboard, `grossProfitAmount()`,
`SalesOrderProfitSummary`) and the posted JE's line amount (read by
`Accounting::getIncomeStatement()` and every other ledger-based report) are **two separate
columns in two separate packages**, kept in sync only by the code paths that know to update both
at once (`Inventory::fulfillSaleOrder()`, `accounting:recalculate-cogs`). Anything that touches
only one side — `JournalEntry::void()` called directly, a manual `SaleOrder::update(['cogs_amount' => ...])`,
a raw DB fix — leaves the other stale.

**Diagnose:** for a given order, compare
```php
$so->cogs_amount;
```
against
```php
JournalEntry::where('source_type', SaleOrder::class)
    ->where('source_id', $so->id)
    ->where('source_action', 'sale_fulfillment')
    ->where('status', 'posted')
    ->with('lines')
    ->get()
    ->flatMap->lines
    ->where('type', 'debit')
    ->sum('amount');
```
If these disagree, something touched one side without the other.

**Fix:** whichever tool or manual step you use to correct COGS, always update both together in
the same transaction — never void/edit a JE without also reconciling `SaleOrder.cogs_amount`, and
never edit `SaleOrder.cogs_amount` without a matching JE correction.

---

## 6. In-place correction vs. void + repost

Every other correction tool in this ecosystem (`voidStockReceipt()`,
`Inventory::recalculateShipmentLanding()`) follows the same convention: **never mutate a posted
record — void it and post a new one**, so the audit trail shows both the original and the
correction. `accounting:recalculate-cogs` is a deliberate, explicitly-requested exception: it
edits the posted JE's two line amounts **in place** rather than voiding and reposting.

This is only safe under the narrow conditions the command already enforces (exactly one JE,
exactly two lines, the exact `[debit COGS, credit Inventory]` account pair, an open period) — if
you're writing a new tool against this data, default to void + repost like everything else
unless you have an equally good reason not to, and document the exception as clearly as this one
does.

---

## Related tooling

| Command | What it fixes | Lives in |
| --- | --- | --- |
| `accounting:backfill-cogs` | Posts *missing* COGS JEs for orders with posted revenue but no fulfillment JE | `laravel-erp` |
| `accounting:backfill-cogs --void-orphaned` | Voids COGS JEs that have no matching posted revenue | `laravel-erp` |
| `accounting:recalculate-cogs` | Re-derives COGS from *current* WAC for already-posted, single-JE fulfillments; updates `SaleOrder.cogs_amount` and the JE lines together | `laravel-erp` |
| `inventory:recalculate-shipment {id}` | Fixes the WAC a shipment fed into destination stock; chains into `accounting:recalculate-cogs` for affected orders | `laravel-erp` |

All four support `--dry-run`. See the
[inventory-side guide](../../laravel-inventory/docs/cogs-scenarios.md) for how a bad WAC/landed
cost gets into the system in the first place.
