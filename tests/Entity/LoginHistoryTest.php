<?php

namespace App\Tests\Entity;

use App\Entity\LoginHistory;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class LoginHistoryTest extends TestCase
{
    public function testLoginTimeSetOnConstruct(): void
    {
        $lh = new LoginHistory();
        $this->assertInstanceOf(\DateTimeInterface::class, $lh->getLoginTime());
    }

    public function testDefaultLoginMethodIsPassword(): void
    {
        $lh = new LoginHistory();
        $this->assertEquals('PASSWORD', $lh->getLoginMethod());
    }

    public function testDefaultSuccessIsTrue(): void
    {
        $lh = new LoginHistory();
        $this->assertTrue($lh->isSuccess());
    }

    public function testSetUser(): void
    {
        $lh = new LoginHistory();
        $user = new User();
        $user->setFirstname('Log');
        $user->setLastname('U');
        $user->setEmail('log@u.com');
        $lh->setUser($user);
        $this->assertSame($user, $lh->getUser());
    }

    public function testSetIpAddress(): void
    {
        $lh = new LoginHistory();
        $lh->setIpAddress('192.168.1.100');
        $this->assertEquals('192.168.1.100', $lh->getIpAddress());
    }

    public function testSetDeviceInfo(): void
    {
        $lh = new LoginHistory();
        $lh->setDeviceInfo('Mozilla/5.0 Chrome/120');
        $this->assertEquals('Mozilla/5.0 Chrome/120', $lh->getDeviceInfo());
    }

    public function testSetLoginMethodGoogle(): void
    {
        $lh = new LoginHistory();
        $lh->setLoginMethod('GOOGLE');
        $this->assertEquals('GOOGLE', $lh->getLoginMethod());
    }

    public function testSetSuccessFalse(): void
    {
        $lh = new LoginHistory();
        $lh->setSuccess(false);
        $this->assertFalse($lh->isSuccess());
    }

    public function testSetLocation(): void
    {
        $lh = new LoginHistory();
        $lh->setLocation('Tunis, Tunisie');
        $this->assertEquals('Tunis, Tunisie', $lh->getLocation());
    }
}
