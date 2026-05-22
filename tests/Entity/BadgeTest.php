<?php

namespace App\Tests\Entity;

use App\Entity\Badge;
use PHPUnit\Framework\TestCase;

class BadgeTest extends TestCase
{
    public function testDefaultRareteIsCommun(): void
    {
        $badge = new Badge();
        $this->assertEquals(Badge::RARETE_COMMUN, $badge->getRarete());
    }

    public function testCreatedAtSetOnConstruct(): void
    {
        $badge = new Badge();
        $this->assertInstanceOf(\DateTimeInterface::class, $badge->getCreatedAt());
    }

    public function testSetNom(): void
    {
        $badge = new Badge();
        $badge->setNom('Premier pas');
        $this->assertEquals('Premier pas', $badge->getNom());
    }

    public function testSetDescription(): void
    {
        $badge = new Badge();
        $badge->setDescription('Obtenu après le premier cours complété');
        $this->assertEquals('Obtenu après le premier cours complété', $badge->getDescription());
    }

    public function testSetIcone(): void
    {
        $badge = new Badge();
        $badge->setIcone('🏆');
        $this->assertEquals('🏆', $badge->getIcone());
    }

    public function testSetConditionObtention(): void
    {
        $badge = new Badge();
        $badge->setConditionObtention('Compléter 5 cours');
        $this->assertEquals('Compléter 5 cours', $badge->getConditionObtention());
    }

    public function testDefaultPointsRequisIsZero(): void
    {
        $badge = new Badge();
        $this->assertEquals(0, $badge->getPointsRequis());
    }

    public function testSetPointsRequis(): void
    {
        $badge = new Badge();
        $badge->setPointsRequis(500);
        $this->assertEquals(500, $badge->getPointsRequis());
    }

    public function testDefaultCoursRequisIsZero(): void
    {
        $badge = new Badge();
        $this->assertEquals(0, $badge->getCoursRequis());
    }

    public function testSetCoursRequis(): void
    {
        $badge = new Badge();
        $badge->setCoursRequis(3);
        $this->assertEquals(3, $badge->getCoursRequis());
    }

    public function testDefaultNiveauRequisIsZero(): void
    {
        $badge = new Badge();
        $this->assertEquals(0, $badge->getNiveauRequis());
    }

    public function testSetNiveauRequis(): void
    {
        $badge = new Badge();
        $badge->setNiveauRequis(5);
        $this->assertEquals(5, $badge->getNiveauRequis());
    }

    public function testDefaultCategorieIsGeneral(): void
    {
        $badge = new Badge();
        $this->assertEquals('Général', $badge->getCategorie());
    }

    public function testIsActifDefaultTrue(): void
    {
        $badge = new Badge();
        $this->assertTrue($badge->isActif());
    }

    public function testSetRareteEpique(): void
    {
        $badge = new Badge();
        $badge->setRarete(Badge::RARETE_EPIQUE);
        $this->assertEquals(Badge::RARETE_EPIQUE, $badge->getRarete());
    }

    public function testSetRareteLegendaire(): void
    {
        $badge = new Badge();
        $badge->setRarete(Badge::RARETE_LEGENDAIRE);
        $this->assertEquals(Badge::RARETE_LEGENDAIRE, $badge->getRarete());
    }
}
