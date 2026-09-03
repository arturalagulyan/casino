<?php

namespace App\Enums;

/**
 * Currencies a shop / player / bank can be denominated in.
 *
 * ← legacy VanguardLTE\Shop::$currencies / ::$values['currency'] (a fixed
 * <select> list). The legacy `w_users.currency` column was free-text and the
 * seamless-wallet launch call wrote it through unvalidated (live data has junk);
 * everything is funnelled through this enum now.
 *
 * No FX table yet — reports never sum across currencies (see
 * docs/BUSINESS-LOGIC-REVIEW.md, phase D).
 */
enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case AUD = 'AUD';
    case CAD = 'CAD';
    case NZD = 'NZD';
    case NOK = 'NOK';
    case SEK = 'SEK';
    case CHF = 'CHF';
    case ZAR = 'ZAR';
    case INR = 'INR';
    case RUB = 'RUB';
    case UAH = 'UAH';
    case GEL = 'GEL';
    case RON = 'RON';
    case HUF = 'HUF';
    case HRK = 'HRK';
    case BRL = 'BRL';
    case ARS = 'ARS';
    case MYR = 'MYR';
    case CNY = 'CNY';
    case JPY = 'JPY';
    case KRW = 'KRW';
    case IDR = 'IDR';
    case VND = 'VND';
    case THB = 'THB';
    case TND = 'TND';
    case KES = 'KES';
    case ALL = 'ALL';   // Albanian lek (legacy also wrote "LEK")
    case CFA = 'CFA';   // XOF/XAF francs (legacy free-text code)
    case BTC = 'BTC';
    case MBTC = 'mBTC'; // milli-bitcoin (legacy "mBTC")

    /** Human label for selects (plain text — no image). */
    public function label(): string
    {
        return "{$this->value} — {$this->currencyName()}";
    }

    /**
     * Flag + code as HTML, for Filament columns/entries with `->html()`.
     * Regional-indicator emoji don't render on Chrome/Windows, so the flag is a
     * real SVG served from flagcdn.com.
     */
    public function chip(): string
    {
        $flag = $this->flag();

        return ($flag !== '' ? $flag.' ' : '').e($this->value);
    }

    /** {@see chip()} tolerant of a raw string / null state (Filament column values). */
    public static function chipFor(self|string|null $currency): string
    {
        if ($currency === null || $currency === '') {
            return '—';
        }

        if ($currency instanceof self) {
            return $currency->chip();
        }

        return self::tryFrom($currency)?->chip() ?? e($currency);
    }

    /** ISO 3166-1 alpha-2 region for the flag, or null for supranational / crypto codes. */
    public function region(): ?string
    {
        return match ($this) {
            self::EUR => 'eu', self::USD => 'us', self::GBP => 'gb', self::AUD => 'au',
            self::CAD => 'ca', self::NZD => 'nz', self::NOK => 'no', self::SEK => 'se',
            self::CHF => 'ch', self::ZAR => 'za', self::INR => 'in', self::RUB => 'ru',
            self::UAH => 'ua', self::GEL => 'ge', self::RON => 'ro', self::HUF => 'hu',
            self::HRK => 'hr', self::BRL => 'br', self::ARS => 'ar', self::MYR => 'my',
            self::CNY => 'cn', self::JPY => 'jp', self::KRW => 'kr', self::IDR => 'id',
            self::VND => 'vn', self::THB => 'th', self::TND => 'tn', self::KES => 'ke',
            self::ALL => 'al',
            self::CFA, self::BTC, self::MBTC => null,
        };
    }

    /** An `<img>` of the flag (SVG, ~1em tall), or '' for codes with no country. */
    public function flag(): string
    {
        $region = $this->region();

        if ($region === null) {
            return '';
        }

        return sprintf(
            '<img src="https://flagcdn.com/%s.svg" alt="%s" '.
            'style="display:inline-block;width:auto;height:0.9em;margin-bottom:0.1em;'.
            'vertical-align:middle;border-radius:2px">',
            $region,
            e($this->value),
        );
    }

    public function currencyName(): string
    {
        return match ($this) {
            self::EUR => 'Euro',
            self::USD => 'US Dollar',
            self::GBP => 'Pound Sterling',
            self::AUD => 'Australian Dollar',
            self::CAD => 'Canadian Dollar',
            self::NZD => 'New Zealand Dollar',
            self::NOK => 'Norwegian Krone',
            self::SEK => 'Swedish Krona',
            self::CHF => 'Swiss Franc',
            self::ZAR => 'South African Rand',
            self::INR => 'Indian Rupee',
            self::RUB => 'Russian Ruble',
            self::UAH => 'Ukrainian Hryvnia',
            self::GEL => 'Georgian Lari',
            self::RON => 'Romanian Leu',
            self::HUF => 'Hungarian Forint',
            self::HRK => 'Croatian Kuna',
            self::BRL => 'Brazilian Real',
            self::ARS => 'Argentine Peso',
            self::MYR => 'Malaysian Ringgit',
            self::CNY => 'Chinese Yuan',
            self::JPY => 'Japanese Yen',
            self::KRW => 'South Korean Won',
            self::IDR => 'Indonesian Rupiah',
            self::VND => 'Vietnamese Dong',
            self::THB => 'Thai Baht',
            self::TND => 'Tunisian Dinar',
            self::KES => 'Kenyan Shilling',
            self::ALL => 'Albanian Lek',
            self::CFA => 'CFA Franc',
            self::BTC => 'Bitcoin',
            self::MBTC => 'Milli-Bitcoin',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::EUR => '€',
            self::USD, self::AUD, self::CAD, self::NZD, self::ARS => '$',
            self::GBP => '£',
            self::JPY, self::CNY => '¥',
            self::KRW => '₩',
            self::INR => '₹',
            self::RUB => '₽',
            self::UAH => '₴',
            self::THB => '฿',
            self::VND => '₫',
            self::BRL => 'R$',
            self::ZAR => 'R',
            self::NOK, self::SEK => 'kr',
            self::CHF => 'Fr',
            self::BTC => '₿',
            self::MBTC => 'm₿',
            default => $this->value,
        };
    }

    /** Minor units — drives money formatting / rounding. */
    public function decimals(): int
    {
        return match ($this) {
            self::JPY, self::KRW, self::IDR, self::VND, self::HUF, self::CFA => 0,
            self::BTC => 8,
            self::MBTC => 5,
            default => 2,
        };
    }

    public function isCrypto(): bool
    {
        return in_array($this, [self::BTC, self::MBTC], true);
    }

    /** Smallest representable amount (10^-decimals) — the rounding floor for FX-scaled bets. */
    public function minorUnit(): float
    {
        return 10 ** -$this->decimals();
    }

    /** [value => label] for Filament selects / filters. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }

    public static function default(): self
    {
        return self::EUR;
    }
}
