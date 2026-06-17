<?php

namespace App\Service;

use App\Entity\PlatformEvent;
use App\Entity\Projet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AnalyticsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function log(
        string $eventType,
        ?User $user = null,
        ?int $entityId = null,
        ?string $entityType = null,
        array $payload = [],
    ): void {
        try {
            $event = new PlatformEvent();
            $event->setEventType($eventType);
            $event->setUserId($user?->getId());
            $event->setUserRole($user?->getRole());
            $event->setEntityId($entityId);
            $event->setEntityType($entityType);
            $event->setPayload($payload ?: null);
            $this->em->persist($event);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Analytics log failed: ' . $e->getMessage());
        }
    }

    public function logProjectSubmitted(User $user, Projet $projet): void
    {
        $db = $projet->getDonneesBusiness();
        $this->log(PlatformEvent::PROJECT_SUBMITTED, $user, $projet->getId(), 'projet', [
            'sector'            => $projet->getSecteur(),
            'etape'             => $projet->getEtape(),
            'funding_target'    => $db?->getCoutsEstimes(),
            'expected_revenue'  => $db?->getRevenusAttendus(),
            'team_strength'     => $db?->getForceEquipe(),
            'risk_level'        => $db?->getNiveauRisque(),
            'market_size'       => $db?->getTailleMarche(),
            'revenue_model'     => $db?->getModeleRevenu(),
            'description_len'   => strlen($projet->getDescription() ?? ''),
            'title_len'         => strlen($projet->getTitre() ?? ''),
        ]);
    }

    public function logProjectScored(User $user, Projet $projet): void
    {
        $db = $projet->getDonneesBusiness();
        $this->log(PlatformEvent::PROJECT_SCORED, $user, $projet->getId(), 'projet', [
            'sector'          => $projet->getSecteur(),
            'etape'           => $projet->getEtape(),
            'score_global'    => $projet->getScoreGlobal(),
            'score_financier' => $db?->getScoreFinancier(),
            'score_marche'    => $db?->getScoreMarche(),
            'score_equipe'    => $db?->getScoreEquipeCalcule(),
            'score_risque'    => $db?->getScoreRisqueCalcule(),
            'team_strength'   => $db?->getForceEquipe(),
            'risk_level'      => $db?->getNiveauRisque(),
            'revenue_model'   => $db?->getModeleRevenu(),
            'costs'           => $db?->getCoutsEstimes(),
            'revenues'        => $db?->getRevenusAttendus(),
            'market_size'     => $db?->getTailleMarche(),
        ]);
    }

    public function logInvestorViewedProject(
        User $investor,
        int $projectId,
        string $sector,
        float $score,
        array $investorPrefs = [],
    ): void {
        $this->log(PlatformEvent::INVESTOR_VIEWED_PROJECT, $investor, $projectId, 'projet', array_merge([
            'sector'        => $sector,
            'project_score' => $score,
        ], $investorPrefs));
    }

    public function logApplicationSubmitted(User $entrepreneur, int $postingId, string $sector): void
    {
        $this->log(PlatformEvent::APPLICATION_SUBMITTED, $entrepreneur, $postingId, 'investor_posting', [
            'sector' => $sector,
        ]);
    }

    public function logApplicationResult(User $investor, int $applicationId, bool $accepted, string $sector): void
    {
        $type = $accepted ? PlatformEvent::APPLICATION_ACCEPTED : PlatformEvent::APPLICATION_REJECTED;
        $this->log($type, $investor, $applicationId, 'investor_application', [
            'sector' => $sector,
        ]);
    }

    public function logDealInitiated(User $user, int $contractId, string $sector, float $amount): void
    {
        $this->log(PlatformEvent::DEAL_INITIATED, $user, $contractId, 'investment_contract', [
            'sector' => $sector,
            'amount' => $amount,
        ]);
    }

    public function logDealCompleted(User $user, int $contractId, string $sector, float $amount): void
    {
        $this->log(PlatformEvent::DEAL_COMPLETED, $user, $contractId, 'investment_contract', [
            'sector' => $sector,
            'amount' => $amount,
        ]);
    }

    public function logMentorSessionBooked(User $entrepreneur, int $sessionId, string $mentorId): void
    {
        $this->log(PlatformEvent::MENTOR_SESSION_BOOKED, $entrepreneur, $sessionId, 'mentorship_session', [
            'mentor_id' => $mentorId,
        ]);
    }

    public function logUserRegistered(User $user): void
    {
        $this->log(PlatformEvent::USER_REGISTERED, $user, null, null, [
            'role' => $user->getRole(),
        ]);
    }

    public function logPitchAnalyzed(User $user, int $projectId, array $scores): void
    {
        $this->log(PlatformEvent::PITCH_ANALYZED, $user, $projectId, 'projet', $scores);
    }

    public function logPostingViewed(User $viewer, int $postingId, string $sector): void
    {
        $this->log(PlatformEvent::POSTING_VIEWED, $viewer, $postingId, 'investor_posting', [
            'sector' => $sector,
            'viewer_role' => $viewer->getRole(),
        ]);
    }
}
