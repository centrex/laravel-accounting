<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

use Centrex\Accounting\Concerns\EnumHelpers;

/**
 * ISO 4217 Currency codes.
 *
 * Each case value is the 3-letter alphabetic code.
 * Methods provide the numeric code, symbol, decimal precision, and display label.
 */
enum Currency: string
{
    use EnumHelpers;

    // ── Major / Most-used ───────────────────────────────────────────────────
    case USD = 'USD'; // US Dollar
    case EUR = 'EUR'; // Euro
    case GBP = 'GBP'; // British Pound Sterling
    case JPY = 'JPY'; // Japanese Yen
    case CNY = 'CNY'; // Chinese Yuan Renminbi
    case CHF = 'CHF'; // Swiss Franc
    case CAD = 'CAD'; // Canadian Dollar
    case AUD = 'AUD'; // Australian Dollar
    case NZD = 'NZD'; // New Zealand Dollar
    case HKD = 'HKD'; // Hong Kong Dollar
    case SGD = 'SGD'; // Singapore Dollar
    case SEK = 'SEK'; // Swedish Krona
    case NOK = 'NOK'; // Norwegian Krone
    case DKK = 'DKK'; // Danish Krone
    case KRW = 'KRW'; // South Korean Won
    case INR = 'INR'; // Indian Rupee
    case BDT = 'BDT'; // Bangladeshi Taka
    case PKR = 'PKR'; // Pakistani Rupee
    case LKR = 'LKR'; // Sri Lankan Rupee
    case NPR = 'NPR'; // Nepalese Rupee
    case MYR = 'MYR'; // Malaysian Ringgit
    case THB = 'THB'; // Thai Baht
    case IDR = 'IDR'; // Indonesian Rupiah
    case PHP = 'PHP'; // Philippine Peso
    case VND = 'VND'; // Vietnamese Dong
    case TWD = 'TWD'; // New Taiwan Dollar

    // ── Middle East & Africa ────────────────────────────────────────────────
    case AED = 'AED'; // UAE Dirham
    case SAR = 'SAR'; // Saudi Riyal
    case QAR = 'QAR'; // Qatari Riyal
    case KWD = 'KWD'; // Kuwaiti Dinar
    case BHD = 'BHD'; // Bahraini Dinar
    case OMR = 'OMR'; // Omani Rial
    case JOD = 'JOD'; // Jordanian Dinar
    case EGP = 'EGP'; // Egyptian Pound
    case TRY = 'TRY'; // Turkish Lira
    case ILS = 'ILS'; // Israeli New Shekel
    case NGN = 'NGN'; // Nigerian Naira
    case ZAR = 'ZAR'; // South African Rand
    case KES = 'KES'; // Kenyan Shilling
    case GHS = 'GHS'; // Ghanaian Cedi
    case ETB = 'ETB'; // Ethiopian Birr
    case TZS = 'TZS'; // Tanzanian Shilling
    case UGX = 'UGX'; // Ugandan Shilling
    case MAD = 'MAD'; // Moroccan Dirham
    case DZD = 'DZD'; // Algerian Dinar
    case TND = 'TND'; // Tunisian Dinar

    // ── Europe (non-Euro) ───────────────────────────────────────────────────
    case PLN = 'PLN'; // Polish Zloty
    case CZK = 'CZK'; // Czech Koruna
    case HUF = 'HUF'; // Hungarian Forint
    case RON = 'RON'; // Romanian Leu
    case BGN = 'BGN'; // Bulgarian Lev
    case HRK = 'HRK'; // Croatian Kuna
    case RSD = 'RSD'; // Serbian Dinar
    case RUB = 'RUB'; // Russian Ruble
    case UAH = 'UAH'; // Ukrainian Hryvnia

    // ── Americas ────────────────────────────────────────────────────────────
    case MXN = 'MXN'; // Mexican Peso
    case BRL = 'BRL'; // Brazilian Real
    case ARS = 'ARS'; // Argentine Peso
    case CLP = 'CLP'; // Chilean Peso
    case COP = 'COP'; // Colombian Peso
    case PEN = 'PEN'; // Peruvian Sol
    case UYU = 'UYU'; // Uruguayan Peso
    case BOB = 'BOB'; // Bolivian Boliviano

    // ── Oceania / Pacific ───────────────────────────────────────────────────
    case FJD = 'FJD'; // Fijian Dollar
    case PGK = 'PGK'; // Papua New Guinean Kina

