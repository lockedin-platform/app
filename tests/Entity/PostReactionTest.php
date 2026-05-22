<?php

namespace App\Tests\Entity;

use App\Entity\PostReaction;
use App\Entity\Post;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class PostReactionTest extends TestCase
{
    public function testDefaultReactionTypeIsLike(): void
    {
        $reaction = new PostReaction();
        $this->assertEquals(PostReaction::TYPE_LIKE, $reaction->getReactionType());
    }

    public function testSetReactionType(): void
    {
        $reaction = new PostReaction();
        $reaction->setReactionType(PostReaction::TYPE_LOVE);
        $this->assertEquals(PostReaction::TYPE_LOVE, $reaction->getReactionType());
    }

    public function testSetPost(): void
    {
        $reaction = new PostReaction();
        $post = new Post();
        $post->setContent('Test post');
        $reaction->setPost($post);
        $this->assertSame($post, $reaction->getPost());
    }

    public function testSetUser(): void
    {
        $reaction = new PostReaction();
        $user = new User();
        $user->setFirstname('R');
        $user->setLastname('U');
        $user->setEmail('r@u.com');
        $reaction->setUser($user);
        $this->assertSame($user, $reaction->getUser());
    }

    public function testGetEmojiLike(): void
    {
        $reaction = new PostReaction();
        $reaction->setReactionType(PostReaction::TYPE_LIKE);
        $this->assertEquals('👍', $reaction->getEmoji());
    }

    public function testGetEmojiLove(): void
    {
        $reaction = new PostReaction();
        $reaction->setReactionType(PostReaction::TYPE_LOVE);
        $this->assertEquals('❤️', $reaction->getEmoji());
    }

    public function testGetEmojiHaha(): void
    {
        $reaction = new PostReaction();
        $reaction->setReactionType(PostReaction::TYPE_HAHA);
        $this->assertEquals('😂', $reaction->getEmoji());
    }

    public function testGetEmojiWow(): void
    {
        $reaction = new PostReaction();
        $reaction->setReactionType(PostReaction::TYPE_WOW);
        $this->assertEquals('😮', $reaction->getEmoji());
    }

    public function testGetEmojiSad(): void
    {
        $reaction = new PostReaction();
        $reaction->setReactionType(PostReaction::TYPE_SAD);
        $this->assertEquals('😢', $reaction->getEmoji());
    }

    public function testGetEmojiAngry(): void
    {
        $reaction = new PostReaction();
        $reaction->setReactionType(PostReaction::TYPE_ANGRY);
        $this->assertEquals('😡', $reaction->getEmoji());
    }
}
