<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testFullNameCombinesFirstAndLast(): void
    {
        $user = new User();
        $user->setFirstname('Ahmed');
        $user->setLastname('Ben Ali');
        $this->assertEquals('Ahmed Ben Ali', $user->getFullName());
    }

    public function testFullNameNullFields(): void
    {
        $user = new User();
        $fullName = $user->getFullName();
        $this->assertIsString($fullName);
    }

    public function testDefaultRoleIsEntrepreneur(): void
    {
        $user = new User();
        $this->assertEquals('ENTREPRENEUR', $user->getRole());
    }

    public function testGetRolesContainsRoleUser(): void
    {
        $user = new User();
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testSetRoleMentor(): void
    {
        $user = new User();
        $user->setRole('MENTOR');
        $this->assertEquals('MENTOR', $user->getRole());
        $this->assertContains('ROLE_MENTOR', $user->getRoles());
    }

    public function testSetEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $this->assertEquals('test@example.com', $user->getEmail());
        $this->assertEquals('test@example.com', $user->getUserIdentifier());
    }

    public function testIsBannedDefaultFalse(): void
    {
        $user = new User();
        $this->assertFalse($user->getIsBanned());
    }

    public function testSetBanned(): void
    {
        $user = new User();
        $user->setIsBanned(true);
        $this->assertTrue($user->getIsBanned());
    }
}
