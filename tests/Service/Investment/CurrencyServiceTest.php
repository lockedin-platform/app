<?php

namespace App\Tests\Service\Investment;

use App\Service\Investment\CurrencyService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CurrencyServiceTest extends TestCase
{
    private CurrencyService $service;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = new CurrencyService($httpClient);
    }

    public function testConvertSameCurrency(): void
    {
        $result = $this->service->convert(100.0, 'EUR', 'EUR');
        $this->assertEquals(100.0, $result);
    }

    public function testConvertEurToUsd(): void
    {
        $result = $this->service->convert(100.0, 'EUR', 'USD');
        $this->assertGreaterThan(100.0, $result, 'EUR to USD should increase value (USD rate > 1)');
    }

    public function testConvertEurToTnd(): void
    {
        $result = $this->service->convert(100.0, 'EUR', 'TND');
        $this->assertGreaterThan(200.0, $result, 'EUR to TND should give ~335 TND');
    }

    public function testConvertZeroAmount(): void
    {
        $result = $this->service->convert(0.0, 'EUR', 'USD');
        $this->assertEquals(0.0, $result);
    }

    public function testGetRateReturnsPositive(): void
    {
        $rate = $this->service->getRate('EUR', 'TND');
        $this->assertGreaterThan(0.0, $rate);
    }

    public function testGetRatesReturnsAllCurrencies(): void
    {
        $rates = $this->service->getRates();
        $this->assertArrayHasKey('EUR', $rates);
        $this->assertArrayHasKey('USD', $rates);
        $this->assertArrayHasKey('TND', $rates);
        $this->assertArrayHasKey('GBP', $rates);
        $this->assertArrayHasKey('MAD', $rates);
    }

    public function testFormatEur(): void
    {
        $formatted = CurrencyService::format(1234.56, 'EUR');
        $this->assertStringContainsString('1', $formatted);
        $this->assertStringContainsString('€', $formatted);
    }

    public function testFormatTnd(): void
    {
        $formatted = CurrencyService::format(5000.0, 'TND');
        $this->assertStringContainsString('DT', $formatted);
    }
}
