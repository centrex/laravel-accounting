# Manage accounts in laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/centrex/laravel-accounting.svg?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/centrex/laravel-accounting?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)

This package to provide a simple drop-in trait to manage accruing balances for a given model. It can also be used to create double entry based projects where you would want to credit one journal and debit another.

## Contents

-   [Installation](#installation)
-   [Usage](#usage)
-   [Testing](#testing)
-   [Changelog](#changelog)
-   [Contributing](#contributing)
-   [Credits](#credits)
-   [License](#license)

## Installation

You can install the package via composer:

```bash
composer require centrex/laravel-accounting
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="accounting-migrations"
php artisan migrate
```

You can optionally publish the config file with:

```bash
php artisan vendor:publish --tag="accounting-config"
```

## Usage

// Standard equation of accounting system

| Dividend + Expenses + Asset | Liabilities + Owner's Equity + Revenue |
| --------------------------- | -------------------------------------- |
| Dividend                    | Liabilities                            |
| Expense                     | Owner's Equity                         |
| Asset                       | Revenue                                |

// Standard accounting statements
```
'statements' => [
    'balance_sheet' => [
        'accumulated' => true,
        'name' => 'Balance Sheet',
        'cash_only' => false,
        'accounts' => ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense']
    ],
    'income' => [
        'name' => 'Income',
        'cash_only' => true,
        'accounts' => ['Revenue', 'Expense', 'Other',]
    ],
    'cash_flow' => [
        'name' => 'Cash Flow',
        'cash_only' => true,
        'accounts' => ['Asset']
    ]
],
```

## Testing

🧹 Keep a modern codebase with **Pint**:

```bash
composer lint
```

✅ Run refactors using **Rector**

```bash
composer refacto
```

⚗️ Run static analysis using **PHPStan**:

```bash
composer test:types
```

✅ Run unit tests using **PEST**

```bash
composer test:unit
```

🚀 Run the entire test suite:

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

-   [centrex](https://github.com/centrex)
-   [All Contributors](../../contributors)
-   [scottlaurent/accounting](https://github.com/scottlaurent/accounting)
-   [consilience/accounting](https://github.com/consilience/accounting)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
