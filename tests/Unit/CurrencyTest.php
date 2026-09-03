<?php

namespace Tests\Unit;

use App\Enums\Currency;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    public function test_country_currencies_render_a_flag_image(): void
    {
        $this->assertStringContainsString('https://flagcdn.com/al.svg', Currency::ALL->flag());
        $this->assertStringContainsString('https://flagcdn.com/us.svg', Currency::USD->flag());
        $this->assertStringContainsString('<img', Currency::EUR->flag());
    }

    public function test_supranational_and_crypto_codes_have_no_flag(): void
    {
        $this->assertSame('', Currency::CFA->flag());
        $this->assertSame('', Currency::BTC->flag());
        $this->assertSame('', Currency::MBTC->flag());
        $this->assertNull(Currency::CFA->region());
    }

    public function test_label_is_plain_text(): void
    {
        $this->assertSame('EUR — Euro', Currency::EUR->label());
        $this->assertStringNotContainsString('<', Currency::ALL->label());
    }

    public function test_chip_pairs_the_flag_with_the_code(): void
    {
        $chip = Currency::ALL->chip();
        $this->assertStringContainsString('flagcdn.com/al.svg', $chip);
        $this->assertStringEndsWith('ALL', $chip);

        // no-flag code is just the escaped text
        $this->assertSame('BTC', Currency::BTC->chip());
    }

    public function test_chip_for_tolerates_raw_strings_and_null(): void
    {
        $this->assertStringContainsString('flagcdn.com/us.svg', Currency::chipFor('USD'));
        $this->assertStringContainsString('flagcdn.com/us.svg', Currency::chipFor(Currency::USD));
        $this->assertSame('—', Currency::chipFor(null));
        $this->assertSame('—', Currency::chipFor(''));
        $this->assertSame('XYZ', Currency::chipFor('XYZ'));   // unknown code passes through, escaped
    }
}