    // ── Other notable ───────────────────────────────────────────────────────
    case XOF = 'XOF'; // West African CFA Franc
    case XAF = 'XAF'; // Central African CFA Franc

    // ─────────────────────────────────────────────────────────────────────────

    /** ISO 4217 numeric code. */
    public function numericCode(): int
    {
        return match ($this) {
            self::USD => 840,
            self::EUR => 978,
            self::GBP => 826,
            self::JPY => 392,
            self::CNY => 156,
            self::CHF => 756,
            self::CAD => 124,
            self::AUD => 36,
            self::NZD => 554,
            self::HKD => 344,
            self::SGD => 702,
            self::SEK => 752,
            self::NOK => 578,
            self::DKK => 208,
            self::KRW => 410,
            self::INR => 356,
            self::BDT => 50,
            self::PKR => 586,
            self::LKR => 144,
            self::NPR => 524,
            self::MYR => 458,
            self::THB => 764,
            self::IDR => 360,
            self::PHP => 608,
            self::VND => 704,
            self::TWD => 901,
            self::AED => 784,
            self::SAR => 682,
            self::QAR => 634,
            self::KWD => 414,
            self::BHD => 48,
            self::OMR => 512,
            self::JOD => 400,
            self::EGP => 818,
            self::TRY => 949,
            self::ILS => 376,
            self::NGN => 566,
            self::ZAR => 710,
            self::KES => 404,
            self::GHS => 936,
            self::ETB => 230,
            self::TZS => 834,
            self::UGX => 800,
            self::MAD => 504,
            self::DZD => 12,
            self::TND => 788,
            self::PLN => 985,
            self::CZK => 203,
            self::HUF => 348,
            self::RON => 946,
            self::BGN => 975,
            self::HRK => 191,
            self::RSD => 941,
            self::RUB => 643,
            self::UAH => 980,
            self::MXN => 484,
            self::BRL => 986,
            self::ARS => 32,
            self::CLP => 152,
            self::COP => 170,
            self::PEN => 604,
            self::UYU => 858,
            self::BOB => 68,
            self::FJD => 242,
            self::PGK => 598,
            self::XOF => 952,
            self::XAF => 950,
        };
    }

    /** Commonly used symbol for the currency. */
    public function symbol(): string
    {
        return match ($this) {
            self::USD => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::JPY => '¥',
            self::CNY => '¥',
            self::CHF => 'Fr',
            self::CAD => 'CA$',
            self::AUD => 'A$',
            self::NZD => 'NZ$',
            self::HKD => 'HK$',
            self::SGD => 'S$',
            self::SEK => 'kr',
            self::NOK => 'kr',
            self::DKK => 'kr',
            self::KRW => '₩',
            self::INR => '₹',
            self::BDT => '৳',
            self::PKR => '₨',
            self::LKR => '₨',
            self::NPR => '₨',
            self::MYR => 'RM',
            self::THB => '฿',
            self::IDR => 'Rp',
            self::PHP => '₱',
            self::VND => '₫',
            self::TWD => 'NT$',
            self::AED => 'د.إ',
            self::SAR => '﷼',
            self::QAR => '﷼',
            self::KWD => 'د.ك',
            self::BHD => '.د.ب',
            self::OMR => '﷼',
            self::JOD => 'JD',
            self::EGP => '£',
            self::TRY => '₺',
            self::ILS => '₪',
            self::NGN => '₦',
            self::ZAR => 'R',
            self::KES => 'KSh',
            self::GHS => 'GH₵',
            self::ETB => 'Br',
            self::TZS => 'TSh',
            self::UGX => 'USh',
            self::MAD => 'MAD',
            self::DZD => 'DZD',
            self::TND => 'DT',
            self::PLN => 'zł',
            self::CZK => 'Kč',
            self::HUF => 'Ft',
            self::RON => 'lei',
            self::BGN => 'лв',
            self::HRK => 'kn',
            self::RSD => 'din',
            self::RUB => '₽',
            self::UAH => '₴',
            self::MXN => 'MX$',
            self::BRL => 'R$',
            self::ARS => '$',
            self::CLP => '$',
            self::COP => '$',
            self::PEN => 'S/',
            self::UYU => '$U',
            self::BOB => 'Bs.',
            self::FJD => 'FJ$',
            self::PGK => 'K',
            self::XOF, self::XAF => 'CFA',
        };
    }

