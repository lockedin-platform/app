<?php

namespace App\Tests\Entity;

use App\Entity\UserConnection;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserConnectionTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $conn = new UserConnection();
        $this->assertInstanceOf(\DateTimeInterface::class, $conn->getCreatedAt());
    }

    public function testSetFollower(): void
    {
        $conn = new UserConnection();
        $user = new User();
        $user->setFirstname('Conn');
        $user->setLastname('A');
        $user->setEmail('conn@a.com');
        $conn->setFollower($user);
        $this->assertSame($user, $conn->getFollower());
    }

    public function testSetFollowed(): void
    {
        $conn = new UserConnection();
        $user = new User();
        $user->setFirstname('Conn');
        $user->setLastname('B');
        $user->setEmail('conn@b.com');
        $conn->setFollowed($user);
        $this->assertSame($user, $conn->getFollowed());
    }

    public function testIdIsNullByDefault(): void
    {
        $conn = new UserConnection();
        $this->assertNull($conn->getId());
    }
}
