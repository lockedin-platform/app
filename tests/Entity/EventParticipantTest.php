<?php

namespace App\Tests\Entity;

use App\Entity\EventParticipant;
use App\Entity\Event;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class EventParticipantTest extends TestCase
{
    public function testIdIsNullByDefault(): void
    {
        $ep = new EventParticipant();
        $this->assertNull($ep->getId());
    }

    public function testSetEvent(): void
    {
        $ep = new EventParticipant();
        $event = new Event();
        $event->setTitle('Conférence');
        $ep->setEvent($event);
        $this->assertSame($event, $ep->getEvent());
    }

    public function testSetUser(): void
    {
        $ep = new EventParticipant();
        $user = new User();
        $user->setFirstname('P');
        $user->setLastname('U');
        $user->setEmail('p@u.com');
        $ep->setUser($user);
        $this->assertSame($user, $ep->getUser());
    }
}
