<?php

namespace App\Tests\Entity;

use App\Entity\GroupMember;
use App\Entity\Group;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class GroupMemberTest extends TestCase
{
    public function testJoinedAtSetOnConstruct(): void
    {
        $gm = new GroupMember();
        $this->assertInstanceOf(\DateTimeInterface::class, $gm->getJoinedAt());
    }

    public function testSetGroup(): void
    {
        $gm = new GroupMember();
        $group = new Group();
        $group->setName('Dev Tunisie');
        $gm->setGroup($group);
        $this->assertSame($group, $gm->getGroup());
    }

    public function testSetUser(): void
    {
        $gm = new GroupMember();
        $user = new User();
        $user->setFirstname('Member');
        $user->setLastname('M');
        $user->setEmail('member@m.com');
        $gm->setUser($user);
        $this->assertSame($user, $gm->getUser());
    }

    public function testIdIsNullByDefault(): void
    {
        $gm = new GroupMember();
        $this->assertNull($gm->getId());
    }
}
