<?php

namespace App\Tests\Entity;

use App\Entity\Progression;
use App\Entity\Cours;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ProgressionTest extends TestCase
{
    public function testDefaultEtatIsNonCommence(): void
    {
        $progression = new Progression();
        $this->assertEquals(Progression::ETAT_NON_COMMENCE, $progression->getEtat());
    }

    public function testDefaultPourcentageIsZero(): void
    {
        $progression = new Progression();
        $this->assertEquals('0.00', $progression->getPourcentage());
    }

    public function testDefaultPointsXpIsZero(): void
    {
        $progression = new Progression();
        $this->assertEquals(0, $progression->getPointsXp());
    }

    public function testDefaultNiveauIsOne(): void
    {
        $progression = new Progression();
        $this->assertEquals(1, $progression->getNiveau());
    }

    public function testDateDebutSetOnConstruct(): void
    {
        $progression = new Progression();
        $this->assertInstanceOf(\DateTimeInterface::class, $progression->getDateDebut());
    }

    public function testSetUser(): void
    {
        $progression = new Progression();
        $user = new User();
        $user->setFirstname('Student');
        $user->setLastname('Test');
        $user->setEmail('student@test.com');
        $progression->setUser($user);
        $this->assertSame($user, $progression->getUser());
    }

    public function testSetCours(): void
    {
        $progression = new Progression();
        $cours = new Cours();
        $cours->setTitre('PHP Avancé');
        $progression->setCours($cours);
        $this->assertSame($cours, $progression->getCours());
    }

    public function testSetPourcentage(): void
    {
        $progression = new Progression();
        $progression->setPourcentage('75.50');
        $this->assertEquals('75.50', $progression->getPourcentage());
    }

    public function testSetPointsXp(): void
    {
        $progression = new Progression();
        $progression->setPointsXp(500);
        $this->assertEquals(500, $progression->getPointsXp());
    }

    public function testSetNiveau(): void
    {
        $progression = new Progression();
        $progression->setNiveau(5);
        $this->assertEquals(5, $progression->getNiveau());
    }

    public function testSetEtatEnCours(): void
    {
        $progression = new Progression();
        $progression->setEtat(Progression::ETAT_EN_COURS);
        $this->assertEquals(Progression::ETAT_EN_COURS, $progression->getEtat());
    }

    public function testSetEtatComplete(): void
    {
        $progression = new Progression();
        $progression->setEtat(Progression::ETAT_COMPLETE);
        $this->assertEquals(Progression::ETAT_COMPLETE, $progression->getEtat());
    }

    public function testSetEtatCertifie(): void
    {
        $progression = new Progression();
        $progression->setEtat(Progression::ETAT_CERTIFIE);
        $this->assertEquals(Progression::ETAT_CERTIFIE, $progression->getEtat());
    }
}
