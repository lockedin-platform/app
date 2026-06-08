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
        $email = $_ENV['ADMIN_EMAIL'] ?? 'admin@lockedin.app';
        $password = $_ENV['ADMIN_PASSWORD'] ?? 'LockedIn2026!';

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing) {
            $output->writeln('Admin user already exists — skipping.');
            return Command::SUCCESS;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstname('Admin');
        $user->setLastname('LockedIn');
        $user->setRole('ROLE_ADMIN');
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln("Admin created: $email / $password");

        return Command::SUCCESS;
    }
}
