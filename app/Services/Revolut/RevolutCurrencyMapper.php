<?php

declare(strict_types=1);

namespace FireflyIII\Services\Revolut;

class RevolutCurrencyMapper
{
    private const array EIGHT_DECIMAL = ['BTC', 'ETH', 'BCH', 'XRP', 'LTC', 'XLM', 'EOS', 'OMG', 'XTZ', 'ZRX'];

    public function decimalPlaces(?string $currencyCode): int
    {
        if (null === $currencyCode) {
            return 2;
        }

        return in_array(strtoupper($currencyCode), self::EIGHT_DECIMAL, true) ? 8 : 2;
    }
}
