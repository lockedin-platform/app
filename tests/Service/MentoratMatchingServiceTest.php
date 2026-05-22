<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\MentoratMatchingService;
use PHPUnit\Framework\TestCase;

class MentoratMatchingServiceTest extends TestCase
{
    private MentoratMatchingService $service;

    protected function setUp(): void
    {
        $this->service = new MentoratMatchingService();
    }

    public function testCalculateMatchScoreSameSector(): void
    {
        $entrepreneur = new User();
        $entrepreneur->setFirstname('Ali');
        $entrepreneur->setLastname('Test');
        $entrepreneur->setEmail('ali@test.com');
        $entrepreneur->setBio('développeur technologie innovation startup');

        $mentor = new User();
        $mentor->setFirstname('Sara');
        $mentor->setLastname('Mentor');
        $mentor->setEmail('sara@test.com');
        $mentor->setBio('expert technologie innovation développement logiciel');
        $mentor->setCompanyName('TechCorp');

        $score = $this->service->calculateMatchScore($entrepreneur, $mentor, 'Technologie');
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testCalculateMatchScoreNoOverlap(): void
    {
        $entrepreneur = new User();
        $entrepreneur->setFirstname('Ali');
        $entrepreneur->setLastname('Test');
        $entrepreneur->setEmail('ali@test.com');
        $entrepreneur->setBio('agriculture céréales blé');

        $mentor = new User();
        $mentor->setFirstname('Sara');
        $mentor->setLastname('Mentor');
        $mentor->setEmail('sara@test.com');
        $mentor->setBio('cryptographie blockchain web3');

        $score = $this->service->calculateMatchScore($entrepreneur, $mentor, 'Agriculture');
        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0, $score);
    }

    public function testCalculateMatchScoreEmptyBios(): void
    {
        $entrepreneur = new User();
        $entrepreneur->setFirstname('A');
        $entrepreneur->setLastname('B');
        $entrepreneur->setEmail('a@b.com');

        $mentor = new User();
        $mentor->setFirstname('C');
        $mentor->setLastname('D');
        $mentor->setEmail('c@d.com');

        $score = $this->service->calculateMatchScore($entrepreneur, $mentor);
        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0, $score);
    }

    public function testCalculateMatchScoreWithProjectSector(): void
    {
        $entrepreneur = new User();
        $entrepreneur->setFirstname('Ali');
        $entrepreneur->setLastname('E');
        $entrepreneur->setEmail('ali2@test.com');
        $entrepreneur->setBio('finance investissement banque');

        $mentor = new User();
        $mentor->setFirstname('Karim');
        $mentor->setLastname('M');
        $mentor->setEmail('karim@test.com');
        $mentor->setBio('expert finance microfinance investissement');

        $score = $this->service->calculateMatchScore($entrepreneur, $mentor, 'Finance');
        $this->assertGreaterThan(0, $score);
    }

    public function testCalculateMatchScoreIsFloat(): void
    {
        $entrepreneur = new User();
        $entrepreneur->setFirstname('X');
        $entrepreneur->setLastname('Y');
        $entrepreneur->setEmail('x@y.com');
        $entrepreneur->setBio('marketing digital');

        $mentor = new User();
        $mentor->setFirstname('Z');
        $mentor->setLastname('W');
        $mentor->setEmail('z@w.com');
        $mentor->setBio('marketing digital réseaux sociaux');

        $score = $this->service->calculateMatchScore($entrepreneur, $mentor);
        $this->assertIsFloat($score);
    }

    public function testCalculateMatchScoreMaxIs100(): void
    {
        $entrepreneur = new User();
        $entrepreneur->setFirstname('Same');
        $entrepreneur->setLastname('Bio');
        $entrepreneur->setEmail('same@bio.com');
        $entrepreneur->setBio('technologie développement logiciel programmation informatique cloud devops');
        $entrepreneur->setCompanyName('TechCo');

        $mentor = new User();
        $mentor->setFirstname('Same');
        $mentor->setLastname('Bio');
        $mentor->setEmail('same2@bio.com');
        $mentor->setBio('technologie développement logiciel programmation informatique cloud devops');
        $mentor->setCompanyName('TechCo');

        $score = $this->service->calculateMatchScore($entrepreneur, $mentor, 'technologie développement');
        $this->assertLessThanOrEqual(100, $score);
    }
}
