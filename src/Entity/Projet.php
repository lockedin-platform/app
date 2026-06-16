<?php
namespace App\Entity;

use App\Repository\ProjetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjetRepository::class)]
#[ORM\Table(name: 'projet')]
class Projet
{
    public const STATUT_BROUILLON = 'BROUILLON';
    public const STATUT_SOUMIS = 'SOUMIS';
    public const STATUT_EVALUE = 'EVALUE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'projets')]
    #[ORM\JoinColumn(name: 'user_id', nullable: true)]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(min: 3, max: 255, minMessage: 'Le titre doit contenir au moins {{ limit }} caractères.', maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\NotBlank(message: 'La description est obligatoire.')]
    #[Assert\Length(min: 10, max: 5000, minMessage: 'La description doit contenir au moins {{ limit }} caractères.', maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\NotBlank(message: 'Le secteur est obligatoire.')]
    private ?string $secteur = null;

    // Geography — Model 1 input "geography" + key to the World Bank macro join (Model 4)
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\NotBlank(message: "L'étape est obligatoire.")]
    private ?string $etape = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\NotBlank(message: 'Le statut est obligatoire.')]
    private ?string $statut = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateCreation = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $statutProjet = self::STATUT_BROUILLON;

    #[ORM\Column(nullable: true)]
    private ?float $scoreGlobal = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $diagnosticIa = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateSoumission = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateEvaluation = null;

    #[ORM\Column(nullable: true)]
    private ?int $entrepreneurId = null;

    #[ORM\OneToOne(targetEntity: DonneesBusiness::class, mappedBy: 'projet', cascade: ['persist', 'remove'])]
    private ?DonneesBusiness $donneesBusiness = null;

    #[ORM\OneToMany(targetEntity: InvestmentOpportunity::class, mappedBy: 'project')]
    private Collection $opportunities;

    public function __construct()
    {
        $this->dateCreation = new \DateTime();
        $this->opportunities = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getTitre(): ?string { return $this->titre; }
    public function setTitre(?string $titre): static { $this->titre = $titre; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getSecteur(): ?string { return $this->secteur; }
    public function setSecteur(?string $secteur): static { $this->secteur = $secteur; return $this; }
    public function getPays(): ?string { return $this->pays; }
    public function setPays(?string $pays): static { $this->pays = $pays; return $this; }
    public function getEtape(): ?string { return $this->etape; }
    public function setEtape(?string $etape): static { $this->etape = $etape; return $this; }
    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(?string $statut): static { $this->statut = $statut; return $this; }
    public function getDateCreation(): ?\DateTimeInterface { return $this->dateCreation; }
    public function setDateCreation(?\DateTimeInterface $dateCreation): static { $this->dateCreation = $dateCreation; return $this; }
    public function getStatutProjet(): ?string { return $this->statutProjet; }
    public function setStatutProjet(?string $statutProjet): static { $this->statutProjet = $statutProjet; return $this; }
    public function getScoreGlobal(): ?float { return $this->scoreGlobal; }
    public function setScoreGlobal(?float $scoreGlobal): static { $this->scoreGlobal = $scoreGlobal; return $this; }
    public function getDiagnosticIa(): ?string { return $this->diagnosticIa; }
    public function setDiagnosticIa(?string $diagnosticIa): static { $this->diagnosticIa = $diagnosticIa; return $this; }
    public function getDateSoumission(): ?\DateTimeInterface { return $this->dateSoumission; }
    public function setDateSoumission(?\DateTimeInterface $dateSoumission): static { $this->dateSoumission = $dateSoumission; return $this; }
    public function getDateEvaluation(): ?\DateTimeInterface { return $this->dateEvaluation; }
    public function setDateEvaluation(?\DateTimeInterface $dateEvaluation): static { $this->dateEvaluation = $dateEvaluation; return $this; }
    public function getEntrepreneurId(): ?int { return $this->entrepreneurId; }
    public function setEntrepreneurId(?int $entrepreneurId): static { $this->entrepreneurId = $entrepreneurId; return $this; }
    public function getDonneesBusiness(): ?DonneesBusiness { return $this->donneesBusiness; }
    public function setDonneesBusiness(?DonneesBusiness $donneesBusiness): static
    {
        $this->donneesBusiness = $donneesBusiness;
        if ($donneesBusiness !== null && $donneesBusiness->getProjet() !== $this) {
            $donneesBusiness->setProjet($this);
        }
        return $this;
    }
    /** @return Collection<int, InvestmentOpportunity> */
    public function getOpportunities(): Collection { return $this->opportunities; }
}
