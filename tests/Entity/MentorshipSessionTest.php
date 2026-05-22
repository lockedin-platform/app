<?php

namespace App\Tests\Entity;

use App\Entity\MentorshipSession;
use App\Entity\MentorshipRequest;
use PHPUnit\Framework\TestCase;

class MentorshipSessionTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $session = new MentorshipSession();
        $this->assertInstanceOf(\DateTimeInterface::class, $session->getCreatedAt());
    }

    public function testDefaultStatusIsScheduled(): void
    {
        $session = new MentorshipSession();
        $this->assertEquals(MentorshipSession::STATUS_SCHEDULED, $session->getStatus());
    }

    public function testSetMentorshipRequest(): void
    {
        $session = new MentorshipSession();
        $request = new MentorshipRequest();
        $session->setMentorshipRequest($request);
        $this->assertSame($request, $session->getMentorshipRequest());
    }

    public function testSetScheduledAt(): void
    {
        $session = new MentorshipSession();
        $date = new \DateTime('+7 days');
        $session->setScheduledAt($date);
        $this->assertSame($date, $session->getScheduledAt());
    }

    public function testSetDurationMinutes(): void
    {
        $session = new MentorshipSession();
        $session->setDurationMinutes(60);
        $this->assertEquals(60, $session->getDurationMinutes());
    }

    public function testSetStatusCompleted(): void
    {
        $session = new MentorshipSession();
        $session->setStatus(MentorshipSession::STATUS_COMPLETED);
        $this->assertEquals(MentorshipSession::STATUS_COMPLETED, $session->getStatus());
    }

    public function testSetStatusCancelled(): void
    {
        $session = new MentorshipSession();
        $session->setStatus(MentorshipSession::STATUS_CANCELLED);
        $this->assertEquals(MentorshipSession::STATUS_CANCELLED, $session->getStatus());
    }

    public function testSetMeetingLink(): void
    {
        $session = new MentorshipSession();
        $session->setMeetingLink('https://meet.google.com/abc-def-ghi');
        $this->assertEquals('https://meet.google.com/abc-def-ghi', $session->getMeetingLink());
    }

    public function testSetMentorFeedback(): void
    {
        $session = new MentorshipSession();
        $session->setMentorFeedback('Très bonne session, entrepreneur motivé');
        $this->assertEquals('Très bonne session, entrepreneur motivé', $session->getMentorFeedback());
    }

    public function testSetEntrepreneurFeedback(): void
    {
        $session = new MentorshipSession();
        $session->setEntrepreneurFeedback('Conseils précieux, merci !');
        $this->assertEquals('Conseils précieux, merci !', $session->getEntrepreneurFeedback());
    }

    public function testSetMentorRating(): void
    {
        $session = new MentorshipSession();
        $session->setMentorRating(5);
        $this->assertEquals(5, $session->getMentorRating());
    }

    public function testSetEntrepreneurRating(): void
    {
        $session = new MentorshipSession();
        $session->setEntrepreneurRating(4);
        $this->assertEquals(4, $session->getEntrepreneurRating());
    }
}
