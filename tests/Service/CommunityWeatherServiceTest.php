<?php

namespace App\Tests\Service;

use App\Service\CommunityWeatherService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CommunityWeatherServiceTest extends TestCase
{
    private CommunityWeatherService $service;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = new CommunityWeatherService($httpClient);
    }

    public function testForecastForNullDate(): void
    {
        $result = $this->service->forecastForEvent(null);
        $this->assertEquals('unavailable', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testForecastForPastDate(): void
    {
        $pastDate = new \DateTimeImmutable('-3 days');
        $result = $this->service->forecastForEvent($pastDate);
        $this->assertEquals('past', $result['status']);
        $this->assertArrayHasKey('days_until_event', $result);
    }

    public function testForecastForFarFutureDate(): void
    {
        $futureDate = new \DateTimeImmutable('+60 days');
        $result = $this->service->forecastForEvent($futureDate);
        $this->assertEquals('pending', $result['status']);
        $this->assertArrayHasKey('days_until_available', $result);
    }

    public function testForecastPastDateHasNegativeDays(): void
    {
        $pastDate = new \DateTimeImmutable('-5 days');
        $result = $this->service->forecastForEvent($pastDate);
        $this->assertLessThan(0, $result['days_until_event']);
    }

    public function testForecastFarFutureHasDaysUntilAvailable(): void
    {
        $futureDate = new \DateTimeImmutable('+30 days');
        $result = $this->service->forecastForEvent($futureDate);
        $this->assertEquals('pending', $result['status']);
        $this->assertGreaterThanOrEqual(1, $result['days_until_available']);
    }

    public function testForecastUnavailableMessage(): void
    {
        $result = $this->service->forecastForEvent(null);
        $this->assertStringContainsString('indisponible', $result['message']);
    }
}
