<?php

namespace App\Tests\Entity;

use App\Entity\Post;
use App\Entity\PostReaction;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class PostTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $post = new Post();
        $this->assertInstanceOf(\DateTimeInterface::class, $post->getCreatedAt());
    }

    public function testSetContent(): void
    {
        $post = new Post();
        $post->setContent('Mon premier post communautaire');
        $this->assertEquals('Mon premier post communautaire', $post->getContent());
    }

    public function testSetUser(): void
    {
        $post = new Post();
        $user = new User();
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail('test@user.com');
        $post->setUser($user);
        $this->assertSame($user, $post->getUser());
    }

    public function testSetImageUrl(): void
    {
        $post = new Post();
        $post->setImageUrl('https://example.com/image.jpg');
        $this->assertEquals('https://example.com/image.jpg', $post->getImageUrl());
    }

    public function testReactionsCollectionEmpty(): void
    {
        $post = new Post();
        $this->assertCount(0, $post->getReactions());
    }

    public function testGetReactionsCountZero(): void
    {
        $post = new Post();
        $this->assertEquals(0, $post->getReactionsCount());
    }

    public function testGetReactionSummaryAllZeros(): void
    {
        $post = new Post();
        $summary = $post->getReactionSummary();
        $this->assertArrayHasKey('LIKE', $summary);
        $this->assertArrayHasKey('LOVE', $summary);
        $this->assertArrayHasKey('HAHA', $summary);
        $this->assertArrayHasKey('WOW', $summary);
        $this->assertArrayHasKey('SAD', $summary);
        $this->assertArrayHasKey('ANGRY', $summary);
        $this->assertEquals(0, $summary['LIKE']);
        $this->assertEquals(0, $summary['LOVE']);
    }

    public function testGetReactionTypeForUserNull(): void
    {
        $post = new Post();
        $this->assertNull($post->getReactionTypeForUser(null));
    }

    public function testGetReactionCountForTypeZero(): void
    {
        $post = new Post();
        $this->assertEquals(0, $post->getReactionCountForType('LIKE'));
    }
}
