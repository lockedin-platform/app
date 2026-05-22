<?php

namespace App\Tests\Entity;

use App\Entity\UserFollow;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserFollowTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $follow = new UserFollow();
        $this->assertInstanceOf(\DateTimeInterface::class, $follow->getCreatedAt());
    }

    public function testSetFollower(): void
    {
        $follow = new UserFollow();
        $user = new User();
        $user->setFirstname('Follower');
        $user->setLastname('F');
        $user->setEmail('follower@f.com');
        $follow->setFollower($user);
        $this->assertSame($user, $follow->getFollower());
    }

    public function testSetFollowed(): void
    {
        $follow = new UserFollow();
        $user = new User();
        $user->setFirstname('Followed');
        $user->setLastname('D');
        $user->setEmail('followed@d.com');
        $follow->setFollowed($user);
        $this->assertSame($user, $follow->getFollowed());
    }

    public function testIdIsNullByDefault(): void
    {
        $follow = new UserFollow();
        $this->assertNull($follow->getId());
    }
}
