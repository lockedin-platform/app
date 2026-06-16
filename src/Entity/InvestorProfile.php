<?php

namespace App\Entity;

use App\Repository\InvestorProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvestorProfileRepository::class)]
#[ORM\Table(name: 'investor_profile')]
#[ORM\HasLifecycleCallbacks]
class InvestorProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $preferredSectors = null;

    // Model 2 v0 — geography preference (matched against project.pays). Null = no preference.
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $preferredCountry = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 5])]
    private int $riskTolerance = 5;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, options: ['default' => 0])]
    #[Assert\LessThan(propertyPath: 'budgetMax', message: 'Le budget minimum doit etre strictement inferieur au budget maximum.')]
    private string $budgetMin = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, options: ['default' => 10000000])]
    private string $budgetMax = '10000000';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 12])]
    private int $horizonMonths = 12;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getPreferredCountry(): ?string { return $this->preferredCountry; }
    public function setPreferredCountry(?string $preferredCountry): static { $this->preferredCountry = $preferredCountry; return $this; }

    public function getPreferredSectors(): ?string { return $this->preferredSectors; }
    public function setPreferredSectors(?string $v): static { $this->preferredSectors = $v; return $this; }

    public function getSectorArray(): array
    {
        if ($this->preferredSectors === null || trim($this->preferredSectors) === '') {
            return [];
        }
        return array_map('trim', explode(',', $this->preferredSectors));
    }

    public function getRiskTolerance(): int { return $this->riskTolerance; }
    public function setRiskTolerance(int $v): static { $this->riskTolerance = $v; return $this; }

    public function getBudgetMin(): string { return $this->budgetMin; }
    public function setBudgetMin(string $v): static { $this->budgetMin = $v; return $this; }

    public function getBudgetMax(): string { return $this->budgetMax; }
    public function setBudgetMax(string $v): static { $this->budgetMax = $v; return $this; }

    public function getHorizonMonths(): int { return $this->horizonMonths; }
    public function setHorizonMonths(int $v): static { $this->horizonMonths = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
}
