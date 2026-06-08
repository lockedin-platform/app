<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'This email is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ADMIN';
    public const ROLE_ENTREPRENEUR = 'ENTREPRENEUR';
    public const ROLE_MENTOR = 'MENTOR';
    public const ROLE_INVESTISSEUR = 'INVESTISSEUR';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private ?string $firstname = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private ?string $lastname = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^\+?[0-9\s\-]{7,20}$/', message: 'Invalid phone number.')]
    private ?string $phone = null;

    #[ORM\Column(length: 20)]
    private string $role = self::ROLE_ENTREPRENEUR;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: 'La bio ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $bio = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $profilePicture = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200, maxMessage: 'Le nom de l\'entreprise ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $companyName = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url]
    private ?string $linkedinUrl = null;

    #[ORM\Column(length: 300, nullable: true)]
    #[Assert\Length(max: 300, maxMessage: 'L\'adresse ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $address = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Assert\LessThan('today', message: 'La date de naissance doit être dans le passé.')]
    private ?\DateTimeInterface $dateOfBirth = null;

    #[ORM\Column]
    private bool $verified = false;

    #[ORM\Column]
    private bool $phoneVerified = false;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isBanned = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleProviderId = null;

    #[ORM\Column]
    private bool $faceRegistered = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $faceEncoding = null;

    #[ORM\Column(length: 10, options: ['default' => 'light'])]
    private string $preferredTheme = 'light';

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    private string $preferredLanguage = 'fr';

    #[ORM\Column(length: 5, options: ['default' => 'EUR'])]
    private string $preferredCurrency = 'EUR';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $verificationCode = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $verificationCodeExpiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $resetTokenExpiresAt = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $loginAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column]
    private int $totalXp = 0;

    #[ORM\OneToMany(targetEntity: Projet::class, mappedBy: 'user')]
    private Collection $projets;

    #[ORM\OneToMany(targetEntity: Progression::class, mappedBy: 'user')]
    private Collection $progressions;

    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'user')]
    private Collection $posts;

    // ✅ NOUVEAU — Relation badges
    #[ORM\ManyToMany(targetEntity: Badge::class)]
    #[ORM\JoinTable(name: 'user_badge')]
    private Collection $badges;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->projets = new ArrayCollection();
        $this->progressions = new ArrayCollection();
        $this->posts = new ArrayCollection();
        $this->badges = new ArrayCollection(); // ✅ NOUVEAU
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getFirstname(): ?string { return $this->firstname; }
    public function setFirstname(string $firstname): static { $this->firstname = $firstname; return $this; }
    public function getLastname(): ?string { return $this->lastname; }
    public function setLastname(string $lastname): static { $this->lastname = $lastname; return $this; }
    public function getFullName(): string { return $this->firstname . ' ' . $this->lastname; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone; return $this; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $role): static { $this->role = $role; return $this; }
    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): static { $this->bio = $bio; return $this; }
    public function getProfilePicture(): ?string { return $this->profilePicture; }
    public function setProfilePicture(?string $profilePicture): static { $this->profilePicture = $profilePicture; return $this; }
    public function getCompanyName(): ?string { return $this->companyName; }
    public function setCompanyName(?string $companyName): static { $this->companyName = $companyName; return $this; }
    public function getLinkedinUrl(): ?string { return $this->linkedinUrl; }
    public function setLinkedinUrl(?string $linkedinUrl): static { $this->linkedinUrl = $linkedinUrl; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): static { $this->address = $address; return $this; }
    public function getDateOfBirth(): ?\DateTimeInterface { return $this->dateOfBirth; }
    public function setDateOfBirth(?\DateTimeInterface $dateOfBirth): static { $this->dateOfBirth = $dateOfBirth; return $this; }
    public function isVerified(): bool { return $this->verified; }
    public function setVerified(bool $verified): static { $this->verified = $verified; return $this; }
    public function isPhoneVerified(): bool { return $this->phoneVerified; }
    public function setPhoneVerified(bool $phoneVerified): static { $this->phoneVerified = $phoneVerified; return $this; }
    public function getIsActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
    public function getIsBanned(): bool { return $this->isBanned; }
    public function setIsBanned(bool $isBanned): static { $this->isBanned = $isBanned; return $this; }
    public function getGoogleProviderId(): ?string { return $this->googleProviderId; }
    public function setGoogleProviderId(?string $googleProviderId): static { $this->googleProviderId = $googleProviderId; return $this; }
    public function isFaceRegistered(): bool { return $this->faceRegistered; }
    public function setFaceRegistered(bool $faceRegistered): static { $this->faceRegistered = $faceRegistered; return $this; }
    public function getFaceEncoding(): ?string { return $this->faceEncoding; }
    public function setFaceEncoding(?string $faceEncoding): static { $this->faceEncoding = $faceEncoding; return $this; }
    public function getPreferredTheme(): string { return $this->preferredTheme; }
    public function setPreferredTheme(string $preferredTheme): static { $this->preferredTheme = $preferredTheme; return $this; }
    public function getPreferredLanguage(): string { return $this->preferredLanguage; }
    public function setPreferredLanguage(string $preferredLanguage): static { $this->preferredLanguage = $preferredLanguage; return $this; }
    public function getPreferredCurrency(): string { return $this->preferredCurrency; }
    public function setPreferredCurrency(string $preferredCurrency): static { $this->preferredCurrency = $preferredCurrency; return $this; }
    public function getVerificationCode(): ?string { return $this->verificationCode; }
    public function setVerificationCode(?string $verificationCode): static { $this->verificationCode = $verificationCode; return $this; }
    public function getVerificationCodeExpiresAt(): ?\DateTimeInterface { return $this->verificationCodeExpiresAt; }
    public function setVerificationCodeExpiresAt(?\DateTimeInterface $dt): static { $this->verificationCodeExpiresAt = $dt; return $this; }
    public function getResetToken(): ?string { return $this->resetToken; }
    public function setResetToken(?string $resetToken): static { $this->resetToken = $resetToken; return $this; }
    public function getResetTokenExpiresAt(): ?\DateTimeInterface { return $this->resetTokenExpiresAt; }
    public function setResetTokenExpiresAt(?\DateTimeInterface $dt): static { $this->resetTokenExpiresAt = $dt; return $this; }
    public function getLoginAttempts(): int { return $this->loginAttempts; }
    public function setLoginAttempts(int $loginAttempts): static { $this->loginAttempts = $loginAttempts; return $this; }
    public function incrementLoginAttempts(): static { $this->loginAttempts++; return $this; }
    public function resetLoginAttempts(): static { $this->loginAttempts = 0; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    public function getTotalXp(): int { return $this->totalXp; }
    public function setTotalXp(int $totalXp): static { $this->totalXp = $totalXp; return $this; }

    /** @return Collection<int, Projet> */
    public function getProjets(): Collection { return $this->projets; }
    /** @return Collection<int, Progression> */
    public function getProgressions(): Collection { return $this->progressions; }
    /** @return Collection<int, Post> */
    public function getPosts(): Collection { return $this->posts; }

    // ✅ NOUVEAU — Badges
    /** @return Collection<int, Badge> */
    public function getBadges(): Collection { return $this->badges; }

    public function addBadge(Badge $badge): static
    {
        if (!$this->badges->contains($badge)) {
            $this->badges->add($badge);
        }
        return $this;
    }

    public function hasBadge(Badge $badge): bool
    {
        return $this->badges->contains($badge);
    }

    // UserInterface
    public function getRoles(): array
    {
        return ['ROLE_' . $this->role, 'ROLE_USER'];
    }

    public function getUserIdentifier(): string { return (string) $this->email; }
    public function eraseCredentials(): void {}
}