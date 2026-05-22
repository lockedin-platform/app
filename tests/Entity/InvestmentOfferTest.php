<?php

namespace App\Tests\Entity;

use App\Entity\InvestmentOffer;
use App\Entity\InvestmentOpportunity;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class InvestmentOfferTest extends TestCase
{
    public function testDefaultStatusIsPending(): void
    {
        $offer = new InvestmentOffer();
        $this->assertEquals(InvestmentOffer::STATUS_PENDING, $offer->getStatus());
    }

    public function testCreatedAtSetOnConstruct(): void
    {
        $offer = new InvestmentOffer();
        $this->assertInstanceOf(\DateTimeInterface::class, $offer->getCreatedAt());
    }

    public function testUpdatedAtSetOnConstruct(): void
    {
        $offer = new InvestmentOffer();
        $this->assertInstanceOf(\DateTimeInterface::class, $offer->getUpdatedAt());
    }

    public function testIsPaidDefaultFalse(): void
    {
        $offer = new InvestmentOffer();
        $this->assertFalse($offer->isPaid());
    }

    public function testIsRiskAcknowledgedDefaultFalse(): void
    {
        $offer = new InvestmentOffer();
        $this->assertFalse($offer->isRiskAcknowledged());
    }

    public function testSetProposedAmount(): void
    {
        $offer = new InvestmentOffer();
        $offer->setProposedAmount('25000.00');
        $this->assertEquals('25000.00', $offer->getProposedAmount());
    }

    public function testSetStatusAccepted(): void
    {
        $offer = new InvestmentOffer();
        $offer->setStatus(InvestmentOffer::STATUS_ACCEPTED);
        $this->assertEquals(InvestmentOffer::STATUS_ACCEPTED, $offer->getStatus());
    }

    public function testSetInvestor(): void
    {
        $offer = new InvestmentOffer();
        $user = new User();
        $user->setFirstname('Investor');
        $user->setLastname('I');
        $user->setEmail('investor@i.com');
        $offer->setInvestor($user);
        $this->assertSame($user, $offer->getInvestor());
    }

    public function testSetOpportunity(): void
    {
        $offer = new InvestmentOffer();
        $opp = new InvestmentOpportunity();
        $offer->setOpportunity($opp);
        $this->assertSame($opp, $offer->getOpportunity());
    }

    public function testSetPaid(): void
    {
        $offer = new InvestmentOffer();
        $offer->setPaid(true);
        $this->assertTrue($offer->isPaid());
    }

    public function testSetRiskAcknowledged(): void
    {
        $offer = new InvestmentOffer();
        $offer->setRiskAcknowledged(true);
        $this->assertTrue($offer->isRiskAcknowledged());
    }

    public function testSetPaymentIntentId(): void
    {
        $offer = new InvestmentOffer();
        $offer->setPaymentIntentId('pi_abc123');
        $this->assertEquals('pi_abc123', $offer->getPaymentIntentId());
    }

    public function testIsContractReadyForPaymentFalseByDefault(): void
    {
        $offer = new InvestmentOffer();
        $this->assertFalse($offer->isContractReadyForPayment());
    }
}
