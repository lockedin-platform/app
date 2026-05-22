<?php

namespace App\Tests\Service\Investment;

use App\Service\Investment\EconomicRiskEngine;
use PHPUnit\Framework\TestCase;

class EconomicRiskEngineTest extends TestCase
{
    private EconomicRiskEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new EconomicRiskEngine();
    }

    public function testComputeEconomicFactorWithEmptyData(): void
    {
        $result = $this->engine->computeEconomicFactor([]);
        $this->assertEquals(50.0, $result, 'Empty data should return default 50.0');
    }

    public function testComputeEconomicFactorWithDataAvailableFalse(): void
    {
        $result = $this->engine->computeEconomicFactor(['dataAvailable' => false]);
        $this->assertEquals(50.0, $result);
    }

    public function testComputeEconomicFactorWithValidData(): void
    {
        $data = [
            'dataAvailable' => true,
            'exchangeRateEurUsd' => 1.08,
            'gdpBillions' => 500.0,
            'inflationRate' => 3.0,
        ];
        $result = $this->engine->computeEconomicFactor($data);
        $this->assertGreaterThanOrEqual(0.0, $result);
        $this->assertLessThanOrEqual(100.0, $result);
    }

    public function testComputeEconomicFactorHighInflation(): void
    {
        $data = [
            'dataAvailable' => true,
            'exchangeRateEurUsd' => 1.08,
            'gdpBillions' => 50.0,
            'inflationRate' => 25.0,
        ];
        $result = $this->engine->computeEconomicFactor($data);
        $this->assertGreaterThan(50.0, $result, 'High inflation should yield higher risk factor');
    }

    public function testCalculateFullRiskValidInputs(): void
    {
        $ecoData = [
            'dataAvailable' => true,
            'exchangeRateEurUsd' => 1.08,
            'gdpBillions' => 400.0,
            'inflationRate' => 5.0,
        ];
        $deadline = new \DateTimeImmutable('+6 months');
        $score = $this->engine->calculateFullRisk(50000.0, $deadline, $ecoData);
        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testCalculateFullRiskThrowsOnZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->calculateFullRisk(0.0, null, []);
    }

    public function testCalculateFullRiskThrowsOnNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->calculateFullRisk(-1000.0, null, []);
    }

    public function testCalculateFullRiskHighAmountHigherRisk(): void
    {
        $ecoData = ['dataAvailable' => false];
        $low = $this->engine->calculateFullRisk(1000.0, new \DateTimeImmutable('+12 months'), $ecoData);
        $high = $this->engine->calculateFullRisk(5000000.0, new \DateTimeImmutable('+12 months'), $ecoData);
        $this->assertGreaterThan($low, $high, 'Larger amount should yield higher risk');
    }

    public function testCalculateFullRiskNullDeadline(): void
    {
        $score = $this->engine->calculateFullRisk(100000.0, null, ['dataAvailable' => false]);
        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
    }

    public function testBuildDeterministicVerdictLowRisk(): void
    {
        $verdict = $this->engine->buildDeterministicVerdict(
            20,
            ['countryName' => 'Tunisie', 'inflationRate' => 3.0, 'gdpBillions' => 45.0],
            50000.0,
            new \DateTimeImmutable('+6 months')
        );
        $this->assertIsString($verdict);
        $this->assertNotEmpty($verdict);
    }

    public function testBuildDeterministicVerdictHighRisk(): void
    {
        $verdict = $this->engine->buildDeterministicVerdict(
            85,
            ['countryName' => 'Tunisie', 'inflationRate' => 12.0, 'gdpBillions' => 45.0],
            2000000.0,
            new \DateTimeImmutable('+1 month')
        );
        $this->assertIsString($verdict);
        $this->assertNotEmpty($verdict);
    }
}
