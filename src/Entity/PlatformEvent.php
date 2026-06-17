<?php

namespace App\Entity;

use App\Repository\PlatformEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlatformEventRepository::class)]
#[ORM\Table(name: 'platform_event', schema: 'public')]
#[ORM\Index(columns: ['event_type'], name: 'idx_event_type')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_event_occurred')]
#[ORM\Index(columns: ['user_id'], name: 'idx_event_user')]
class PlatformEvent
{
    public const PROJECT_SUBMITTED        = 'project.submitted';
    public const PROJECT_VIEWED           = 'project.viewed';
    public const PROJECT_SCORED           = 'project.scored';
    public const INVESTOR_VIEWED_PROJECT  = 'investor.viewed_project';
    public const DEAL_INITIATED           = 'deal.initiated';
    public const DEAL_COMPLETED           = 'deal.completed';
    public const DEAL_REJECTED            = 'deal.rejected';
    public const MENTOR_SESSION_BOOKED    = 'mentor.session_booked';
    public const MENTOR_SESSION_COMPLETED = 'mentor.session_completed';
    public const USER_REGISTERED          = 'user.registered';
    public const USER_LOGGED_IN           = 'user.logged_in';
    public const APPLICATION_SUBMITTED    = 'application.submitted';
    public const APPLICATION_ACCEPTED     = 'application.accepted';
    public const APPLICATION_REJECTED     = 'application.rejected';
    public const PITCH_ANALYZED           = 'pitch.analyzed';
    public const BUSINESS_PLAN_ANALYZED   = 'business_plan.analyzed';
    public const POSTING_CREATED          = 'posting.created';
    public const POSTING_VIEWED           = 'posting.viewed';
    public const MATCH_PROPOSED           = 'match.proposed';
    public const MATCH_ACCEPTED           = 'match.accepted';
    public const MATCH_REJECTED           = 'match.rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $eventType = '';

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $userRole = null;

    #[ORM\Column(nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEventType(): string { return $this->eventType; }
    public function setEventType(string $v): static { $this->eventType = $v; return $this; }
    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $v): static { $this->userId = $v; return $this; }
    public function getUserRole(): ?string { return $this->userRole; }
    public function setUserRole(?string $v): static { $this->userRole = $v; return $this; }
    public function getEntityId(): ?int { return $this->entityId; }
    public function setEntityId(?int $v): static { $this->entityId = $v; return $this; }
    public function getEntityType(): ?string { return $this->entityType; }
    public function setEntityType(?string $v): static { $this->entityType = $v; return $this; }
    public function getPayload(): ?array { return $this->payload; }
    public function setPayload(?array $v): static { $this->payload = $v; return $this; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
