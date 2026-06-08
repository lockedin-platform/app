<?php
namespace App\Entity;

use App\Repository\InvestorApplicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvestorApplicationRepository::class)]
#[ORM\Table(name: 'investor_application')]
#[ORM\HasLifecycleCallbacks]
class InvestorApplication
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_REVIEWED = 'REVIEWED';
    public const STATUS_OFFER_MADE = 'OFFER_MADE';
    public const STATUS_REJECTED = 'REJECTED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InvestorPosting::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'posting_id', nullable: false, onDelete: 'CASCADE')]
    private ?InvestorPosting $posting = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'entrepreneur_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $entrepreneur = null;

    #[ORM\ManyToOne(targetEntity: Projet::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
    private ?Projet $project = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $coverMessage = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

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
    public function onPreUpdate(): void { $this->updatedAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getPosting(): ?InvestorPosting { return $this->posting; }
    public function setPosting(?InvestorPosting $v): static { $this->posting = $v; return $this; }
    public function getEntrepreneur(): ?User { return $this->entrepreneur; }
    public function setEntrepreneur(?User $v): static { $this->entrepreneur = $v; return $this; }
    public function getProject(): ?Projet { return $this->project; }
    public function setProject(?Projet $v): static { $this->project = $v; return $this; }
    public function getCoverMessage(): string { return $this->coverMessage; }
    public function setCoverMessage(string $v): static { $this->coverMessage = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
}
