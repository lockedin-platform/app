<?php

namespace App\Controller;

use App\Entity\InvestmentOffer;
use App\Entity\InvestmentOpportunity;
use App\Entity\InvestorApplication;
use App\Entity\InvestorPosting;
use App\Entity\Projet;
use App\Repository\ContractMilestoneRepository;
use App\Repository\InvestmentContractRepository;
use App\Repository\InvestmentOfferRepository;
use App\Repository\InvestmentOpportunityRepository;
use App\Repository\InvestorApplicationRepository;
use App\Repository\InvestorPostingRepository;
use App\Repository\InvestorProfileRepository;
use App\Repository\ProjetRepository;
use App\Service\AnalyticsService;
use App\Service\EmailService;
use App\Service\NotificationService;
use App\Service\Investment\StripePaymentService;
use App\Service\Investment\EconomicApiService;
use App\Service\Investment\EconomicRiskEngine;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/investissement')]
#[IsGranted('ROLE_USER')]
class InvestmentController extends AbstractController
{
    #[Route('/opportunities', name: 'app_invest_opportunities')]
    public function opportunities(Request $request, InvestorPostingRepository $postingRepo, InvestorApplicationRepository $appRepo, PaginatorInterface $paginator): Response
    {
        $search = trim($request->query->get('q', ''));
        $sort = $request->query->get('sort', 'recent');
        $page = $request->query->getInt('page', 1);

        $qb = $postingRepo->searchOpenQuery($search, $sort);
        $pagination = $paginator->paginate($qb, $page, 9);
        $postings = iterator_to_array($pagination);

        $user = $this->getUser();
        $myApplicationPostingIds = [];
        $pendingInboxCount = 0;

        if ($user) {
            if ($this->isGranted('ROLE_ENTREPRENEUR')) {
                $myApps = $appRepo->findByEntrepreneur($user);
                foreach ($myApps as $app) {
                    $myApplicationPostingIds[] = $app->getPosting()->getId();
                }
            }
            if ($this->isGranted('ROLE_INVESTISSEUR')) {
                $pendingInboxCount = $appRepo->countPendingForInvestor($user);
            }
        }

        return $this->render('front/investment/opportunities.html.twig', [
            'postings' => $postings,
            'pagination' => $pagination,
            'search' => $search,
            'sort' => $sort,
            'myApplicationPostingIds' => $myApplicationPostingIds,
            'pendingInboxCount' => $pendingInboxCount,
        ]);
    }

    #[Route('/postings/{id}/apply', name: 'app_invest_posting_apply', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ENTREPRENEUR')]
    public function applyToPosting(
        InvestorPosting $posting,
        Request $request,
        EntityManagerInterface $em,
        InvestorApplicationRepository $appRepo,
        ProjetRepository $projetRepo,
        NotificationService $notificationService,
        AnalyticsService $analytics,
    ): Response {
        $user = $this->getUser();

        if ($posting->getStatus() !== InvestorPosting::STATUS_OPEN) {
            $this->addFlash('danger', 'This posting is no longer open.');
            return $this->redirectToRoute('app_invest_opportunities');
        }

        if ($appRepo->findExisting($posting, $user)) {
            $this->addFlash('warning', 'You have already applied to this posting.');
            return $this->redirectToRoute('app_invest_opportunities');
        }

        $projects = $projetRepo->findByUser($user);
        if (empty($projects)) {
            $this->addFlash('warning', 'You need to create a project before applying.');
            return $this->redirectToRoute('app_projet_index');
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('apply_posting_' . $posting->getId(), $token)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->redirectToRoute('app_invest_opportunities');
            }

            $project = $projetRepo->find($request->request->get('project_id'));
            if (!$project || $project->getUser() !== $user) {
                $this->addFlash('danger', 'Invalid project selected.');
                return $this->render('front/investment/apply.html.twig', ['posting' => $posting, 'projects' => $projects]);
            }

            $coverMessage = trim($request->request->get('cover_message', ''));
            if (mb_strlen($coverMessage) < 20) {
                $this->addFlash('danger', 'Cover message must be at least 20 characters.');
                return $this->render('front/investment/apply.html.twig', ['posting' => $posting, 'projects' => $projects]);
            }

            $application = new InvestorApplication();
            $application->setPosting($posting);
            $application->setEntrepreneur($user);
            $application->setProject($project);
            $application->setCoverMessage($coverMessage);

            $em->persist($application);
            $em->flush();
            $analytics->logApplicationSubmitted($user, $posting->getId(), $posting->getSector());

            $inboxUrl = $this->generateUrl('app_invest_my_postings');
            $notificationService->notify(
                $posting->getPostedBy(),
                'New application received',
                ($user->getFirstname() ?? 'An entrepreneur') . ' applied to your "' . $posting->getSector() . '" posting with their project "' . $project->getTitre() . '".',
                'INFO',
                $inboxUrl,
                'Review application'
            );

            $this->addFlash('success', 'Application submitted! The investor will review your project and reach out if interested.');
            return $this->redirectToRoute('app_invest_my_applications');
        }

