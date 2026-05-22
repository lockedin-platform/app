<?php

namespace App\Tests\Entity;

use App\Entity\InvestmentOpportunity;
use App\Entity\InvestmentOffer;
use App\Entity\Projet;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class InvestmentOpportunityTest extends TestCase
{
    public function testDefaultStatusIsOpen(): void
    {
        $opp = new InvestmentOpportunity();
        $this->assertEquals(InvestmentOpportunity::STATUS_OPEN, $opp->getStatus());
    }

    public function testCreatedAtSetOnConstruct(): void
    {
        $opp = new InvestmentOpportunity();
        $this->assertInstanceOf(\DateTimeInterface::class, $opp->getCreatedAt());
    }

    public function testOffersCollectionEmpty(): void
    {
        $opp = new InvestmentOpportunity();
        $this->assertCount(0, $opp->getOffers());
    }

    public function testSetTargetAmount(): void
    {
        $opp = new InvestmentOpportunity();
        $opp->setTargetAmount('500000.00');
        $this->assertEquals('500000.00', $opp->getTargetAmount());
    }

    public function testSetDescription(): void
    {
        $opp = new InvestmentOpportunity();
        $opp->setDescription('Recherche investissement pour expansion');
        $this->assertEquals('Recherche investissement pour expansion', $opp->getDescription());
    }

    public function testSetDeadline(): void
    {
        $opp = new InvestmentOpportunity();
        $date = new \DateTime('+60 days');
        $opp->setDeadline($date);
        $this->assertSame($date, $opp->getDeadline());
    }

    public function testSetProject(): void
    {
        $opp = new InvestmentOpportunity();
        $projet = new Projet();
        $projet->setTitre('Startup IA');
        $opp->setProject($projet);
        $this->assertSame($projet, $opp->getProject());
    }

    public function testSetRiskScore(): void
    {
        $opp = new InvestmentOpportunity();
        $opp->setRiskScore(45.5);
        $this->assertEquals(45.5, $opp->getRiskScore());
    }

    public function testSetRiskLabel(): void
    {
        $opp = new InvestmentOpportunity();
        $opp->setRiskLabel('MODERATE');
        $this->assertEquals('MODERATE', $opp->getRiskLabel());
    }

    public function testGetTotalFundedZeroByDefault(): void
    {
        $opp = new InvestmentOpportunity();
        $this->assertEquals(0.0, $opp->getTotalFunded());
    }

    public function testGetFundingPercentageZeroByDefault(): void
    {
        $opp = new InvestmentOpportunity();
        $opp->setTargetAmount('100000.00');
        $this->assertEquals(0.0, $opp->getFundingPercentage());
    }

    public function testGetFundingPercentageZeroTargetReturnsZero(): void
    {
        $opp = new InvestmentOpportunity();
        $this->assertEquals(0.0, $opp->getFundingPercentage());
    }
}
