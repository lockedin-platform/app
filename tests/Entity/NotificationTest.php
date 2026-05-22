<?php

namespace App\Tests\Entity;

use App\Entity\Notification;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    public function testCreatedAtSetOnConstruct(): void
    {
        $notif = new Notification();
        $this->assertInstanceOf(\DateTimeInterface::class, $notif->getCreatedAt());
    }

    public function testDefaultTypeIsInfo(): void
    {
        $notif = new Notification();
        $this->assertEquals('INFO', $notif->getType());
    }

    public function testDefaultIsReadFalse(): void
    {
        $notif = new Notification();
        $this->assertFalse($notif->isRead());
    }

    public function testSetTitle(): void
    {
        $notif = new Notification();
        $notif->setTitle('Nouveau message');
        $this->assertEquals('Nouveau message', $notif->getTitle());
    }

    public function testSetMessage(): void
    {
        $notif = new Notification();
        $notif->setMessage('Vous avez reçu un nouveau message de Ali');
        $this->assertEquals('Vous avez reçu un nouveau message de Ali', $notif->getMessage());
    }

    public function testSetUser(): void
    {
        $notif = new Notification();
        $user = new User();
        $user->setFirstname('Notif');
        $user->setLastname('U');
        $user->setEmail('notif@u.com');
        $notif->setUser($user);
        $this->assertSame($user, $notif->getUser());
    }

    public function testSetRead(): void
    {
        $notif = new Notification();
        $notif->setRead(true);
        $this->assertTrue($notif->isRead());
    }

    public function testSetTypeWarning(): void
    {
        $notif = new Notification();
        $notif->setType('WARNING');
        $this->assertEquals('WARNING', $notif->getType());
    }

    public function testSetActionUrl(): void
    {
        $notif = new Notification();
        $notif->setActionUrl('/contract/5');
        $this->assertEquals('/contract/5', $notif->getActionUrl());
    }

    public function testSetActionLabel(): void
    {
        $notif = new Notification();
        $notif->setActionLabel('Voir le contrat');
        $this->assertEquals('Voir le contrat', $notif->getActionLabel());
    }

    public function testGetTypeIconInfo(): void
    {
        $notif = new Notification();
        $this->assertEquals('info-circle-fill', $notif->getTypeIcon());
    }

    public function testGetTypeIconMessage(): void
    {
        $notif = new Notification();
        $notif->setType('MESSAGE');
        $this->assertEquals('chat-left-text-fill', $notif->getTypeIcon());
    }

    public function testGetTypeColorDanger(): void
    {
        $notif = new Notification();
        $notif->setType('DANGER');
        $this->assertEquals('danger', $notif->getTypeColor());
    }

    public function testGetTypeColorSuccess(): void
    {
        $notif = new Notification();
        $notif->setType('SUCCESS');
        $this->assertEquals('success', $notif->getTypeColor());
    }
}
