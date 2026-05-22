<?php

namespace App\Tests\Entity;

use App\Entity\InvestorProfile;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class InvestorProfileTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $profile = new InvestorProfile();
        $this->assertInstanceOf(\DateTimeInterface::class, $profile->getCreatedAt());
    }

    public function testDefaultRiskToleranceIs5(): void
    {
        $profile = new InvestorProfile();
        $this->assertEquals(5, $profile->getRiskTolerance());
    }

    public function testDefaultBudgetMinIsZero(): void
    {
        $profile = new InvestorProfile();
        $this->assertEquals('0', $profile->getBudgetMin());
    }

    public function testDefaultBudgetMaxIs10M(): void
    {
        $profile = new InvestorProfile();
        $this->assertEquals('10000000', $profile->getBudgetMax());
    }

    public function testDefaultHorizonMonthsIs12(): void
    {
        $profile = new InvestorProfile();
        $this->assertEquals(12, $profile->getHorizonMonths());
    }

    public function testSetUser(): void
    {
        $profile = new InvestorProfile();
        $user = new User();
        $user->setFirstname('Invest');
        $user->setLastname('Or');
        $user->setEmail('invest@or.com');
        $profile->setUser($user);
        $this->assertSame($user, $profile->getUser());
    }

    public function testSetPreferredSectors(): void
    {
        $profile = new InvestorProfile();
        $profile->setPreferredSectors('Tech,Santé,Finance');
        $this->assertEquals('Tech,Santé,Finance', $profile->getPreferredSectors());
    }

    public function testGetSectorArrayParsesCommaSeparated(): void
    {
        $profile = new InvestorProfile();
        $profile->setPreferredSectors('Tech, Santé, Finance');
        $this->assertEquals(['Tech', 'Santé', 'Finance'], $profile->getSectorArray());
    }

    public function testGetSectorArrayEmptyWhenNull(): void
    {
        $profile = new InvestorProfile();
        $this->assertEquals([], $profile->getSectorArray());
    }

    public function testGetSectorArrayEmptyWhenBlank(): void
    {
        $profile = new InvestorProfile();
        $profile->setPreferredSectors('   ');
        $this->assertEquals([], $profile->getSectorArray());
    }

    public function testSetRiskTolerance(): void
    {
        $profile = new InvestorProfile();
        $profile->setRiskTolerance(8);
        $this->assertEquals(8, $profile->getRiskTolerance());
    }

    public function testSetBudgetMin(): void
    {
        $profile = new InvestorProfile();
        $profile->setBudgetMin('50000');
        $this->assertEquals('50000', $profile->getBudgetMin());
    }

    public function testSetHorizonMonths(): void
    {
        $profile = new InvestorProfile();
        $profile->setHorizonMonths(24);
        $this->assertEquals(24, $profile->getHorizonMonths());
    }

    public function testSetDescription(): void
    {
        $profile = new InvestorProfile();
        $profile->setDescription('Investisseur spécialisé dans les startups tech');
        $this->assertEquals('Investisseur spécialisé dans les startups tech', $profile->getDescription());
    }
}
