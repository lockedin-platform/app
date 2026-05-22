<?php

namespace App\Tests\Entity;

use App\Entity\MentorAvailability;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class MentorAvailabilityTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $avail = new MentorAvailability();
        $this->assertInstanceOf(\DateTimeInterface::class, $avail->getCreatedAt());
    }

    public function testSetMentor(): void
    {
        $avail = new MentorAvailability();
        $mentor = new User();
        $mentor->setFirstname('Mentor');
        $mentor->setLastname('M');
        $mentor->setEmail('mentor@m.com');
        $avail->setMentor($mentor);
        $this->assertSame($mentor, $avail->getMentor());
    }

    public function testSetDate(): void
    {
        $avail = new MentorAvailability();
        $date = new \DateTime('2025-08-15');
        $avail->setDate($date);
        $this->assertSame($date, $avail->getDate());
    }

    public function testSetStartTime(): void
    {
        $avail = new MentorAvailability();
        $time = new \DateTime('09:00:00');
        $avail->setStartTime($time);
        $this->assertSame($time, $avail->getStartTime());
    }

    public function testSetEndTime(): void
    {
        $avail = new MentorAvailability();
        $time = new \DateTime('17:00:00');
        $avail->setEndTime($time);
        $this->assertSame($time, $avail->getEndTime());
    }

    public function testIdIsNullByDefault(): void
    {
        $avail = new MentorAvailability();
        $this->assertNull($avail->getId());
    }
}
