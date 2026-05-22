<?php

namespace App\Service\Investment;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class EconomicApiService
{
    private const EXCHANGE_RATE_URL = 'https://open.er-api.com/v6/latest/EUR';
    private const GDP_URL_TEMPLATE = 'https://api.worldbank.org/v2/country/%s/indicator/NY.GDP.MKTP.CD?format=json&per_page=5';
    private const INFLATION_URL_TEMPLATE = 'https://api.worldbank.org/v2/country/%s/indicator/FP.CPI.TOTL.ZG?format=json&per_page=5';
    private const TIMEOUT = 15;

    private const COUNTRY_NAMES = [
        'TN' => 'Tunisie', 'FR' => 'France', 'US' => 'Etats-Unis',
        'DE' => 'Allemagne', 'GB' => 'Royaume-Uni', 'MA' => 'Maroc',
        'DZ' => 'Algerie', 'EG' => 'Egypte', 'SA' => 'Arabie Saoudite',
        'AE' => 'Emirats Arabes Unis',
    ];

    private const DEFAULT_GDP = [
        'TN' => 46.7, 'FR' => 2780.0, 'US' => 25460.0,
        'DE' => 4070.0, 'MA' => 130.0,
    ];

    private const DEFAULT_INFLATION = [
        'TN' => 8.3, 'FR' => 4.9, 'US' => 4.1,
        'DE' => 5.9, 'MA' => 6.1,
    ];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /** @return array<string, mixed> */
    public function fetchAllEconomicData(string $countryCode): array
    {
        $data = [
            'countryCode' => strtoupper($countryCode),
            'countryName' => self::COUNTRY_NAMES[strtoupper($countryCode)] ?? $countryCode,
            'exchangeRateEurUsd' => 1.08,
            'exchangeRateEurTnd' => 3.38,
            'gdpBillions' => self::DEFAULT_GDP[strtoupper($countryCode)] ?? 100.0,
            'inflationRate' => self::DEFAULT_INFLATION[strtoupper($countryCode)] ?? 5.0,
            'dataYear' => 'N/A',
            'dataAvailable' => false,
            'fetchTimestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        $hasData = false;

        try {
            $rates = $this->fetchExchangeRates();
            $data['exchangeRateEurUsd'] = $rates['USD'] ?? 1.08;
            $data['exchangeRateEurTnd'] = $rates['TND'] ?? 3.38;
            $hasData = true;
        } catch (\Throwable $e) {
            // keep defaults
        }

        try {
            $gdpResult = $this->fetchWorldBankIndicator(
                sprintf(self::GDP_URL_TEMPLATE, $countryCode)
            );
            if ($gdpResult['value'] !== null) {
                $data['gdpBillions'] = $gdpResult['value'] / 1_000_000_000;
                $data['dataYear'] = $gdpResult['date'] ?? 'N/A';
                $hasData = true;
            }
        } catch (\Throwable $e) {
            // keep defaults
        }

        try {
            $inflResult = $this->fetchWorldBankIndicator(
                sprintf(self::INFLATION_URL_TEMPLATE, $countryCode)
            );
            if ($inflResult['value'] !== null) {
                $data['inflationRate'] = $inflResult['value'];
                $hasData = true;
            }
        } catch (\Throwable $e) {
            // keep defaults
        }

        $data['dataAvailable'] = $hasData;

        return $data;
    }

    /** @return array<string, float> */
    public function fetchExchangeRates(): array
    {
        $response = $this->httpClient->request('GET', self::EXCHANGE_RATE_URL, [
            'timeout' => self::TIMEOUT,
            'verify_peer' => false,
            'verify_host' => false,
        ]);

        $json = $response->toArray();

        return $json['rates'] ?? [];
    }

    /** @return array<string, float|string|null> */
    private function fetchWorldBankIndicator(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => self::TIMEOUT,
            'verify_peer' => false,
            'verify_host' => false,
        ]);

        $json = $response->toArray();

        // World Bank format: [{page metadata}, [{value: ..., date: ...}, ...]]
        if (!isset($json[1]) || !is_array($json[1])) {
            return ['value' => null, 'date' => null];
        }

        foreach ($json[1] as $entry) {
            if (isset($entry['value'])) {
                return [
                    'value' => (float) $entry['value'],
                    'date' => $entry['date'] ?? null,
                ];
            }
        }

        return ['value' => null, 'date' => null];
    }

    public function getCountryName(string $code): string
    {
        return self::COUNTRY_NAMES[strtoupper($code)] ?? $code;
    }

    /** @return array<string, string> */
    public function getSupportedCountries(): array
    {
        return self::COUNTRY_NAMES;
    }
}
