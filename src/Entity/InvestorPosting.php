<?php
namespace App\Entity;

use App\Repository\InvestorPostingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvestorPostingRepository::class)]
#[ORM\Table(name: 'investor_posting')]
#[ORM\HasLifecycleCallbacks]
class InvestorPosting
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_CLOSED = 'CLOSED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'posted_by_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $postedBy = null;

    #[ORM\Column(length: 100)]
    private string $sector = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $budgetMin = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $budgetMax = '0';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deadline = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: InvestorApplication::class, mappedBy: 'posting', cascade: ['remove'])]
    private Collection $applications;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->applications = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getPostedBy(): ?User { return $this->postedBy; }
    public function setPostedBy(?User $v): static { $this->postedBy = $v; return $this; }
    public function getSector(): string { return $this->sector; }
    public function setSector(string $v): static { $this->sector = $v; return $this; }
    public function getBudgetMin(): string { return $this->budgetMin; }
    public function setBudgetMin(string $v): static { $this->budgetMin = $v; return $this; }
    public function getBudgetMax(): string { return $this->budgetMax; }
    public function setBudgetMax(string $v): static { $this->budgetMax = $v; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): static { $this->description = $v; return $this; }
    public function getDeadline(): ?\DateTimeInterface { return $this->deadline; }
    public function setDeadline(?\DateTimeInterface $v): static { $this->deadline = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }

    /** @return Collection<int, InvestorApplication> */
    public function getApplications(): Collection { return $this->applications; }

    public function getPendingApplicationCount(): int
    {
        return $this->applications->filter(fn($a) => $a->getStatus() === InvestorApplication::STATUS_PENDING)->count();
    }
}
