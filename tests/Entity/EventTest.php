<?php

namespace App\Tests\Entity;

use App\Entity\Event;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $event = new Event();
        $this->assertInstanceOf(\DateTimeInterface::class, $event->getCreatedAt());
    }

    public function testSetTitle(): void
    {
        $event = new Event();
        $event->setTitle('Conférence Startup Tunisie');
        $this->assertEquals('Conférence Startup Tunisie', $event->getTitle());
    }

    public function testSetDescription(): void
    {
        $event = new Event();
        $event->setDescription('Conférence annuelle des startups');
        $this->assertEquals('Conférence annuelle des startups', $event->getDescription());
    }

    public function testSetEventDate(): void
    {
        $event = new Event();
        $date = new \DateTime('+30 days');
        $event->setEventDate($date);
        $this->assertSame($date, $event->getEventDate());
    }

    public function testSetCapacity(): void
    {
        $event = new Event();
        $event->setCapacity(100);
        $this->assertEquals(100, $event->getCapacity());
    }

    public function testHasCapacityWhenZeroMeansUnlimited(): void
    {
        $event = new Event();
        $event->setCapacity(0);
        $this->assertTrue($event->hasCapacity());
    }

    public function testHasCapacityWithSpace(): void
    {
        $event = new Event();
        $event->setCapacity(50);
        // No participants yet, so has capacity
        $this->assertTrue($event->hasCapacity());
    }

    public function testSetCreatedBy(): void
    {
        $event = new Event();
        $user = new User();
        $user->setFirstname('Organisateur');
        $user->setLastname('Test');
        $user->setEmail('org@test.com');
        $event->setCreatedBy($user);
        $this->assertSame($user, $event->getCreatedBy());
    }

    public function testGetParticipantsCountZero(): void
    {
        $event = new Event();
        $this->assertEquals(0, $event->getParticipantsCount());
    }

    public function testHasParticipantNullReturnsFalse(): void
    {
        $event = new Event();
        $this->assertFalse($event->hasParticipant(null));
    }

    public function testGetParticipantForUserNullReturnsNull(): void
    {
        $event = new Event();
        $this->assertNull($event->getParticipantForUser(null));
    }

    public function testParticipantsCollectionEmpty(): void
    {
        $event = new Event();
        $this->assertCount(0, $event->getParticipants());
    }

    public function testDefaultCapacityIsZero(): void
    {
        $event = new Event();
        $this->assertEquals(0, $event->getCapacity());
    }
}
