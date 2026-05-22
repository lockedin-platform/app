<?php

namespace App\Tests\Entity;

use App\Entity\Comment;
use App\Entity\Thread;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class CommentTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $comment = new Comment();
        $this->assertInstanceOf(\DateTimeInterface::class, $comment->getCreatedAt());
    }

    public function testSetContent(): void
    {
        $comment = new Comment();
        $comment->setContent('Super discussion !');
        $this->assertEquals('Super discussion !', $comment->getContent());
    }

    public function testSetThread(): void
    {
        $comment = new Comment();
        $thread = new Thread();
        $thread->setTitle('Discussion test');
        $comment->setThread($thread);
        $this->assertSame($thread, $comment->getThread());
    }

    public function testSetUser(): void
    {
        $comment = new Comment();
        $user = new User();
        $user->setFirstname('Ali');
        $user->setLastname('C');
        $user->setEmail('ali@c.com');
        $comment->setUser($user);
        $this->assertSame($user, $comment->getUser());
    }

    public function testIdIsNullByDefault(): void
    {
        $comment = new Comment();
        $this->assertNull($comment->getId());
    }
}
