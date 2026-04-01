<?php

declare(strict_types=1);

namespace FireflyIII\Services\Monobank;

class MonobankCurrencyMapper
{
    private const array NUMERIC_TO_ALPHA = [
        36  => 'AUD',
        124 => 'CAD',
        156 => 'CNY',
        191 => 'HRK',
        203 => 'CZK',
        208 => 'DKK',
        344 => 'HKD',
        348 => 'HUF',
        376 => 'ILS',
        392 => 'JPY',
        398 => 'KZT',
        410 => 'KRW',
        578 => 'NOK',
        643 => 'RUB',
        752 => 'SEK',
        756 => 'CHF',
        826 => 'GBP',
        840 => 'USD',
        944 => 'AZN',
        946 => 'RON',
        949 => 'TRY',
        975 => 'BGN',
        978 => 'EUR',
        980 => 'UAH',
        985 => 'PLN',
    ];

    private const array ZERO_DECIMAL = ['JPY', 'KRW', 'TWD'];

    public function alphaFromNumeric(?int $numericCode): ?string
    {
        if (null === $numericCode) {
            return null;
        }

        return self::NUMERIC_TO_ALPHA[$numericCode] ?? null;
    }

    public function decimalPlaces(?string $currencyCode): int
    {
        if (null === $currencyCode) {
            return 2;
        }

        return in_array(strtoupper($currencyCode), self::ZERO_DECIMAL, true) ? 0 : 2;
    }
}
