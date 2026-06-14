<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create default admin user if not exists')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->upsertAdmin('lockedin.admin@gmail.com', 'LockedIn@2026', $output);

        // Also handle env-based admin
        $email = $_ENV['ADMIN_EMAIL'] ?? null;
        $password = $_ENV['ADMIN_PASSWORD'] ?? null;
        if ($email && $email !== 'lockedin.admin@gmail.com') {
            $this->upsertAdmin($email, $password ?? 'LockedIn@2026', $output);
        }

        return Command::SUCCESS;
    }

    private function upsertAdmin(string $email, string $password, OutputInterface $output): void
    {
        $repo = $this->em->getRepository(User::class);
        $user = $repo->findOneBy(['email' => $email]);

        if ($user) {
            $user->setRole(User::ROLE_ADMIN);
            $user->setVerified(true);
            $user->setIsActive(true);
            $user->setIsBanned(false);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->flush();
            $output->writeln("ADMIN UPDATED: $email / $password");
        } else {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstname('Admin');
            $user->setLastname('LockedIn');
            $user->setRole(User::ROLE_ADMIN);
            $user->setVerified(true);
            $user->setIsActive(true);
            $user->setIsBanned(false);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $this->em->flush();
            $output->writeln("ADMIN CREATED: $email / $password");
        }
    }
}
