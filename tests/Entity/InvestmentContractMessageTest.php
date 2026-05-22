<?php

namespace App\Tests\Entity;

use App\Entity\InvestmentContractMessage;
use App\Entity\InvestmentContract;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class InvestmentContractMessageTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $msg = new InvestmentContractMessage();
        $this->assertInstanceOf(\DateTimeInterface::class, $msg->getCreatedAt());
    }

    public function testDefaultBodyIsEmpty(): void
    {
        $msg = new InvestmentContractMessage();
        $this->assertEquals('', $msg->getBody());
    }

    public function testDefaultSystemMessageFalse(): void
    {
        $msg = new InvestmentContractMessage();
        $this->assertFalse($msg->isSystemMessage());
    }

    public function testSetBody(): void
    {
        $msg = new InvestmentContractMessage();
        $msg->setBody('Bonjour, voici ma proposition.');
        $this->assertEquals('Bonjour, voici ma proposition.', $msg->getBody());
    }

    public function testSetSystemMessage(): void
    {
        $msg = new InvestmentContractMessage();
        $msg->setSystemMessage(true);
        $this->assertTrue($msg->isSystemMessage());
    }

    public function testSetContract(): void
    {
        $msg = new InvestmentContractMessage();
        $contract = new InvestmentContract();
        $msg->setContract($contract);
        $this->assertSame($contract, $msg->getContract());
    }

    public function testSetSender(): void
    {
        $msg = new InvestmentContractMessage();
        $user = new User();
        $user->setFirstname('Sender');
        $user->setLastname('S');
        $user->setEmail('sender@s.com');
        $msg->setSender($user);
        $this->assertSame($user, $msg->getSender());
    }
}
