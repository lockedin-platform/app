<?php

namespace App\Tests\Entity;

use App\Entity\Group;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class GroupTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $group = new Group();
        $this->assertInstanceOf(\DateTimeInterface::class, $group->getCreatedAt());
    }

    public function testSetName(): void
    {
        $group = new Group();
        $group->setName('Entrepreneurs Tunisie');
        $this->assertEquals('Entrepreneurs Tunisie', $group->getName());
    }

    public function testSetDescription(): void
    {
        $group = new Group();
        $group->setDescription('Groupe pour entrepreneurs en Tunisie');
        $this->assertEquals('Groupe pour entrepreneurs en Tunisie', $group->getDescription());
    }

    public function testIsPrivateDefaultFalse(): void
    {
        $group = new Group();
        $this->assertFalse($group->isPrivate());
    }

    public function testSetIsPrivate(): void
    {
        $group = new Group();
        $group->setIsPrivate(true);
        $this->assertTrue($group->isPrivate());
    }

    public function testSetGroupAdmin(): void
    {
        $group = new Group();
        $admin = new User();
        $admin->setFirstname('Admin');
        $admin->setLastname('User');
        $admin->setEmail('admin@group.com');
        $group->setGroupAdmin($admin);
        $this->assertSame($admin, $group->getGroupAdmin());
    }

    public function testGetMembersCountZero(): void
    {
        $group = new Group();
        $this->assertEquals(0, $group->getMembersCount());
    }

    public function testHasMemberNullReturnsFalse(): void
    {
        $group = new Group();
        $this->assertFalse($group->hasMember(null));
    }

    public function testHasMemberAdminIsTrue(): void
    {
        $group = new Group();
        $admin = new User();
        $admin->setFirstname('Admin');
        $admin->setLastname('A');
        $admin->setEmail('admin@a.com');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($admin, 1);
        $group->setGroupAdmin($admin);
        $this->assertTrue($group->hasMember($admin));
    }

    public function testHasMemberNonMemberReturnsFalse(): void
    {
        $group = new Group();
        $admin = new User();
        $admin->setFirstname('Admin');
        $admin->setLastname('A');
        $admin->setEmail('admin@a.com');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($admin, 1);
        $group->setGroupAdmin($admin);

        $stranger = new User();
        $stranger->setFirstname('Stranger');
        $stranger->setLastname('S');
        $stranger->setEmail('stranger@s.com');
        $ref2 = new \ReflectionProperty(User::class, 'id');
        $ref2->setValue($stranger, 99);
        $this->assertFalse($group->hasMember($stranger));
    }

    public function testThreadsCollectionEmpty(): void
    {
        $group = new Group();
        $this->assertCount(0, $group->getThreads());
    }

    public function testMembersCollectionEmpty(): void
    {
        $group = new Group();
        $this->assertCount(0, $group->getMembers());
    }
}
