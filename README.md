# Manage accounts in laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/centrex/laravel-accounting.svg?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-accounting/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/centrex/laravel-accounting/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/centrex/laravel-accounting?style=flat-square)](https://packagist.org/packages/centrex/laravel-accounting)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require centrex/laravel-accounting
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-accounting-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-accounting-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-accounting-views"
```

## Usage

```php
$laravelAccounting = new Centrex\LaravelAccounting();
echo $laravelAccounting->echoPhrase('Hello, Centrex!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [centrex](https://github.com/centrex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
