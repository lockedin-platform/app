<?php

namespace App\Tests\Entity;

use App\Entity\Cours;
use App\Entity\Progression;
use PHPUnit\Framework\TestCase;

class CoursTest extends TestCase
{
    public function testDefaultNiveauIsDebutant(): void
    {
        $cours = new Cours();
        $this->assertEquals(Cours::NIVEAU_DEBUTANT, $cours->getNiveauDifficulte());
    }

    public function testDefaultPointsXpIs100(): void
    {
        $cours = new Cours();
        $this->assertEquals(100, $cours->getPointsXp());
    }

    public function testDefaultDureeEstimeeIs60(): void
    {
        $cours = new Cours();
        $this->assertEquals(60, $cours->getDureeEstimee());
    }

    public function testDefaultCategorieIsGeneral(): void
    {
        $cours = new Cours();
        $this->assertEquals('Général', $cours->getCategorie());
    }

    public function testSetTitre(): void
    {
        $cours = new Cours();
        $cours->setTitre('Introduction à PHP');
        $this->assertEquals('Introduction à PHP', $cours->getTitre());
    }

    public function testSetDescription(): void
    {
        $cours = new Cours();
        $cours->setDescription('Un cours complet sur PHP 8');
        $this->assertEquals('Un cours complet sur PHP 8', $cours->getDescription());
    }

    public function testSetNiveauDifficulte(): void
    {
        $cours = new Cours();
        $cours->setNiveauDifficulte(Cours::NIVEAU_AVANCE);
        $this->assertEquals(Cours::NIVEAU_AVANCE, $cours->getNiveauDifficulte());
    }

    public function testSetPointsXp(): void
    {
        $cours = new Cours();
        $cours->setPointsXp(250);
        $this->assertEquals(250, $cours->getPointsXp());
    }

    public function testIsCertificationDefaultFalse(): void
    {
        $cours = new Cours();
        $this->assertFalse($cours->isCertification());
    }

    public function testSetCertification(): void
    {
        $cours = new Cours();
        $cours->setCertification(true);
        $this->assertTrue($cours->isCertification());
    }

    public function testIsActifDefaultTrue(): void
    {
        $cours = new Cours();
        $this->assertTrue($cours->isActif());
    }

    public function testSetActifFalse(): void
    {
        $cours = new Cours();
        $cours->setActif(false);
        $this->assertFalse($cours->isActif());
    }

    public function testCreatedAtSetOnConstruct(): void
    {
        $cours = new Cours();
        $this->assertInstanceOf(\DateTimeInterface::class, $cours->getCreatedAt());
    }

    public function testProgressionsCollectionEmpty(): void
    {
        $cours = new Cours();
        $this->assertCount(0, $cours->getProgressions());
    }

    public function testCommentsCollectionEmpty(): void
    {
        $cours = new Cours();
        $this->assertCount(0, $cours->getComments());
    }

    public function testSetDocumentPath(): void
    {
        $cours = new Cours();
        $cours->setDocumentPath('/uploads/cours/doc.pdf');
        $this->assertEquals('/uploads/cours/doc.pdf', $cours->getDocumentPath());
    }

    public function testSetVideoUrl(): void
    {
        $cours = new Cours();
        $cours->setVideoUrl('https://youtube.com/watch?v=123');
        $this->assertEquals('https://youtube.com/watch?v=123', $cours->getVideoUrl());
    }
}
