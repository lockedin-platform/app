<?php

namespace App\Tests\Entity;

use App\Entity\Thread;
use App\Entity\Group;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ThreadTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $thread = new Thread();
        $this->assertInstanceOf(\DateTimeInterface::class, $thread->getCreatedAt());
    }

    public function testCommentsCollectionEmpty(): void
    {
        $thread = new Thread();
        $this->assertCount(0, $thread->getComments());
    }

    public function testSetTitle(): void
    {
        $thread = new Thread();
        $thread->setTitle('Comment lancer une startup ?');
        $this->assertEquals('Comment lancer une startup ?', $thread->getTitle());
    }

    public function testSetContent(): void
    {
        $thread = new Thread();
        $thread->setContent('Je cherche des conseils pour lancer mon projet');
        $this->assertEquals('Je cherche des conseils pour lancer mon projet', $thread->getContent());
    }

    public function testSetGroup(): void
    {
        $thread = new Thread();
        $group = new Group();
        $group->setName('Startups TN');
        $thread->setGroup($group);
        $this->assertSame($group, $thread->getGroup());
    }

    public function testSetUser(): void
    {
        $thread = new Thread();
        $user = new User();
        $user->setFirstname('Thread');
        $user->setLastname('Author');
        $user->setEmail('thread@auth.com');
        $thread->setUser($user);
        $this->assertSame($user, $thread->getUser());
    }

    public function testIdIsNullByDefault(): void
    {
        $thread = new Thread();
        $this->assertNull($thread->getId());
    }
}
