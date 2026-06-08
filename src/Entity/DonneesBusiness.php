<?php
namespace App\Entity;

use App\Repository\DonneesBusinessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DonneesBusinessRepository::class)]
#[ORM\Table(name: 'donnees_business')]
class DonneesBusiness
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'Market size is required.')]
    private ?string $tailleMarche = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'Revenue model is required.')]
    private ?string $modeleRevenu = null;

    #[ORM\Column(nullable: true)]
    #[Assert\NotNull(message: 'Estimated costs are required.')]
    private ?float $coutsEstimes = 0;

    #[ORM\Column(nullable: true)]
    #[Assert\NotNull(message: 'Expected revenue is required.')]
    private ?float $revenusAttendus = 0;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\NotBlank(message: 'Risk level is required.')]
    private ?string $niveauRisque = null;

    #[ORM\Column(nullable: true)]
    #[Assert\NotNull(message: 'Team strength is required.')]
    #[Assert\Range(min: 1, max: 10, notInRangeMessage: 'Team strength must be between 1 and 10.')]
    private ?int $forceEquipe = 0;

    #[ORM\OneToOne(targetEntity: Projet::class, inversedBy: 'donneesBusiness')]
    #[ORM\JoinColumn(name: 'projet_id', nullable: false, onDelete: 'CASCADE')]
    private ?Projet $projet = null;

    #[ORM\Column(nullable: true)]
    private ?float $margeEstimee = 0;

    #[ORM\Column(nullable: true)]
    private ?float $ratioRentabilite = 0;

    #[ORM\Column(nullable: true)]
    private ?float $scoreFinancier = 0;

    #[ORM\Column(nullable: true)]
    private ?float $scoreMarche = 0;

    #[ORM\Column(nullable: true)]
    private ?float $scoreEquipeCalcule = 0;

    #[ORM\Column(nullable: true)]
    private ?float $scoreRisqueCalcule = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawCouts = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawRevenus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawForceEquipe = null;

    public function getId(): ?int { return $this->id; }
    public function getTailleMarche(): ?string { return $this->tailleMarche; }
    public function setTailleMarche(?string $v): static { $this->tailleMarche = $v; return $this; }
    public function getModeleRevenu(): ?string { return $this->modeleRevenu; }
    public function setModeleRevenu(?string $v): static { $this->modeleRevenu = $v; return $this; }
    public function getCoutsEstimes(): ?float { return $this->coutsEstimes; }
    public function setCoutsEstimes(?float $v): static { $this->coutsEstimes = $v; return $this; }
    public function getRevenusAttendus(): ?float { return $this->revenusAttendus; }
    public function setRevenusAttendus(?float $v): static { $this->revenusAttendus = $v; return $this; }
    public function getNiveauRisque(): ?string { return $this->niveauRisque; }
    public function setNiveauRisque(?string $v): static { $this->niveauRisque = $v; return $this; }
    public function getForceEquipe(): ?int { return $this->forceEquipe; }
    public function setForceEquipe(?int $v): static { $this->forceEquipe = $v; return $this; }
    public function getProjet(): ?Projet { return $this->projet; }
    public function setProjet(?Projet $v): static { $this->projet = $v; return $this; }
    public function getMargeEstimee(): ?float { return $this->margeEstimee; }
    public function setMargeEstimee(?float $v): static { $this->margeEstimee = $v; return $this; }
    public function getRatioRentabilite(): ?float { return $this->ratioRentabilite; }
    public function setRatioRentabilite(?float $v): static { $this->ratioRentabilite = $v; return $this; }
    public function getScoreFinancier(): ?float { return $this->scoreFinancier; }
    public function setScoreFinancier(?float $v): static { $this->scoreFinancier = $v; return $this; }
    public function getScoreMarche(): ?float { return $this->scoreMarche; }
    public function setScoreMarche(?float $v): static { $this->scoreMarche = $v; return $this; }
    public function getScoreEquipeCalcule(): ?float { return $this->scoreEquipeCalcule; }
    public function setScoreEquipeCalcule(?float $v): static { $this->scoreEquipeCalcule = $v; return $this; }
    public function getScoreRisqueCalcule(): ?float { return $this->scoreRisqueCalcule; }
    public function setScoreRisqueCalcule(?float $v): static { $this->scoreRisqueCalcule = $v; return $this; }
    public function getRawCouts(): ?string { return $this->rawCouts; }
    public function setRawCouts(?string $v): static { $this->rawCouts = $v; return $this; }
    public function getRawRevenus(): ?string { return $this->rawRevenus; }
    public function setRawRevenus(?string $v): static { $this->rawRevenus = $v; return $this; }
    public function getRawForceEquipe(): ?string { return $this->rawForceEquipe; }
    public function setRawForceEquipe(?string $v): static { $this->rawForceEquipe = $v; return $this; }

    public function calculerIndicateurs(): void
    {
        $this->margeEstimee = $this->revenusAttendus - $this->coutsEstimes;
        $this->ratioRentabilite = $this->coutsEstimes > 0 ? $this->margeEstimee / $this->coutsEstimes : 0;
    }
}
