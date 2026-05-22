<?php

namespace App\Tests\Entity;

use App\Entity\CoursComment;
use App\Entity\Cours;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class CoursCommentTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $comment = new CoursComment();
        $this->assertInstanceOf(\DateTimeInterface::class, $comment->getCreatedAt());
    }

    public function testUpdatedAtSetOnConstruct(): void
    {
        $comment = new CoursComment();
        $this->assertInstanceOf(\DateTimeInterface::class, $comment->getUpdatedAt());
    }

    public function testSetContenu(): void
    {
        $comment = new CoursComment();
        $comment->setContenu('Excellent cours, très bien expliqué');
        $this->assertEquals('Excellent cours, très bien expliqué', $comment->getContenu());
    }

    public function testSetRating(): void
    {
        $comment = new CoursComment();
        $comment->setRating('4.5');
        $this->assertEquals('4.5', $comment->getRating());
    }

    public function testSetRatingNull(): void
    {
        $comment = new CoursComment();
        $comment->setRating(null);
        $this->assertNull($comment->getRating());
    }

    public function testSetCours(): void
    {
        $comment = new CoursComment();
        $cours = new Cours();
        $cours->setTitre('PHP Basics');
        $comment->setCours($cours);
        $this->assertSame($cours, $comment->getCours());
    }

    public function testSetUser(): void
    {
        $comment = new CoursComment();
        $user = new User();
        $user->setFirstname('Student');
        $user->setLastname('X');
        $user->setEmail('student@x.com');
        $comment->setUser($user);
        $this->assertSame($user, $comment->getUser());
    }
}
