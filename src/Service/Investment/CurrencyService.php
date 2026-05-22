<?php

namespace App\Service\Investment;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CurrencyService
{
    private const API_URL = 'https://open.er-api.com/v6/latest/EUR';
    private const CACHE_DURATION = 1800; // 30 minutes
    private const TIMEOUT = 10;

    public const CURRENCIES = ['EUR', 'USD', 'TND', 'GBP', 'MAD'];

    public const CURRENCY_LABELS = [
        'EUR' => 'Euro (EUR)',
        'USD' => 'Dollar (USD)',
        'TND' => 'Dinar (TND)',
        'GBP' => 'Livre (GBP)',
        'MAD' => 'Dirham (MAD)',
    ];

    public const CURRENCY_SYMBOLS = [
        'EUR' => "\u{20AC}", 'USD' => '$', 'TND' => 'DT', 'GBP' => "\u{00A3}", 'MAD' => 'MAD',
    ];

    /** @var array<string, float> */
    private array $rates;
    private int $lastFetch = 0;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        $this->rates = self::getDefaultRates();
    }

    /** @return array<string, float> */
    public function fetchRates(): array
    {
        if (time() - $this->lastFetch < self::CACHE_DURATION && !empty($this->rates)) {
            return $this->rates;
        }

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'timeout' => self::TIMEOUT,
            ]);
            $json = $response->toArray();

            if (isset($json['rates'])) {
                $parsed = [];
                foreach (self::CURRENCIES as $c) {
                    if (isset($json['rates'][$c])) {
                        $parsed[$c] = (float) $json['rates'][$c];
                    }
                }
                if (!empty($parsed)) {
                    $this->rates = $parsed;
                    $this->lastFetch = time();
                }
            }
        } catch (\Throwable $e) {
            // keep existing/default rates
        }

        return $this->rates;
    }

    public function convert(float $amount, string $from, string $to): float
    {
        if ($from === $to) {
            return $amount;
        }

        $fromRate = $this->rates[$from] ?? 1.0;
        $toRate = $this->rates[$to] ?? 1.0;

        // Convert via EUR as pivot
        $amountInEur = $amount / $fromRate;
        return $amountInEur * $toRate;
    }

    public function getRate(string $from, string $to): float
    {
        return $this->convert(1.0, $from, $to);
    }

    public static function format(float $amount, string $currency): string
    {
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? $currency;
        return number_format($amount, 2, ',', ' ') . ' ' . $symbol;
    }

    /** @return array<string, float> */
    public function getRates(): array
    {
        return $this->rates;
    }

    /** @return array<string, float> */
    private static function getDefaultRates(): array
    {
        return [
            'EUR' => 1.0,
            'USD' => 1.08,
            'TND' => 3.35,
            'GBP' => 0.86,
            'MAD' => 10.85,
        ];
    }
}
