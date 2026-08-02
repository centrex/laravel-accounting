# Known Issues — laravel-accounting

_Last checked: 2026-08-02_

## Failing tests

- **`tests/Feature/LoanFacilityEditLivewireTest.php`** — "editing a facility with a posted drawdown locks financial fields" fails with:
  ```
  RuntimeException: Loan facility 'Active Lender' is inactive.
  ```
  thrown from `src/Accounting.php:2623` (`accrueLoanInterest()`/`drawdownLoan()`-style guard on `$facility->is_active`). The test drives the `LoanFacilityEdit` Livewire component through an edit that ends up calling a facility-mutating method on a facility the test has (intentionally or not) left/flipped to inactive. Needs investigation into whether the test fixture is wrong or the component is calling the wrong method for an edit-only flow.

- **`tests/Feature/BillsLivewirePaymentTest.php`** — "bill payment …" fails with:
  ```
  Call to undefined method Centrex\TallUi\DataTable\Column::currency()
  ```
  from `src/Livewire/BillTable.php:38` (`Column::make('Total', 'base_total')->currency($currency)`). **Root cause confirmed**: `Column::currency()` does exist in the `tallui` submodule's current source (`tallui/src/DataTable/Column.php`, added 2026-07-11 as a shorthand for `format('currency:CODE')`) — the method isn't missing from the API, it's missing from _this package's installed copy_. `laravel-accounting`'s own `vendor/centrex/tallui` is pinned to a release that predates that commit, i.e. **`composer.json`'s `centrex/tallui: "*"` constraint here resolved to a stale version.** Fix by running `composer update centrex/tallui` (or otherwise bumping the locked version) rather than changing `BillTable.php` — the call site is correct against current tallui.

  Both failures pre-date this session's Excel-export work (confirmed via `git stash` against `main`) and are unrelated to it.

## Style / static-analysis debt (not currently failing CI, but notable)

- `vendor/bin/pint --test` reports **~46 files** with unapplied fixers (mostly `ordered_traits`, `binary_operator_spaces`, `new_with_braces`/`new_with_parentheses`) across `src/Models/`, `src/Livewire/`, `src/Enums/`, `src/Http/`, and several `database/migrations/*` and `tests/*` files. Run `composer lint` to apply.
- `vendor/bin/rector --dry-run` reports **88 files** with pending refactors (mostly `RemoveUnusedPrivateMethodParameterRector`, `FlipTypeControlToUseExclusiveTypeRector`, `UnwrapSprintfOneArgumentRector`, and dead/early-return cleanups). Run `composer refacto` to apply.
- PHPStan (`level: max`) currently passes clean, but only because `phpstan-baseline.neon` (~2,260 baselined errors) suppresses a large number of pre-existing `mixed`-type issues across the codebase — most from methods that return loosely-typed `array` report/DTO shapes (e.g. `Accounting::getTrialBalance()`, `getBalanceSheet()`, etc.) and are consumed without narrowing. The baseline hides real issues from being *newly* caught; it does not mean the flagged code is type-safe.

## TODO / FIXME markers

None found (`grep -rn "TODO\|FIXME" src/ config/ database/` — no matches).

## Open GitHub issues

Not checked — the `gh` CLI is not installed in this environment, so `centrex/laravel-accounting`'s issue tracker could not be queried. Re-run `gh issue list --repo centrex/laravel-accounting --state open` once `gh` is available and authenticated.
