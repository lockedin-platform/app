<?php

namespace App\Tests\Entity;

use App\Entity\MentorshipRequest;
use App\Entity\User;
use App\Entity\Projet;
use PHPUnit\Framework\TestCase;

class MentorshipRequestTest extends TestCase
{
    public function testDefaultStatusIsPending(): void
    {
        $request = new MentorshipRequest();
        $this->assertEquals(MentorshipRequest::STATUS_PENDING, $request->getStatus());
    }

    public function testCreatedAtSetOnConstruct(): void
    {
        $request = new MentorshipRequest();
        $this->assertInstanceOf(\DateTimeInterface::class, $request->getCreatedAt());
    }

    public function testSetEntrepreneur(): void
    {
        $request = new MentorshipRequest();
        $user = new User();
        $user->setFirstname('Ali');
        $user->setLastname('Ent');
        $user->setEmail('ali@ent.com');
        $request->setEntrepreneur($user);
        $this->assertSame($user, $request->getEntrepreneur());
    }

    public function testSetMentor(): void
    {
        $request = new MentorshipRequest();
        $mentor = new User();
        $mentor->setFirstname('Sara');
        $mentor->setLastname('Mentor');
        $mentor->setEmail('sara@mentor.com');
        $request->setMentor($mentor);
        $this->assertSame($mentor, $request->getMentor());
    }

    public function testSetProject(): void
    {
        $request = new MentorshipRequest();
        $projet = new Projet();
        $projet->setTitre('Mon projet');
        $request->setProject($projet);
        $this->assertSame($projet, $request->getProject());
    }

    public function testSetMotivation(): void
    {
        $request = new MentorshipRequest();
        $request->setMotivation('Je souhaite apprendre le marketing digital');
        $this->assertEquals('Je souhaite apprendre le marketing digital', $request->getMotivation());
    }

    public function testSetGoals(): void
    {
        $request = new MentorshipRequest();
        $request->setGoals('Lancer mon produit en 3 mois');
        $this->assertEquals('Lancer mon produit en 3 mois', $request->getGoals());
    }

    public function testSetMatchScore(): void
    {
        $request = new MentorshipRequest();
        $request->setMatchScore(85.5);
        $this->assertEquals(85.5, $request->getMatchScore());
    }

    public function testAutoApprovedDefaultFalse(): void
    {
        $request = new MentorshipRequest();
        $this->assertFalse($request->isAutoApproved());
    }

    public function testSetAutoApproved(): void
    {
        $request = new MentorshipRequest();
        $request->setAutoApproved(true);
        $this->assertTrue($request->isAutoApproved());
    }

    public function testSetStatusAccepted(): void
    {
        $request = new MentorshipRequest();
        $request->setStatus(MentorshipRequest::STATUS_ACCEPTED);
        $this->assertEquals(MentorshipRequest::STATUS_ACCEPTED, $request->getStatus());
    }

    public function testSessionsCollectionEmpty(): void
    {
        $request = new MentorshipRequest();
        $this->assertCount(0, $request->getSessions());
    }

    public function testSetDate(): void
    {
        $request = new MentorshipRequest();
        $date = new \DateTime('2025-07-01');
        $request->setDate($date);
        $this->assertSame($date, $request->getDate());
    }

    public function testSetTime(): void
    {
        $request = new MentorshipRequest();
        $request->setTime('14:00');
        $this->assertEquals('14:00', $request->getTime());
    }
}
