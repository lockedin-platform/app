<?php

namespace App\Tests\Entity;

use App\Entity\DonneesBusiness;
use App\Entity\Projet;
use PHPUnit\Framework\TestCase;

class DonneesBusinessTest extends TestCase
{
    public function testDefaultCoutsEstimesIsZero(): void
    {
        $db = new DonneesBusiness();
        $this->assertEquals(0, $db->getCoutsEstimes());
    }

    public function testDefaultRevenusAttendusIsZero(): void
    {
        $db = new DonneesBusiness();
        $this->assertEquals(0, $db->getRevenusAttendus());
    }

    public function testDefaultForceEquipeIsZero(): void
    {
        $db = new DonneesBusiness();
        $this->assertEquals(0, $db->getForceEquipe());
    }

    public function testSetTailleMarche(): void
    {
        $db = new DonneesBusiness();
        $db->setTailleMarche('Grand');
        $this->assertEquals('Grand', $db->getTailleMarche());
    }

    public function testSetModeleRevenu(): void
    {
        $db = new DonneesBusiness();
        $db->setModeleRevenu('Abonnement');
        $this->assertEquals('Abonnement', $db->getModeleRevenu());
    }

    public function testSetCoutsEstimes(): void
    {
        $db = new DonneesBusiness();
        $db->setCoutsEstimes(50000.0);
        $this->assertEquals(50000.0, $db->getCoutsEstimes());
    }

    public function testSetRevenusAttendus(): void
    {
        $db = new DonneesBusiness();
        $db->setRevenusAttendus(150000.0);
        $this->assertEquals(150000.0, $db->getRevenusAttendus());
    }

    public function testSetNiveauRisque(): void
    {
        $db = new DonneesBusiness();
        $db->setNiveauRisque('Moyen');
        $this->assertEquals('Moyen', $db->getNiveauRisque());
    }

    public function testSetForceEquipe(): void
    {
        $db = new DonneesBusiness();
        $db->setForceEquipe(8);
        $this->assertEquals(8, $db->getForceEquipe());
    }

    public function testSetProjet(): void
    {
        $db = new DonneesBusiness();
        $projet = new Projet();
        $projet->setTitre('Mon projet');
        $db->setProjet($projet);
        $this->assertSame($projet, $db->getProjet());
    }

    public function testCalculerIndicateurs(): void
    {
        $db = new DonneesBusiness();
        $db->setCoutsEstimes(40000.0);
        $db->setRevenusAttendus(100000.0);
        $db->calculerIndicateurs();
        $this->assertEquals(60000.0, $db->getMargeEstimee());
        $this->assertEquals(1.5, $db->getRatioRentabilite());
    }

    public function testCalculerIndicateursZeroCouts(): void
    {
        $db = new DonneesBusiness();
        $db->setCoutsEstimes(0);
        $db->setRevenusAttendus(100000.0);
        $db->calculerIndicateurs();
        $this->assertEquals(100000.0, $db->getMargeEstimee());
        $this->assertEquals(0, $db->getRatioRentabilite());
    }

    public function testSetScoreFinancier(): void
    {
        $db = new DonneesBusiness();
        $db->setScoreFinancier(75.5);
        $this->assertEquals(75.5, $db->getScoreFinancier());
    }

    public function testSetScoreMarche(): void
    {
        $db = new DonneesBusiness();
        $db->setScoreMarche(80.0);
        $this->assertEquals(80.0, $db->getScoreMarche());
    }
}
