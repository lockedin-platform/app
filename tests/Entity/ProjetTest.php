<?php

namespace App\Tests\Entity;

use App\Entity\Projet;
use App\Entity\DonneesBusiness;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ProjetTest extends TestCase
{
    public function testDefaultStatutProjetIsBrouillon(): void
    {
        $projet = new Projet();
        $this->assertEquals(Projet::STATUT_BROUILLON, $projet->getStatutProjet());
    }

    public function testDateCreationSetOnConstruct(): void
    {
        $projet = new Projet();
        $this->assertInstanceOf(\DateTimeInterface::class, $projet->getDateCreation());
    }

    public function testSetTitre(): void
    {
        $projet = new Projet();
        $projet->setTitre('Mon projet innovant');
        $this->assertEquals('Mon projet innovant', $projet->getTitre());
    }

    public function testSetDescription(): void
    {
        $projet = new Projet();
        $projet->setDescription('Une description détaillée du projet');
        $this->assertEquals('Une description détaillée du projet', $projet->getDescription());
    }

    public function testSetSecteur(): void
    {
        $projet = new Projet();
        $projet->setSecteur('Technologie');
        $this->assertEquals('Technologie', $projet->getSecteur());
    }

    public function testSetEtape(): void
    {
        $projet = new Projet();
        $projet->setEtape('Lancement');
        $this->assertEquals('Lancement', $projet->getEtape());
    }

    public function testSetScoreGlobal(): void
    {
        $projet = new Projet();
        $projet->setScoreGlobal(75.5);
        $this->assertEquals(75.5, $projet->getScoreGlobal());
    }

    public function testSetDonneesBusinessBidirectional(): void
    {
        $projet = new Projet();
        $db = new DonneesBusiness();
        $projet->setDonneesBusiness($db);
        $this->assertSame($db, $projet->getDonneesBusiness());
        $this->assertSame($projet, $db->getProjet());
    }

    public function testSetUser(): void
    {
        $projet = new Projet();
        $user = new User();
        $user->setFirstname('Ali');
        $user->setLastname('Test');
        $user->setEmail('ali@test.com');
        $projet->setUser($user);
        $this->assertSame($user, $projet->getUser());
    }

    public function testSetStatutProjet(): void
    {
        $projet = new Projet();
        $projet->setStatutProjet(Projet::STATUT_EVALUE);
        $this->assertEquals(Projet::STATUT_EVALUE, $projet->getStatutProjet());
    }

    public function testSetDiagnosticIa(): void
    {
        $projet = new Projet();
        $projet->setDiagnosticIa('Projet prometteur avec un fort potentiel');
        $this->assertEquals('Projet prometteur avec un fort potentiel', $projet->getDiagnosticIa());
    }

    public function testOpportunitiesCollectionEmpty(): void
    {
        $projet = new Projet();
        $this->assertCount(0, $projet->getOpportunities());
    }

    public function testSetDateEvaluation(): void
    {
        $projet = new Projet();
        $date = new \DateTime('2025-06-15');
        $projet->setDateEvaluation($date);
        $this->assertSame($date, $projet->getDateEvaluation());
    }
}
