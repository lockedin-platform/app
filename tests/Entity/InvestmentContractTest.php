<?php

namespace App\Tests\Entity;

use App\Entity\InvestmentContract;
use App\Entity\InvestmentContractMessage;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class InvestmentContractTest extends TestCase
{
    public function testDefaultStatusIsNegotiating(): void
    {
        $contract = new InvestmentContract();
        $this->assertEquals(InvestmentContract::STATUS_NEGOTIATING, $contract->getStatus());
    }

    public function testCreatedAtSetOnConstruct(): void
    {
        $contract = new InvestmentContract();
        $this->assertInstanceOf(\DateTimeInterface::class, $contract->getCreatedAt());
    }

    public function testSetTitle(): void
    {
        $contract = new InvestmentContract();
        $contract->setTitle('Contrat investissement série A');
        $this->assertEquals('Contrat investissement série A', $contract->getTitle());
    }

    public function testSetTerms(): void
    {
        $contract = new InvestmentContract();
        $contract->setTerms('Les parties conviennent de...');
        $this->assertEquals('Les parties conviennent de...', $contract->getTerms());
    }

    public function testSetEquityPercentage(): void
    {
        $contract = new InvestmentContract();
        $contract->setEquityPercentage('15.50');
        $this->assertEquals('15.50', $contract->getEquityPercentage());
    }

    public function testBelongsToInvestor(): void
    {
        $investor = $this->createUserWithId(1);
        $entrepreneur = $this->createUserWithId(2);
        $other = $this->createUserWithId(3);

        $contract = new InvestmentContract();
        $contract->setInvestor($investor);
        $contract->setEntrepreneur($entrepreneur);

        $this->assertTrue($contract->belongsTo($investor));
        $this->assertTrue($contract->belongsTo($entrepreneur));
        $this->assertFalse($contract->belongsTo($other));
    }

    public function testGetOtherParty(): void
    {
        $investor = $this->createUserWithId(1);
        $entrepreneur = $this->createUserWithId(2);

        $contract = new InvestmentContract();
        $contract->setInvestor($investor);
        $contract->setEntrepreneur($entrepreneur);

        $this->assertSame($entrepreneur, $contract->getOtherParty($investor));
        $this->assertSame($investor, $contract->getOtherParty($entrepreneur));
    }

    public function testIsFullySignedFalseByDefault(): void
    {
        $contract = new InvestmentContract();
        $this->assertFalse($contract->isFullySigned());
    }

    public function testMarkSignedByInvestor(): void
    {
        $investor = $this->createUserWithId(1);
        $entrepreneur = $this->createUserWithId(2);

        $contract = new InvestmentContract();
        $contract->setInvestor($investor);
        $contract->setEntrepreneur($entrepreneur);

        $now = new \DateTime();
        $contract->markSignedBy($investor, 'Ali Investor', 'abc123hash', $now);

        $this->assertEquals('Ali Investor', $contract->getInvestorSignatureName());
        $this->assertEquals('abc123hash', $contract->getInvestorSignatureHash());
        $this->assertEquals(InvestmentContract::STATUS_READY_TO_SIGN, $contract->getStatus());
        $this->assertFalse($contract->isFullySigned());
    }

    public function testMarkSignedByBothPartiesMakesSigned(): void
    {
        $investor = $this->createUserWithId(1);
        $entrepreneur = $this->createUserWithId(2);

        $contract = new InvestmentContract();
        $contract->setInvestor($investor);
        $contract->setEntrepreneur($entrepreneur);

        $now = new \DateTime();
        $contract->markSignedBy($investor, 'Investor', 'hash1', $now);
        $contract->markSignedBy($entrepreneur, 'Entrepreneur', 'hash2', $now);

        $this->assertTrue($contract->isFullySigned());
        $this->assertEquals(InvestmentContract::STATUS_SIGNED, $contract->getStatus());
    }

    public function testClearSignatures(): void
    {
        $investor = $this->createUserWithId(1);
        $entrepreneur = $this->createUserWithId(2);

        $contract = new InvestmentContract();
        $contract->setInvestor($investor);
        $contract->setEntrepreneur($entrepreneur);

        $now = new \DateTime();
        $contract->markSignedBy($investor, 'Investor', 'hash1', $now);
        $contract->clearSignatures();

        $this->assertNull($contract->getInvestorSignatureName());
        $this->assertNull($contract->getInvestorSignatureHash());
        $this->assertEquals(InvestmentContract::STATUS_NEGOTIATING, $contract->getStatus());
    }

    public function testAddMessage(): void
    {
        $contract = new InvestmentContract();
        $message = new InvestmentContractMessage();

        $contract->addMessage($message);
        $this->assertCount(1, $contract->getMessages());
        $this->assertSame($contract, $message->getContract());
    }

    public function testMessagesCollectionEmptyByDefault(): void
    {
        $contract = new InvestmentContract();
        $this->assertCount(0, $contract->getMessages());
    }

    private function createUserWithId(int $id): User
    {
        $user = new User();
        $user->setFirstname('User');
        $user->setLastname((string) $id);
        $user->setEmail("user{$id}@test.com");
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);
        return $user;
    }
}