        return $this->render('front/investment/apply.html.twig', [
            'posting' => $posting,
            'projects' => $projects,
        ]);
    }

    #[Route('/my-applications', name: 'app_invest_my_applications')]
    #[IsGranted('ROLE_ENTREPRENEUR')]
    public function myApplications(InvestorApplicationRepository $appRepo): Response
    {
        $apps = $appRepo->findByEntrepreneur($this->getUser());
        return $this->render('front/investment/my_applications.html.twig', ['applications' => $apps]);
    }

    #[Route('/my-postings', name: 'app_invest_my_postings')]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function myPostings(InvestorPostingRepository $postingRepo, InvestorApplicationRepository $appRepo): Response
    {
        $postings = $postingRepo->findByInvestor($this->getUser());
        $pendingCount = $appRepo->countPendingForInvestor($this->getUser());
        return $this->render('front/investment/my_postings.html.twig', [
            'postings' => $postings,
            'pendingCount' => $pendingCount,
        ]);
    }

    #[Route('/postings/create', name: 'app_invest_create_posting', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function createPosting(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('create_posting', $token)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->render('front/investment/create_posting.html.twig');
            }

            $sector = trim($request->request->get('sector', ''));
            $budgetMin = $request->request->get('budget_min');
            $budgetMax = $request->request->get('budget_max');
            $description = trim($request->request->get('description', ''));
            $deadlineStr = $request->request->get('deadline', '');

            $errors = [];
            if ($sector === '') $errors[] = 'Sector is required.';
            if (!is_numeric($budgetMin) || (float)$budgetMin < 0) $errors[] = 'Budget minimum must be a positive number.';
            if (!is_numeric($budgetMax) || (float)$budgetMax <= 0) $errors[] = 'Budget maximum must be a positive number.';
            if ((float)$budgetMax < (float)$budgetMin) $errors[] = 'Budget maximum must be greater than minimum.';
            if (mb_strlen($description) < 20) $errors[] = 'Description must be at least 20 characters.';

            $deadline = null;
            if ($deadlineStr !== '' && strtotime($deadlineStr) !== false) {
                $deadline = new \DateTime($deadlineStr);
                if ($deadline <= new \DateTime('today')) {
                    $errors[] = 'Deadline must be in the future.';
                }
            }

            if (!empty($errors)) {
                foreach ($errors as $err) { $this->addFlash('danger', $err); }
                return $this->render('front/investment/create_posting.html.twig', compact('sector', 'budgetMin', 'budgetMax', 'description', 'deadlineStr'));
            }

            $posting = new InvestorPosting();
            $posting->setPostedBy($this->getUser());
            $posting->setSector($sector);
            $posting->setBudgetMin((string)(float)$budgetMin);
            $posting->setBudgetMax((string)(float)$budgetMax);
            $posting->setDescription($description);
            $posting->setDeadline($deadline);

            $em->persist($posting);
            $em->flush();

            $this->addFlash('success', 'Investment posting published! Entrepreneurs can now apply.');
            return $this->redirectToRoute('app_invest_my_postings');
        }

        return $this->render('front/investment/create_posting.html.twig');
    }

    #[Route('/applications/{id}/review', name: 'app_invest_review_application', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function reviewApplication(InvestorApplication $application, EntityManagerInterface $em): Response
    {
        if ($application->getPosting()->getPostedBy() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You do not have access to this application.');
        }

        if ($application->getStatus() === InvestorApplication::STATUS_PENDING) {
            $application->setStatus(InvestorApplication::STATUS_REVIEWED);
            $em->flush();
        }

        return $this->render('front/investment/review_application.html.twig', [
            'application' => $application,
        ]);
    }

    #[Route('/applications/{id}/make-offer', name: 'app_invest_make_offer_from_app', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function makeOfferFromApplication(
        InvestorApplication $application,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notificationService,
        AnalyticsService $analytics,
    ): Response {
        if ($application->getPosting()->getPostedBy() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('make_offer_app_' . $application->getId(), $token)) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_review_application', ['id' => $application->getId()]);
        }

        $amount = $request->request->get('amount');
        if (!is_numeric($amount) || (float)$amount <= 0) {
            $this->addFlash('danger', 'Offer amount must be a positive number.');
            return $this->redirectToRoute('app_invest_review_application', ['id' => $application->getId()]);
        }

        $project = $application->getProject();
        $posting = $application->getPosting();

        // Create InvestmentOpportunity linked to the entrepreneur's project
        $opp = new InvestmentOpportunity();
        $opp->setProject($project);
        $opp->setTargetAmount((string)(float)$amount);
        $opp->setDescription('Investment opportunity created from posting: ' . $posting->getSector());
        $opp->setDeadline($posting->getDeadline() ?? new \DateTime('+6 months'));
        $opp->setStatus(InvestmentOpportunity::STATUS_OPEN);
        $em->persist($opp);

        // Create InvestmentOffer from investor
        $offer = new InvestmentOffer();
        $offer->setOpportunity($opp);
        $offer->setInvestor($this->getUser());
        $offer->setProposedAmount((string)(float)$amount);
        $offer->setStatus(InvestmentOffer::STATUS_ACCEPTED);
        $em->persist($offer);

        // Mark application as offer made
        $application->setStatus(InvestorApplication::STATUS_OFFER_MADE);

        $em->flush();
        $analytics->logApplicationResult($this->getUser(), $application->getId(), true, $posting->getSector());

        // Notify entrepreneur
        $contractUrl = $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]);
        $notificationService->notify(
            $application->getEntrepreneur(),
            'Investment offer received!',
            ($this->getUser()->getFirstname() ?? 'An investor') . ' has reviewed your project "' . $project->getTitre() . '" and prepared an investment offer of ' . number_format((float)$amount, 0, ',', ' ') . ' TND. Start negotiating!',
            'SUCCESS',
            $contractUrl,
            'View offer & negotiate'
        );

        $this->addFlash('success', 'Investment offer sent! The entrepreneur has been notified and can start negotiating.');
        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    #[Route('/applications/{id}/reject', name: 'app_invest_reject_application', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function rejectApplication(
        InvestorApplication $application,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notificationService,
        AnalyticsService $analytics,
    ): Response {
        if ($application->getPosting()->getPostedBy() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reject_app_' . $application->getId(), $token)) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_review_application', ['id' => $application->getId()]);
        }

        $application->setStatus(InvestorApplication::STATUS_REJECTED);
        $em->flush();
        $analytics->logApplicationResult($this->getUser(), $application->getId(), false, $application->getPosting()->getSector());

        $notificationService->notify(
            $application->getEntrepreneur(),
            'Application not selected',
            'Your application for the "' . $application->getPosting()->getSector() . '" posting was not selected this time. Keep improving your project!',
            'INFO'
        );

        $this->addFlash('info', 'Application rejected.');
        return $this->redirectToRoute('app_invest_my_postings');
    }

    #[Route('/opportunities/{id}', name: 'app_invest_opportunity_show', requirements: ['id' => '\d+'])]
    public function showOpportunity(InvestmentOpportunity $opp, InvestmentOfferRepository $offerRepo, EconomicApiService $ecoApi, EconomicRiskEngine $riskEngine): Response
    {
        $offers = $offerRepo->findByOpportunity($opp);
        $myOffer = $offerRepo->findOneBy(['opportunity' => $opp, 'investor' => $this->getUser()]);
        $canManageOffers = $this->isGranted('ROLE_ENTREPRENEUR')
            && $opp->getProject()
            && $opp->getProject()->getUser() === $this->getUser();

        // Compute risk data for risk acknowledgment gate
        $riskScore = 50;
        $riskLevel = 'medium';
        $riskFactors = [];
        try {
            $ecoData = $ecoApi->fetchAllEconomicData('TN');
            $riskScore = $riskEngine->calculateFullRisk(
                (float) $opp->getTargetAmount(),
                $opp->getDeadline(),
                $ecoData
            );
            $riskLevel = $riskScore <= EconomicRiskEngine::THRESHOLD_LOW ? 'low'
                : ($riskScore <= EconomicRiskEngine::THRESHOLD_MEDIUM ? 'medium' : 'high');
            $riskFactors = $this->buildTopRiskFactors($opp, $ecoData, $riskEngine);
        } catch (\Throwable $e) {
            // fallback: default medium risk
        }

        return $this->render('front/investment/show.html.twig', [
            'opportunity' => $opp,
            'offers' => $offers,
            'myOffer' => $myOffer,
            'canManageOffers' => $canManageOffers,
            'riskScore' => $riskScore,
            'riskLevel' => $riskLevel,
            'riskFactors' => $riskFactors,
        ]);
    }

    #[Route('/offers/{id}/accept', name: 'app_invest_offer_accept_front', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ENTREPRENEUR')]
    public function acceptOfferFront(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        InvestmentOfferRepository $offerRepo,
        NotificationService $notificationService,
    ): Response
    {
        if (!$this->isCsrfTokenValid('offer_action_' . $offer->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $offer->getOpportunity()->getId()]);
        }

        $opportunity = $offer->getOpportunity();
        if (!$opportunity->getProject() || $opportunity->getProject()->getUser() !== $this->getUser()) {
            $this->addFlash('danger', 'You cannot manage offers for this opportunity.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opportunity->getId()]);
        }

        if ($offer->getStatus() !== InvestmentOffer::STATUS_PENDING) {
            $this->addFlash('danger', 'Only pending offers can be accepted.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opportunity->getId()]);
        }

        $acceptedTotal = $offerRepo->sumAcceptedAmountsForOpportunity($opportunity);
        $nextAcceptedTotal = $acceptedTotal + (float) $offer->getProposedAmount();
        if (($nextAcceptedTotal - (float) $opportunity->getTargetAmount()) > 0.00001) {
            $this->addFlash('danger', 'Accepting this offer would exceed the funding target.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opportunity->getId()]);
        }

        $offer->setStatus(InvestmentOffer::STATUS_ACCEPTED);
        $contractUrl = $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]);
        $notificationService->notify(
            $offer->getInvestor(),
            'Offer accepted',
            'Your offer for ' . ($opportunity->getProject()?->getTitre() ?? 'this project') . ' has been accepted. Negotiate and sign the contract before payment.',
            'CONTRACT',
            $contractUrl,
            'Open contract'
        );

        $em->flush();

        $this->addFlash('success', 'Offer accepted. You can now start contract negotiation.');
        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    #[Route('/offers/{id}/reject', name: 'app_invest_offer_reject_front', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ENTREPRENEUR')]
    public function rejectOfferFront(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notificationService,
    ): Response
    {
        if (!$this->isCsrfTokenValid('offer_action_' . $offer->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $offer->getOpportunity()->getId()]);
        }

        $opportunity = $offer->getOpportunity();
        if (!$opportunity->getProject() || $opportunity->getProject()->getUser() !== $this->getUser()) {
            $this->addFlash('danger', 'You cannot manage offers for this opportunity.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opportunity->getId()]);
        }

        if ($offer->getStatus() !== InvestmentOffer::STATUS_PENDING) {
            $this->addFlash('danger', 'Only pending offers can be rejected.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opportunity->getId()]);
        }

        $offer->setStatus(InvestmentOffer::STATUS_REJECTED);
        $notificationService->notify(
            $offer->getInvestor(),
            'Offer rejected',
            'Your offer for ' . ($opportunity->getProject()?->getTitre() ?? 'this project') . ' has been rejected.',
            'DANGER'
        );
        $em->flush();

        $this->addFlash('success', 'Offer rejected.');
        return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opportunity->getId()]);
    }

    #[Route('/entrepreneur/inbox', name: 'app_invest_entrepreneur_inbox')]
    #[IsGranted('ROLE_ENTREPRENEUR')]
    public function entrepreneurInbox(): Response
    {
        return $this->redirectToRoute('app_invest_opportunities');
    }

    #[Route('/opportunities/{id}/offer', name: 'app_invest_offer', methods: ['POST'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function makeOffer(InvestmentOpportunity $opp, Request $request, EntityManagerInterface $em, InvestmentOfferRepository $offerRepo, ValidatorInterface $validator): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('invest_offer_' . $opp->getId(), $token)) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        if ($opp->getStatus() !== InvestmentOpportunity::STATUS_OPEN) {
            $this->addFlash('danger', 'This opportunity is no longer open.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        // Prevent entrepreneur from investing in their own project
        if ($opp->getProject() && $opp->getProject()->getUser() === $this->getUser()) {
            $this->addFlash('danger', 'You cannot invest in your own project.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        // Prevent duplicate offers (same investor + same opportunity)
        $existingOffer = $offerRepo->findExistingOffer($this->getUser(), $opp);
        if ($existingOffer) {
            $this->addFlash('danger', 'You have already submitted an offer for this opportunity.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        $riskAcknowledged = $request->request->getBoolean('risk_acknowledged');
        $amount = $request->request->get('amount');
        if (!is_numeric($amount) || (float) $amount <= 0) {
            $this->addFlash('danger', 'Amount must be a positive number.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        $remaining = (float) $opp->getTargetAmount() - $opp->getTotalFunded();
        if ((float) $amount > $remaining && $remaining > 0) {
            $this->addFlash('danger', 'Amount cannot exceed the remaining funding target (' . number_format($remaining, 2, ',', ' ') . ' TND).');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        $offer = new InvestmentOffer();
        $offer->setOpportunity($opp);
        $offer->setInvestor($this->getUser());
        $offer->setProposedAmount((string) (float) $amount);
        $offer->setStatus('PENDING');
        $offer->setPaid(false);
        $offer->setRiskAcknowledged($riskAcknowledged);

        $errors = $validator->validate($offer);
        if (count($errors) > 0) {
            $this->addFlash('danger', (string) $errors->get(0)->getMessage());
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        try {
            $em->persist($offer);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('danger', 'You have already submitted an offer for this opportunity.');
            return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
        }

        $this->addFlash('success', 'Offer submitted successfully!');
        return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $opp->getId()]);
    }

    #[Route('/opportunities/ajax', name: 'app_invest_opportunities_ajax', methods: ['GET'])]
    public function opportunitiesAjax(Request $request, InvestmentOpportunityRepository $repo, InvestmentOfferRepository $offerRepo, InvestmentContractRepository $contractRepo, EconomicApiService $ecoApi, PaginatorInterface $paginator): JsonResponse
    {
        $search = trim($request->query->get('q', ''));
        $sort = $request->query->get('sort', 'recent');
        $page = $request->query->getInt('page', 1);

        $qb = $repo->searchOpenQuery($search, $sort);
        $pagination = $paginator->paginate($qb, $page, 9);
        $opportunities = iterator_to_array($pagination);

        $ecoData = [];
        try {
            $ecoData = $ecoApi->fetchAllEconomicData('TN');
        } catch (\Throwable $e) {}

        $inflation = (float) ($ecoData['inflationRate'] ?? 5.0);
        $now = new \DateTimeImmutable();

        $data = [];
        foreach ($opportunities as $opp) {
            $deadline = $opp->getDeadline();
            $monthsLeft = $deadline ? max(0, (int) ceil($now->diff($deadline)->days / 30)) : null;

            $data[] = [
                'id' => $opp->getId(),
                'projectTitle' => $opp->getProject() ? $opp->getProject()->getTitre() : 'Projet',
                'description' => mb_strlen($opp->getDescription()) > 100 ? mb_substr($opp->getDescription(), 0, 100) . '...' : $opp->getDescription(),
                'status' => $opp->getStatus(),
                'targetAmount' => number_format((float) $opp->getTargetAmount(), 0, ',', ' '),
                'totalFunded' => number_format($opp->getTotalFunded(), 0, ',', ' '),
                'fundingPercentage' => round($opp->getFundingPercentage(), 1),
                'deadline' => $opp->getDeadline() ? $opp->getDeadline()->format('d/m/Y') : null,
                'showUrl' => $this->generateUrl('app_invest_opportunity_show', ['id' => $opp->getId()]),
                'ecoBadge' => $this->buildEcoBadge($inflation, $monthsLeft),
                'pitchLine' => $this->buildPitchLine($opp, $inflation),
            ];
        }

        return $this->json([
            'count' => $pagination->getTotalItemCount(),
            'page' => $pagination->getCurrentPageNumber(),
            'totalPages' => (int) ceil($pagination->getTotalItemCount() / $pagination->getItemNumberPerPage()),
            'perPage' => $pagination->getItemNumberPerPage(),
            'opportunities' => $data,
            'dealFeed' => $this->buildDealFeed($repo, $offerRepo, $contractRepo),
        ]);
    }

    private function buildEcoBadge(float $inflation, ?int $monthsLeft): string
    {
        $parts = [];
        if ($inflation >= 6.0) {
            $parts[] = 'Inflation elevee';
        } elseif ($inflation <= 3.0) {
            $parts[] = 'Conditions stables';
        } else {
            $parts[] = 'Inflation moderee';
        }

        if ($monthsLeft !== null) {
            if ($monthsLeft <= 6) {
                $parts[] = 'Echeance courte';
            } elseif ($monthsLeft <= 12) {
                $parts[] = 'Horizon modere';
            } else {
                $parts[] = 'Horizon long';
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Build a unified deal feed from recent platform activity across multiple entities.
     */
    private function buildDealFeed(
        InvestmentOpportunityRepository $oppRepo,
        InvestmentOfferRepository $offerRepo,
        InvestmentContractRepository $contractRepo,
        int $limit = 5
    ): array {
        $events = [];
        $now = new \DateTimeImmutable();

        // 1. Recent opportunities posted
        $opps = $oppRepo->createQueryBuilder('o')
            ->leftJoin('o.project', 'p')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($opps as $opp) {
            $sector = $opp->getProject()?->getSecteur() ?: 'an innovative sector';
            $events[] = [
                'icon' => 'bi-plus-circle',
                'type' => 'opportunity',
                'message' => 'New opportunity in ' . $sector . ' published',
                'date' => $opp->getCreatedAt(),
            ];
        }

        // 2. Recent offers submitted
        $offers = $offerRepo->createQueryBuilder('o')
            ->leftJoin('o.opportunity', 'opp')
            ->leftJoin('opp.project', 'p')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($offers as $offer) {
            $projectName = $offer->getOpportunity()?->getProject()?->getTitre() ?: 'a project';
            $events[] = [
                'icon' => 'bi-send',
                'type' => 'offer',
                'message' => 'An investor submitted an offer on ' . $projectName,
                'date' => $offer->getCreatedAt(),
            ];
        }

        // 3. Accepted offers — negotiation has begun
        $accepted = $offerRepo->createQueryBuilder('o')
            ->where('o.status = :s')
            ->setParameter('s', 'ACCEPTED')
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($accepted as $offer) {
            $events[] = [
                'icon' => 'bi-handshake',
                'type' => 'accepted',
                'message' => 'An offer was accepted — negotiation has started',
                'date' => $offer->getUpdatedAt(),
            ];
        }

        // 4. Fully signed contracts
        $contracts = $contractRepo->createQueryBuilder('c')
            ->where('c.investorSignedAt IS NOT NULL AND c.entrepreneurSignedAt IS NOT NULL')
            ->orderBy('c.entrepreneurSignedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($contracts as $contract) {
            $signDate = $contract->getEntrepreneurSignedAt() > $contract->getInvestorSignedAt()
                ? $contract->getEntrepreneurSignedAt()
                : $contract->getInvestorSignedAt();
            $events[] = [
                'icon' => 'bi-pen',
                'type' => 'signed',
                'message' => 'Un accord a ete signe entre les deux parties',
                'date' => $signDate,
            ];
        }

        // 5. Payments completed
        $paid = $offerRepo->createQueryBuilder('o')
            ->where('o.paid = :paid AND o.paidAt IS NOT NULL')
            ->setParameter('paid', true)
            ->orderBy('o.paidAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($paid as $offer) {
            $events[] = [
                'icon' => 'bi-credit-card-2-front',
                'type' => 'funded',
                'message' => 'Un investissement a ete finance avec succes',
                'date' => $offer->getPaidAt(),
            ];
        }

        // Sort by date DESC, take top $limit
        usort($events, function ($a, $b) {
            $da = $a['date'] ?? new \DateTime('2000-01-01');
            $db = $b['date'] ?? new \DateTime('2000-01-01');
            return $db <=> $da;
        });
        $events = array_slice($events, 0, $limit);

        // Compute relative timestamps
        foreach ($events as &$e) {
            $e['ago'] = $this->buildRelativeTime($e['date'], $now);
            unset($e['date']); // don't serialize DateTime objects
        }

        return $events;
    }

    private function buildRelativeTime(?\DateTimeInterface $date, \DateTimeImmutable $now): string
    {
        if (!$date) {
            return '';
        }
        $ts = $date instanceof \DateTimeImmutable ? $date : \DateTimeImmutable::createFromInterface($date);
        $diffSeconds = $now->getTimestamp() - $ts->getTimestamp();
        if ($diffSeconds < 0) {
            $diffSeconds = 0;
        }
        $diffMinutes = (int) floor($diffSeconds / 60);
        $diffHours = (int) floor($diffSeconds / 3600);
        $diffDays = (int) floor($diffSeconds / 86400);

        if ($diffMinutes < 1) {
            return "a l'instant";
        }
        if ($diffMinutes < 60) {
            return 'il y a ' . $diffMinutes . ' min';
        }
        if ($diffHours < 24) {
            return 'il y a ' . $diffHours . ' heure' . ($diffHours > 1 ? 's' : '');
        }
        if ($diffDays === 1) {
            return 'hier';
        }
        if ($diffDays < 7) {
            return 'il y a ' . $diffDays . ' jours';
        }
        if ($diffDays < 30) {
            $weeks = (int) ceil($diffDays / 7);
            return 'il y a ' . $weeks . ' semaine' . ($weeks > 1 ? 's' : '');
        }
        $months = (int) floor($diffDays / 30);
        return 'il y a ' . max(1, $months) . ' mois';
    }

    /**
     * Build an anonymized activity ticker of the 8 most recent platform events.
     */
    private function buildActivityTicker(
        InvestmentOpportunityRepository $oppRepo,
        InvestmentOfferRepository $offerRepo,
        InvestmentContractRepository $contractRepo,
        ContractMilestoneRepository $milestoneRepo,
    ): array {
        $events = [];
        $now = new \DateTimeImmutable();
        $limit = 4;

        // 1. New opportunities
        $opps = $oppRepo->createQueryBuilder('o')
            ->leftJoin('o.project', 'p')
            ->where('o.status = :s')->setParameter('s', 'OPEN')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($opps as $opp) {
            $sector = $opp->getProject()?->getSecteur() ?: 'un secteur innovant';
            $events[] = ['dot' => '#3b82f6', 'text' => 'New ' . $sector . ' project seeking funding', 'date' => $opp->getCreatedAt()];
        }

        // 2. Offers submitted
        $offers = $offerRepo->createQueryBuilder('o')
            ->leftJoin('o.opportunity', 'opp')->leftJoin('opp.project', 'p')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($offers as $off) {
            $sector = $off->getOpportunity()?->getProject()?->getSecteur() ?: 'un projet';
            $events[] = ['dot' => '#94a3b8', 'text' => 'An investor submitted an offer on a ' . $sector . ' project', 'date' => $off->getCreatedAt()];
        }

        // 3. Offers accepted
        $accepted = $offerRepo->createQueryBuilder('o')
            ->where('o.status = :s')->setParameter('s', 'ACCEPTED')
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($accepted as $off) {
            $events[] = ['dot' => '#f59e0b', 'text' => 'An offer was accepted — negotiation has started', 'date' => $off->getUpdatedAt()];
        }

        // 4. Contracts signed
        $contracts = $contractRepo->createQueryBuilder('c')
            ->where('c.investorSignedAt IS NOT NULL AND c.entrepreneurSignedAt IS NOT NULL')
            ->orderBy('c.entrepreneurSignedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($contracts as $c) {
            $d = $c->getEntrepreneurSignedAt() > $c->getInvestorSignedAt() ? $c->getEntrepreneurSignedAt() : $c->getInvestorSignedAt();
            $events[] = ['dot' => '#22c55e', 'text' => 'A deal was signed between two parties', 'date' => $d];
        }

        // 5. Payments completed
        $paid = $offerRepo->createQueryBuilder('o')
            ->where('o.paid = :p AND o.paidAt IS NOT NULL')->setParameter('p', true)
            ->orderBy('o.paidAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($paid as $off) {
            $events[] = ['dot' => '#f59e0b', 'text' => 'An investment was successfully funded', 'date' => $off->getPaidAt()];
        }

        // 6. Milestones released
        $milestones = $milestoneRepo->createQueryBuilder('m')
            ->where('m.releasedAt IS NOT NULL')
            ->orderBy('m.releasedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
        foreach ($milestones as $ms) {
            $events[] = ['dot' => '#06b6d4', 'text' => 'Un jalon projet a ete confirme et libere', 'date' => $ms->getReleasedAt()];
        }

        // Sort by date DESC, take top 8
        usort($events, fn($a, $b) => ($b['date'] ?? new \DateTime('2000-01-01')) <=> ($a['date'] ?? new \DateTime('2000-01-01')));
        $events = array_slice($events, 0, 8);

        foreach ($events as &$e) {
            $e['ago'] = $this->buildRelativeTime($e['date'], $now);
            unset($e['date']);
        }

        return $events;
    }

    /**
     * Build a human-readable pitch line for an opportunity card.
     */
    private function buildPitchLine(InvestmentOpportunity $opp, float $inflation): string
    {
        $parts = [];
        $now = new \DateTimeImmutable();

        // Sector fragment
        $sector = $opp->getProject()?->getSecteur();
        $amount = (float) $opp->getTargetAmount();
        $deadline = $opp->getDeadline();

        // Opening: description snippet or sector-based opener
        $desc = trim($opp->getDescription() ?? '');
        if ($desc !== '') {
            $words = preg_split('/\s+/', $desc);
            if (count($words) > 15) {
                $words = array_slice($words, 0, 15);
            }
            $snippet = implode(' ', $words);
            // Only use if meaningful (more than 3 words)
            if (count($words) >= 4) {
                $parts[] = $snippet;
            }
        }

        if (empty($parts) && $sector) {
            $parts[] = 'Projet ' . mb_strtolower($sector) . ' en phase de lancement';
        }

        // Amount + sector qualifier
        $amountStr = number_format($amount, 0, ',', ' ');
        if ($sector && !empty($parts)) {
            $parts[] = 'recherchant ' . $amountStr . ' TND';
        } elseif (!$sector) {
            $parts[] = 'Recherche de ' . $amountStr . ' TND';
        } else {
            $parts[] = 'recherchant ' . $amountStr . ' TND';
        }

        // Deadline proximity
        if ($deadline) {
            $daysLeft = max(0, (int) $now->diff($deadline)->format('%r%a'));
            if ($daysLeft <= 0) {
                // expired, skip
            } elseif ($daysLeft <= 14) {
                $parts[] = 'echeance dans ' . $daysLeft . ' jours';
            } elseif ($daysLeft <= 60) {
                $weeks = (int) round($daysLeft / 7);
                $parts[] = 'echeance dans ' . $weeks . ' semaine' . ($weeks > 1 ? 's' : '');
            } elseif ($daysLeft <= 365) {
                $months = (int) round($daysLeft / 30);
                $parts[] = 'horizon de ' . $months . ' mois';
            }
        }

        // Economic context
        if ($inflation >= 7.0) {
            $parts[] = 'dans un contexte d\'inflation elevee';
        } elseif ($inflation >= 5.0) {
            $parts[] = 'conditions economiques moderees';
        } elseif ($inflation <= 2.5) {
            $parts[] = 'conditions economiques stables';
        }

        // Assemble sentence
        $line = implode(' — ', $parts);
        // Capitalize first letter, end with period
        $line = mb_strtoupper(mb_substr($line, 0, 1)) . mb_substr($line, 1);
        if (!str_ends_with($line, '.')) {
            $line .= '.';
        }

        return $line;
    }

    /**
     * Build the top 2 risk factors as short phrases for the risk acknowledgment gate.
     */
    private function buildTopRiskFactors(InvestmentOpportunity $opp, array $ecoData, EconomicRiskEngine $engine): array
    {
        $factors = [];
        $inflation = (float) ($ecoData['inflationRate'] ?? 5.0);
        $deadline = $opp->getDeadline();
        $amount = (float) $opp->getTargetAmount();

        // Inflation factor
        if ($inflation >= 8.0) {
            $factors[] = ['weight' => 90, 'label' => sprintf('Inflation elevee (%.1f%%)', $inflation)];
        } elseif ($inflation >= 5.0) {
            $factors[] = ['weight' => 60, 'label' => sprintf('Inflation moderee (%.1f%%)', $inflation)];
        } else {
            $factors[] = ['weight' => 20, 'label' => sprintf('Inflation maitrisee (%.1f%%)', $inflation)];
        }

        // Deadline factor
        if ($deadline) {
            $days = (int) (new \DateTimeImmutable())->diff($deadline)->format('%r%a');
            if ($days <= 30) {
                $factors[] = ['weight' => 85, 'label' => 'Echeance tres courte (' . max(0, $days) . ' jours)'];
            } elseif ($days <= 90) {
                $factors[] = ['weight' => 55, 'label' => 'Echeance rapprochee (' . round($days / 30) . ' mois)'];
            }
        }

        // Amount factor
        if ($amount >= 200000) {
            $factors[] = ['weight' => 70, 'label' => 'Montant de financement eleve'];
        } elseif ($amount >= 100000) {
            $factors[] = ['weight' => 45, 'label' => 'Montant de financement significatif'];
        }

        // Exchange rate factor
        $eurUsd = (float) ($ecoData['exchangeRateEurUsd'] ?? 1.08);
        if ($eurUsd < 1.02 || $eurUsd > 1.18) {
            $factors[] = ['weight' => 50, 'label' => 'Volatilite du taux de change'];
        }

        // Sort by weight descending and take top 2
        usort($factors, fn($a, $b) => $b['weight'] <=> $a['weight']);
        return array_map(fn($f) => $f['label'], array_slice($factors, 0, 2));
    }

    #[Route('/my-offers', name: 'app_invest_my_offers')]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function myOffers(Request $request, InvestmentOfferRepository $repo, InvestmentContractRepository $contractRepo, PaginatorInterface $paginator): Response
    {
        $page = $request->query->getInt('page', 1);
        $qb = $repo->findUnpaidByInvestorQuery($this->getUser());
        $pagination = $paginator->paginate($qb, $page, 6);
        $offers = iterator_to_array($pagination);

        // ── Compute nudge ──
        $nudge = ['message' => 'All your offers are on track. No immediate action required.', 'link' => null, 'type' => 'success'];
        $user = $this->getUser();

        // Priority 1: signed contract awaiting payment
        foreach ($offers as $off) {
            $c = $off->getContract();
            if ($off->getStatus() === 'ACCEPTED' && $c && $c->isFullySigned() && !$off->isPaid()) {
                $projectName = $off->getOpportunity()?->getProject()?->getTitre() ?? 'un projet';
                $nudge = [
                    'message' => 'You have a signed contract ready for payment on ' . $projectName . '. Complete the payment to fund the project.',
                    'link' => $this->generateUrl('app_invest_my_offers'),
                    'type' => 'urgent',
                ];
                break;
            }
        }

        // Priority 2: one party signed, pending yours
        if ($nudge['type'] === 'success') {
            foreach ($offers as $off) {
                $c = $off->getContract();
                if ($off->getStatus() === 'ACCEPTED' && $c && !$c->isFullySigned()) {
                    $hasMySig = $c->hasSigned($user);
                    $hasOtherSig = ($c->getInvestorSignedAt() !== null || $c->getEntrepreneurSignedAt() !== null);
                    if (!$hasMySig && $hasOtherSig) {
                        $projectName = $off->getOpportunity()?->getProject()?->getTitre() ?? 'un projet';
                        $nudge = [
                            'message' => 'Your signature is pending on the contract for ' . $projectName . '. The agreement is waiting for you.',
                            'link' => $this->generateUrl('app_invest_contract_show', ['id' => $off->getId()]),
                            'type' => 'warning',
                        ];
                        break;
                    }
                }
            }
        }

        // Priority 3: accepted offer, no messages
        if ($nudge['type'] === 'success') {
            foreach ($offers as $off) {
                $c = $off->getContract();
                if ($off->getStatus() === 'ACCEPTED' && $c && $c->getLastMessageAt() === null) {
                    $projectName = $off->getOpportunity()?->getProject()?->getTitre() ?? 'un projet';
                    $nudge = [
                        'message' => 'You have an accepted offer on ' . $projectName . ' with no negotiation started. Open the contract to begin.',
                        'link' => $this->generateUrl('app_invest_contract_show', ['id' => $off->getId()]),
                        'type' => 'info',
                    ];
                    break;
                }
            }
        }

        // Priority 4: pending offers awaiting entrepreneur response
        if ($nudge['type'] === 'success') {
            foreach ($offers as $off) {
                if ($off->getStatus() === 'PENDING') {
                    $projectName = $off->getOpportunity()?->getProject()?->getTitre() ?? 'un projet';
                    $nudge = [
                        'message' => 'Your offer on ' . $projectName . ' is awaiting the entrepreneur\'s decision.',
                        'link' => null,
                        'type' => 'info',
                    ];
                    break;
                }
            }
        }

        return $this->render('front/investment/my_offers.html.twig', [
            'offers' => $offers,
            'pagination' => $pagination,
            'nudge' => $nudge,
        ]);
    }

    private function redirectAfterOfferAction(Request $request, InvestmentOffer $offer): Response
    {
        $redirectRoute = $request->request->get('_redirect_route');
        if ($redirectRoute === 'app_invest_entrepreneur_inbox') {
            return $this->redirectToRoute('app_invest_entrepreneur_inbox');
        }

        return $this->redirectToRoute('app_invest_opportunity_show', ['id' => $offer->getOpportunity()->getId()]);
    }

    #[Route('/my-contracts', name: 'app_invest_my_contracts')]
    public function myContracts(InvestmentContractRepository $contractRepo): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('Authentification requise.');
        }

        $contracts = $contractRepo->findRecentForUser($user, 50);

        $summary = [
            'total' => count($contracts),
            'awaitingMySignature' => 0,
            'awaitingOtherSignature' => 0,
            'fullySigned' => 0,
            'funded' => 0,
        ];

        foreach ($contracts as $contract) {
            if ($contract->getStatus() === \App\Entity\InvestmentContract::STATUS_FUNDED) {
                $summary['funded']++;
            }

            if ($contract->isFullySigned()) {
                $summary['fullySigned']++;
                continue;
            }

            if ($contract->hasSigned($user)) {
                $summary['awaitingOtherSignature']++;
            } else {
                $summary['awaitingMySignature']++;
            }
        }

        return $this->render('front/investment/my_contracts.html.twig', [
            'contracts' => $contracts,
            'summary' => $summary,
            'currentUser' => $user,
        ]);
    }

    #[Route('/portfolio', name: 'app_invest_portfolio')]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function portfolio(InvestmentOfferRepository $repo, InvestmentContractRepository $contractRepo): Response
    {
        if (!$this->isGranted('ROLE_INVESTISSEUR')) {
            throw $this->createAccessDeniedException('Le portfolio est reserve aux investisseurs.');
        }

        $offers = $repo->findPaidByInvestor($this->getUser());

        // Build contract / milestone data for each offer
        $portfolioData = [];
        foreach ($offers as $offer) {
            $contract = $contractRepo->findOneBy(['offer' => $offer]);
            $milestones = $contract ? $contract->getFundingMilestones()->toArray() : [];
            $equity = $contract ? (float) $contract->getEquityPercentage() : 0;
            $amount = (float) $offer->getProposedAmount();
            $paidAt = $offer->getPaidAt();
            $daysElapsed = $paidAt ? (new \DateTime())->diff($paidAt)->days : 0;
            $targetAmount = (float) $offer->getOpportunity()->getTargetAmount();

            // Build funding timeline events
            $timeline = [];
            $timeline[] = ['date' => $offer->getCreatedAt(), 'label' => 'Offer submitted', 'icon' => 'send'];
            if ($contract) {
                $timeline[] = ['date' => $contract->getCreatedAt(), 'label' => 'Contract created', 'icon' => 'file-earmark-text'];
                if ($contract->getInvestorSignedAt()) {
                    $timeline[] = ['date' => $contract->getInvestorSignedAt(), 'label' => 'Investor signed', 'icon' => 'pen'];
                }
                if ($contract->getEntrepreneurSignedAt()) {
                    $timeline[] = ['date' => $contract->getEntrepreneurSignedAt(), 'label' => 'Entrepreneur signed', 'icon' => 'pen'];
                }
            }
            if ($paidAt) {
                $timeline[] = ['date' => $paidAt, 'label' => 'Payment completed', 'icon' => 'credit-card'];
            }
            // Milestone release events
            foreach ($milestones as $ms) {
                if ($ms->getReleasedAt()) {
                    $timeline[] = ['date' => $ms->getReleasedAt(), 'label' => 'Milestone: ' . $ms->getLabel(), 'icon' => 'flag'];
                }
            }
            // Future milestones (pending)
            foreach ($milestones as $ms) {
                if (!$ms->getReleasedAt()) {
                    $timeline[] = ['date' => null, 'label' => $ms->getLabel(), 'icon' => 'flag', 'future' => true];
                }
            }
            usort($timeline, function ($a, $b) {
                if ($a['date'] === null && $b['date'] === null) return 0;
                if ($a['date'] === null) return 1;
                if ($b['date'] === null) return 1;
                return $a['date'] <=> $b['date'];
            });

            $portfolioData[] = [
                'offer' => $offer,
                'contract' => $contract,
                'milestones' => $milestones,
                'equity' => $equity,
                'daysElapsed' => $daysElapsed,
                'projectedReturn' => $equity > 0 ? round($amount / ($equity / 100), 2) : null,
                'milestoneReleasedPct' => $contract ? $contract->getMilestoneProgressPercent() : 0,
                'timeline' => $timeline,
                'targetAmount' => $targetAmount,
            ];
        }

        // ── Portfolio summary for greeting ──
        $portfolioSummary = ['count' => count($offers), 'totalAmount' => 0.0, 'recentProject' => null, 'recentDaysAgo' => null, 'pendingMilestoneProject' => null, 'pendingMilestoneOfferId' => null];
        foreach ($offers as $offer) {
            $portfolioSummary['totalAmount'] += (float) $offer->getProposedAmount();
        }
        if (!empty($offers)) {
            $latest = $offers[0]; // already sorted by paidAt DESC
            $portfolioSummary['recentProject'] = $latest->getOpportunity()?->getProject()?->getTitre() ?? 'un projet';
            $portfolioSummary['recentDaysAgo'] = $latest->getPaidAt() ? (new \DateTime())->diff($latest->getPaidAt())->days : 0;
        }
        // Check for pending milestones
        foreach ($portfolioData as $item) {
            foreach ($item['milestones'] as $ms) {
                if (!$ms->getReleasedAt()) {
                    $portfolioSummary['pendingMilestoneProject'] = $item['offer']->getOpportunity()?->getProject()?->getTitre();
                    $portfolioSummary['pendingMilestoneOfferId'] = $item['offer']->getId();
                    break 2;
                }
            }
        }

        return $this->render('front/investment/portfolio.html.twig', [
            'offers' => $offers,
            'portfolioData' => $portfolioData,
            'portfolioSummary' => $portfolioSummary,
        ]);
    }

    #[Route('/my-offers/ajax', name: 'app_invest_my_offers_ajax', methods: ['GET'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function myOffersAjax(Request $request, InvestmentOfferRepository $repo, PaginatorInterface $paginator): JsonResponse
    {
        $search = trim($request->query->get('q', ''));
        $statusFilter = $request->query->get('status', '');
        $page = $request->query->getInt('page', 1);

        $qb = $repo->findUnpaidByInvestorQuery($this->getUser());

        if ($statusFilter !== '') {
            $qb->andWhere('o.status = :status')->setParameter('status', $statusFilter);
        }
        if ($search !== '') {
            $qb->leftJoin('o.opportunity', 'opp')
               ->leftJoin('opp.project', 'proj')
               ->andWhere('LOWER(proj.titre) LIKE :q OR CAST(o.proposedAmount AS string) LIKE :q')
               ->setParameter('q', '%' . strtolower($search) . '%');
        }

        $pagination = $paginator->paginate($qb, $page, 6);
        $filtered = iterator_to_array($pagination);

        $data = [];
        foreach ($filtered as $offer) {
            $contract = $offer->getContract();
            $data[] = [
                'id' => $offer->getId(),
                'projectTitle' => $offer->getOpportunity()->getProject() ? $offer->getOpportunity()->getProject()->getTitre() : 'N/A',
                'proposedAmount' => number_format((float) $offer->getProposedAmount(), 0, ',', ' '),
                'status' => $offer->getStatus(),
                'createdAt' => $offer->getCreatedAt()->format('d/m/Y H:i'),
                'targetAmount' => number_format((float) $offer->getOpportunity()->getTargetAmount(), 0, ',', ' '),
                'paid' => $offer->isPaid(),
                'paymentIntentId' => $offer->getPaymentIntentId(),
                'paidAt' => $offer->getPaidAt()?->format('d/m/Y H:i'),
                'canPay' => $offer->getStatus() === InvestmentOffer::STATUS_ACCEPTED && !$offer->isPaid() && $offer->isContractReadyForPayment(),
                'payUrl' => $this->generateUrl('app_invest_offer_pay', ['id' => $offer->getId()]),
                'payToken' => $this->container->get('security.csrf.token_manager')->getToken('pay_offer_' . $offer->getId())->getValue(),
                'showUrl' => $this->generateUrl('app_invest_opportunity_show', ['id' => $offer->getOpportunity()->getId()]),
                'contractUrl' => $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]),
                'contractExists' => $contract !== null,
                'contractStatus' => $contract?->getStatus(),
                'contractSigned' => $contract?->isFullySigned() ?? false,
            ];
        }

        return $this->json([
            'count' => $pagination->getTotalItemCount(),
            'page' => $pagination->getCurrentPageNumber(),
            'totalPages' => (int) ceil($pagination->getTotalItemCount() / $pagination->getItemNumberPerPage()),
            'perPage' => $pagination->getItemNumberPerPage(),
            'offers' => $data,
        ]);
    }

    #[Route('/my-offers/{id}/pay', name: 'app_invest_offer_pay', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function payOffer(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        StripePaymentService $paymentService,
        NotificationService $notificationService,
        EmailService $emailService,
        LoggerInterface $logger,
    ): JsonResponse {
        if ($offer->getInvestor() !== $this->getUser()) {
            return $this->json(['error' => 'Vous ne pouvez pas payer cette offre.'], 403);
        }

        if (!$this->isCsrfTokenValid('pay_offer_' . $offer->getId(), $request->request->get('_token'))) {
            return $this->json(['error' => 'Jeton de securite invalide.'], 403);
        }

        if ($offer->getStatus() !== InvestmentOffer::STATUS_ACCEPTED) {
            return $this->json(['error' => 'Le paiement est disponible uniquement pour les offres acceptees.'], 400);
        }

        if ($offer->isPaid()) {
            return $this->json([
                'success' => true,
                'alreadyPaid' => true,
                'message' => 'Cette offre a deja ete reglee.',
                'paymentIntentId' => $offer->getPaymentIntentId(),
                'paidAt' => $offer->getPaidAt()?->format('d/m/Y H:i'),
            ]);
        }

        // ── Hard signature gate — authoritative rule ──
        $contractRepo = $em->getRepository(\App\Entity\InvestmentContract::class);
        $contract = $contractRepo->findOneBy(['offer' => $offer]);
        $contractUrl = $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]);

        if (!$contract) {
            return $this->json([
                'error' => 'Le paiement est impossible — aucun contrat n\'a ete cree pour cette offre.',
                'redirectTo' => $contractUrl,
            ], 400);
        }

        if (!$contract->isFullySigned()) {
            return $this->json([
                'error' => 'Le paiement est impossible — les deux parties doivent signer le contrat avant de proceder au paiement.',
                'redirectTo' => $contractUrl,
            ], 400);
        }

        // Block lump-sum payment when milestones are defined
        if ($contract->hasFundingMilestones()) {
            return $this->json([
                'error' => 'Ce contrat utilise un paiement par jalons. Rendez-vous sur la page du contrat pour liberer les paiements etape par etape.',
                'redirectTo' => $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]),
            ], 400);
        }

        $result = $paymentService->payAcceptedOffer($offer);
        if (!($result['success'] ?? false)) {
            return $this->json(['error' => $result['error'] ?? 'Paiement Stripe echoue.'], 400);
        }

        $offer->setPaid(true);
        $offer->setPaidAt(new \DateTime());
        $offer->setPaymentIntentId($result['paymentIntentId'] ?? null);
        if ($contract->getStatus() !== \App\Entity\InvestmentContract::STATUS_FUNDED) {
            $contract->setStatus(\App\Entity\InvestmentContract::STATUS_FUNDED);
        }
        $this->markOpportunityFundedIfPaidOut($offer->getOpportunity());
        $contractUrl = $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]);
        $notificationService->notify(
            $offer->getInvestor(),
            'Paiement confirme',
            'Votre paiement pour ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'ce projet') . ' a ete confirme. Vous pouvez consulter a tout moment le contrat signe.',
            'SUCCESS',
            $contractUrl,
            'Voir le contrat'
        );
        $notificationService->notify(
            $offer->getOpportunity()->getProject()?->getUser(),
            'Paiement recu',
            'Le paiement lie au contrat de ' . ($offer->getInvestor()?->getFullName() ?? 'l\'investisseur') . ' a ete confirme pour ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'ce projet') . '.',
            'SUCCESS',
            $contractUrl,
            'Voir le contrat'
        );
        $em->flush();

        try {
            if ($offer->getInvestor()?->getEmail()) {
                $emailService->sendInvestmentPaymentConfirmation(
                    $offer->getInvestor()->getEmail(),
                    $offer->getInvestor()->getFirstname() ?? 'Utilisateur',
                    $offer->getOpportunity()->getProject()?->getTitre() ?? 'Projet',
                    number_format((float) $offer->getProposedAmount(), 0, ',', ' ') . ' DT',
                    $offer->getPaidAt()?->format('d/m/Y H:i') ?? (new \DateTime())->format('d/m/Y H:i'),
                    $offer->getPaymentIntentId(),
                    $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
                    'Paiement integral du contrat'
                );
            }
        } catch (\Throwable $exception) {
            $logger->error('Unable to send investment payment confirmation email.', [
                'offerId' => $offer->getId(),
                'paymentIntentId' => $offer->getPaymentIntentId(),
                'exception' => $exception,
            ]);
        }

        return $this->json([
            'success' => true,
            'message' => 'Paiement confirme avec succes.',
            'paymentIntentId' => $offer->getPaymentIntentId(),
            'paidAt' => $offer->getPaidAt()?->format('d/m/Y H:i'),
            'currency' => $result['currency'] ?? 'EUR',
            'status' => $result['status'] ?? 'succeeded',
            'amount' => number_format((float) $offer->getProposedAmount(), 0, ',', ' '),
            'projectTitle' => $offer->getOpportunity()->getProject()?->getTitre() ?? 'Projet',
        ]);
    }

    private function markOpportunityFundedIfPaidOut(InvestmentOpportunity $opportunity): void
    {
        if ($opportunity->getTotalFunded() >= (float) $opportunity->getTargetAmount()) {
            $opportunity->setStatus(InvestmentOpportunity::STATUS_FUNDED);
        }
    }

    #[Route('/create-opportunity', name: 'app_invest_create_opportunity', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ENTREPRENEUR')]
    public function createOpportunity(Request $request, EntityManagerInterface $em, ValidatorInterface $validator, ProjetRepository $projetRepo): Response
    {
        // Entrepreneurs seek investment on their own projects
        $projets = $projetRepo->findByUser($this->getUser());

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('create_opportunity', $token)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->render('front/investment/create_opportunity.html.twig', ['projets' => $projets]);
            }

            $project = $em->getRepository(Projet::class)->find($request->request->get('project_id'));
            if (!$project) {
                $this->addFlash('danger', 'Project not found.');
                return $this->render('front/investment/create_opportunity.html.twig', ['projets' => $projets]);
            }

            $fieldErrors = [];
            $targetAmount = $request->request->get('target_amount');
            if (!is_numeric($targetAmount) || (float) $targetAmount < 100) {
                $fieldErrors['target_amount'] = 'The target amount must be at least 100 TND.';
            }

            $deadlineStr = $request->request->get('deadline');
            $deadline = null;
            if (!$deadlineStr || strtotime($deadlineStr) === false) {
                $fieldErrors['deadline'] = 'Invalid deadline date.';
            } else {
                $deadline = new \DateTime($deadlineStr);
                if ($deadline <= new \DateTime('today')) {
                    $fieldErrors['deadline'] = 'Deadline must be in the future.';
                }
            }

            $description = trim($request->request->get('description', ''));
            if (mb_strlen($description) < 10) {
                $fieldErrors['description'] = 'Description must be at least 10 characters.';
            }

            if (!empty($fieldErrors)) {
                return $this->render('front/investment/create_opportunity.html.twig', ['projets' => $projets, 'fieldErrors' => $fieldErrors]);
            }

            $opp = new InvestmentOpportunity();
            $opp->setProject($project);
            $opp->setTargetAmount((string) (float) $targetAmount);
            $opp->setDescription($description);
            $opp->setDeadline($deadline);
            $opp->setStatus('OPEN');

            $errors = $validator->validate($opp);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('front/investment/create_opportunity.html.twig', ['projets' => $projets, 'fieldErrors' => $fieldErrors]);
            }

            $em->persist($opp);
            $em->flush();

            $this->addFlash('success', 'Investment opportunity created successfully!');
            return $this->redirectToRoute('app_invest_opportunities');
        }

        return $this->render('front/investment/create_opportunity.html.twig', ['projets' => $projets]);
    }
}