    /**
     * Number of decimal places for the currency (ISO 4217 minor unit).
     * 0 = no decimals (JPY, KRW, …), 3 = three decimals (KWD, BHD, OMR, TND, JOD).
     */
    public function decimalPlaces(): int
    {
        return match ($this) {
            self::JPY, self::KRW, self::VND, self::IDR,
            self::CLP, self::UGX, self::XOF, self::XAF => 0,
            self::KWD, self::BHD, self::OMR, self::TND, self::JOD => 3,
            default => 2,
        };
    }

    /** Human-readable label: "USD – US Dollar ($)". */
    public function label(): string
    {
        return "{$this->value} – {$this->getName()} ({$this->symbol()})";
    }

    /** Format a numeric amount using this currency's symbol and decimal places. */
    public function format(float|int $amount): string
    {
        return $this->symbol() . ' ' . number_format($amount, $this->decimalPlaces());
    }

    /** Return the Currency instance for the app's configured base currency, or USD as fallback. */
    public static function default(): self
    {
        $code = strtoupper((string) config('accounting.base_currency', 'USD'));

        return self::tryFrom($code) ?? self::USD;
    }

    /** All cases as [value => label] for use in select options. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** Override EnumHelpers::getName to return the full currency name. */
    public function getName(): string
    {
        return match ($this) {
            self::USD => 'US Dollar',
            self::EUR => 'Euro',
            self::GBP => 'British Pound Sterling',
            self::JPY => 'Japanese Yen',
            self::CNY => 'Chinese Yuan Renminbi',
            self::CHF => 'Swiss Franc',
            self::CAD => 'Canadian Dollar',
            self::AUD => 'Australian Dollar',
            self::NZD => 'New Zealand Dollar',
            self::HKD => 'Hong Kong Dollar',
            self::SGD => 'Singapore Dollar',
            self::SEK => 'Swedish Krona',
            self::NOK => 'Norwegian Krone',
            self::DKK => 'Danish Krone',
            self::KRW => 'South Korean Won',
            self::INR => 'Indian Rupee',
            self::BDT => 'Bangladeshi Taka',
            self::PKR => 'Pakistani Rupee',
            self::LKR => 'Sri Lankan Rupee',
            self::NPR => 'Nepalese Rupee',
            self::MYR => 'Malaysian Ringgit',
            self::THB => 'Thai Baht',
            self::IDR => 'Indonesian Rupiah',
            self::PHP => 'Philippine Peso',
            self::VND => 'Vietnamese Dong',
            self::TWD => 'New Taiwan Dollar',
            self::AED => 'UAE Dirham',
            self::SAR => 'Saudi Riyal',
            self::QAR => 'Qatari Riyal',
            self::KWD => 'Kuwaiti Dinar',
            self::BHD => 'Bahraini Dinar',
            self::OMR => 'Omani Rial',
            self::JOD => 'Jordanian Dinar',
            self::EGP => 'Egyptian Pound',
            self::TRY => 'Turkish Lira',
            self::ILS => 'Israeli New Shekel',
            self::NGN => 'Nigerian Naira',
            self::ZAR => 'South African Rand',
            self::KES => 'Kenyan Shilling',
            self::GHS => 'Ghanaian Cedi',
            self::ETB => 'Ethiopian Birr',
            self::TZS => 'Tanzanian Shilling',
            self::UGX => 'Ugandan Shilling',
            self::MAD => 'Moroccan Dirham',
            self::DZD => 'Algerian Dinar',
            self::TND => 'Tunisian Dinar',
            self::PLN => 'Polish Zloty',
            self::CZK => 'Czech Koruna',
            self::HUF => 'Hungarian Forint',
            self::RON => 'Romanian Leu',
            self::BGN => 'Bulgarian Lev',
            self::HRK => 'Croatian Kuna',
            self::RSD => 'Serbian Dinar',
            self::RUB => 'Russian Ruble',
            self::UAH => 'Ukrainian Hryvnia',
            self::MXN => 'Mexican Peso',
            self::BRL => 'Brazilian Real',
            self::ARS => 'Argentine Peso',
            self::CLP => 'Chilean Peso',
            self::COP => 'Colombian Peso',
            self::PEN => 'Peruvian Sol',
            self::UYU => 'Uruguayan Peso',
            self::BOB => 'Bolivian Boliviano',
            self::FJD => 'Fijian Dollar',
            self::PGK => 'Papua New Guinean Kina',
            self::XOF => 'West African CFA Franc',
            self::XAF => 'Central African CFA Franc',
        };
    }
}
