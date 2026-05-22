<?php

namespace App\Tests\Entity;

use App\Entity\ContractMilestone;
use App\Entity\InvestmentContract;
use PHPUnit\Framework\TestCase;

class ContractMilestoneTest extends TestCase
{
    public function testDefaultStatusIsPending(): void
    {
        $milestone = new ContractMilestone();
        $this->assertEquals(ContractMilestone::STATUS_PENDING, $milestone->getStatus());
    }

    public function testCreatedAtSetOnConstruct(): void
    {
        $milestone = new ContractMilestone();
        $this->assertInstanceOf(\DateTimeInterface::class, $milestone->getCreatedAt());
    }

    public function testSetLabel(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setLabel('Livraison phase 1');
        $this->assertEquals('Livraison phase 1', $milestone->getLabel());
    }

    public function testSetPercentage(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setPercentage('25.00');
        $this->assertEquals('25.00', $milestone->getPercentage());
    }

    public function testSetAmount(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setAmount('50000.00');
        $this->assertEquals('50000.00', $milestone->getAmount());
    }

    public function testSetPosition(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setPosition(2);
        $this->assertEquals(2, $milestone->getPosition());
    }

    public function testSetContract(): void
    {
        $milestone = new ContractMilestone();
        $contract = new InvestmentContract();
        $milestone->setContract($contract);
        $this->assertSame($contract, $milestone->getContract());
    }

    public function testCanBeMarkedCompleteWhenPending(): void
    {
        $milestone = new ContractMilestone();
        $this->assertTrue($milestone->canBeMarkedComplete());
    }

    public function testCannotBeMarkedCompleteWhenCompleted(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setStatus(ContractMilestone::STATUS_COMPLETED);
        $this->assertFalse($milestone->canBeMarkedComplete());
    }

    public function testCanBeConfirmedWhenCompleted(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setStatus(ContractMilestone::STATUS_COMPLETED);
        $this->assertTrue($milestone->canBeConfirmed());
    }

    public function testCannotBeConfirmedWhenPending(): void
    {
        $milestone = new ContractMilestone();
        $this->assertFalse($milestone->canBeConfirmed());
    }

    public function testCanBeReleasedWhenConfirmed(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setStatus(ContractMilestone::STATUS_CONFIRMED);
        $this->assertTrue($milestone->canBeReleased());
    }

    public function testCannotBeReleasedWhenPending(): void
    {
        $milestone = new ContractMilestone();
        $this->assertFalse($milestone->canBeReleased());
    }

    public function testIsReleasedFalseByDefault(): void
    {
        $milestone = new ContractMilestone();
        $this->assertFalse($milestone->isReleased());
    }

    public function testIsReleasedTrueWhenStatusReleased(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setStatus(ContractMilestone::STATUS_RELEASED);
        $this->assertTrue($milestone->isReleased());
    }

    public function testSetPaymentIntentId(): void
    {
        $milestone = new ContractMilestone();
        $milestone->setPaymentIntentId('pi_1234567890');
        $this->assertEquals('pi_1234567890', $milestone->getPaymentIntentId());
    }

    public function testSetCompletedAt(): void
    {
        $milestone = new ContractMilestone();
        $date = new \DateTime();
        $milestone->setCompletedAt($date);
        $this->assertSame($date, $milestone->getCompletedAt());
    }
}
