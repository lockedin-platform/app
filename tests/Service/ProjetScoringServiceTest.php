<?php

namespace App\Tests\Service;

use App\Service\ProjetScoringService;
use App\Service\GeminiService;
use App\Entity\Projet;
use App\Entity\DonneesBusiness;
use PHPUnit\Framework\TestCase;

class ProjetScoringServiceTest extends TestCase
{
    private ProjetScoringService $service;

    protected function setUp(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('isConfigured')->willReturn(false);
        $this->service = new ProjetScoringService($gemini);
    }

    public function testCalculateScoresWithCompleteData(): void
    {
        $projet = new Projet();
        $projet->setTitre('Mon Projet Test');
        $projet->setDescription('Un projet innovant dans le secteur tech');
        $projet->setSecteur('Technologie');
        $projet->setEtape('Lancement');

        $db = new DonneesBusiness();
        $db->setTailleMarche('5000000');
        $db->setModeleRevenu('SaaS');
        $db->setCoutsEstimes(100000);
        $db->setRevenusAttendus(500000);
        $db->setNiveauRisque('3');
        $db->setForceEquipe(7);
        $projet->setDonneesBusiness($db);

        $this->service->calculateScores($projet);
        $score = $projet->getScoreGlobal();
        $this->assertNotNull($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testCalculateScoresWithNoDonneesBusinessReturnsEarly(): void
    {
        $projet = new Projet();
        $projet->setTitre('Projet vide');
        $projet->setDescription('Description minimale');
        $projet->setSecteur('Autre');
        $projet->setEtape('Idéation');

        $this->service->calculateScores($projet);
        // No DonneesBusiness → method returns early, score unchanged
        $this->assertEquals(0.0, $projet->getScoreGlobal());
    }

    public function testCalculateScoresHighMarginHighScore(): void
    {
        $projet = new Projet();
        $projet->setTitre('Projet Rentable');
        $projet->setDescription('Un projet très rentable avec forte marge');
        $projet->setSecteur('Finance');
        $projet->setEtape('Croissance');

        $db = new DonneesBusiness();
        $db->setTailleMarche('50000000');
        $db->setModeleRevenu('Commission');
        $db->setCoutsEstimes(50000);
        $db->setRevenusAttendus(2000000);
        $db->setNiveauRisque('2');
        $db->setForceEquipe(9);
        $projet->setDonneesBusiness($db);

        $this->service->calculateScores($projet);
        $score = $projet->getScoreGlobal();
        $this->assertGreaterThan(40, $score);
    }

    public function testCalculateScoresHighRiskLowerScore(): void
    {
        $projet = new Projet();
        $projet->setTitre('Projet Risqué');
        $projet->setDescription('Un projet risqué');
        $projet->setSecteur('Crypto');
        $projet->setEtape('Idéation');

        $db = new DonneesBusiness();
        $db->setTailleMarche('1000');
        $db->setModeleRevenu('inconnu');
        $db->setCoutsEstimes(500000);
        $db->setRevenusAttendus(10000);
        $db->setNiveauRisque('9');
        $db->setForceEquipe(2);
        $projet->setDonneesBusiness($db);

        $this->service->calculateScores($projet);
        $score = $projet->getScoreGlobal();
        $this->assertLessThan(80, $score);
    }

    public function testCalculateScoresIsIdempotent(): void
    {
        $projet = new Projet();
        $projet->setTitre('Projet Test');
        $projet->setDescription('Test');
        $projet->setSecteur('Tech');
        $projet->setEtape('Lancement');

        $db = new DonneesBusiness();
        $db->setTailleMarche('100000');
        $db->setModeleRevenu('Abonnement');
        $db->setCoutsEstimes(50000);
        $db->setRevenusAttendus(100000);
        $db->setNiveauRisque('5');
        $db->setForceEquipe(5);
        $projet->setDonneesBusiness($db);

        $this->service->calculateScores($projet);
        $score1 = $projet->getScoreGlobal();
        $this->service->calculateScores($projet);
        $score2 = $projet->getScoreGlobal();
        $this->assertEquals($score1, $score2);
    }

    public function testGenerateDiagnosticLocalFallback(): void
    {
        $projet = new Projet();
        $projet->setTitre('Projet Diagnostic');
        $projet->setDescription('Un projet pour tester le diagnostic');
        $projet->setSecteur('Tech');
        $projet->setEtape('Lancement');

        $db = new DonneesBusiness();
        $db->setTailleMarche(100000);
        $db->setModeleRevenu('Abonnement');
        $db->setCoutsEstimes(50000);
        $db->setRevenusAttendus(200000);
        $db->setNiveauRisque(4);
        $db->setForceEquipe(6);
        $projet->setDonneesBusiness($db);

        $this->service->calculateScores($projet);
        $diagnostic = $this->service->generateDiagnostic($projet);
        $this->assertIsString($diagnostic);
        $this->assertNotEmpty($diagnostic);
    }
}
