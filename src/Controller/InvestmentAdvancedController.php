<?php

namespace App\Controller;

use App\Entity\InvestmentOpportunity;
use App\Entity\InvestorProfile;
use App\Repository\InvestmentOpportunityRepository;
use App\Repository\InvestorProfileRepository;
use App\Service\Investment\CurrencyService;
use App\Service\Investment\EconomicApiService;
use App\Service\Investment\EconomicRiskEngine;
use App\Service\Investment\InvestmentChatbotService;
use App\Service\Investment\InvestmentMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/investissement/advanced')]
#[IsGranted('ROLE_USER')]
class InvestmentAdvancedController extends AbstractController
{
    // ─── Economic Dashboard ──────────────────────────────────

    #[Route('/economic-dashboard', name: 'app_invest_economic_dashboard')]
    public function economicDashboard(InvestmentOpportunityRepository $oppRepo, EconomicApiService $ecoApi): Response
    {
        $riskCounts = $oppRepo->countOpenByRiskBracket();
        $sectorCounts = $oppRepo->countOpenBySector();
        $shortDeadlineCount = $oppRepo->countOpenShortDeadline(6);

        // Fetch current eco data for investment climate verdict
        $ecoData = [];
        try {
            $ecoData = $ecoApi->fetchAllEconomicData('TN');
        } catch (\Throwable $e) {
            // fallback empty
        }

        $climateVerdict = $this->buildClimateVerdict($ecoData);

        // Count high-inflation-sensitive opportunities (projects in import-heavy sectors)
        $inflationSensitive = 0;
        $importSectors = ['Technologie', 'Industrie', 'Industrie textile', 'Agroalimentaire', 'Energie renouvelable'];
        foreach ($sectorCounts as $sector => $count) {
            foreach ($importSectors as $is) {
                if (stripos($sector, $is) !== false) {
                    $inflationSensitive += $count;
                    break;
                }
            }
        }

        return $this->render('front/investment/economic_dashboard.html.twig', [
            'riskCounts' => $riskCounts,
            'sectorCounts' => $sectorCounts,
            'shortDeadlineCount' => $shortDeadlineCount,
            'inflationSensitive' => $inflationSensitive,
            'climateVerdict' => $climateVerdict,
            'ecoData' => $ecoData,
        ]);
    }

    /**
     * Build a one-sentence investment climate verdict from threshold logic.
     */
    private function buildClimateVerdict(array $ecoData): string
    {
        if (empty($ecoData)) {
            return 'Economic data unavailable. Indicators cannot be evaluated at this time.';
        }

        $inflation = (float) ($ecoData['inflationRate'] ?? 5.0);
        $eurUsd = (float) ($ecoData['exchangeRateEurUsd'] ?? 1.08);
        $gdp = (float) ($ecoData['gdpBillions'] ?? 50.0);
        $parts = [];

        if ($inflation >= 8.0) {
            $parts[] = 'high inflation';
        } elseif ($inflation >= 5.0) {
            $parts[] = 'moderate inflation';
        }

        if ($eurUsd < 1.02) {
            $parts[] = 'an unfavorable EUR exchange rate';
        } elseif ($eurUsd > 1.15) {
            $parts[] = 'a strong euro';
        }

        if ($gdp < 50) {
            $parts[] = 'a modest national GDP';
        }

        if (empty($parts)) {
            return 'Current economic conditions are relatively favorable for investments on the platform.';
        }

        $joinedParts = implode(' and ', $parts);

        if ($inflation >= 8.0 || $eurUsd < 1.02) {
            return ucfirst($joinedParts) . ' create headwinds for short-horizon projects in import-dependent sectors.';
        }

        return ucfirst($joinedParts) . ' call for moderate caution on short-term commitments.';
    }

    #[Route('/economic-data', name: 'app_invest_economic_data', methods: ['GET'])]
    public function economicData(
        Request $request,
        EconomicApiService $api,
        EconomicRiskEngine $engine,
    ): JsonResponse {
        $country = $request->query->get('country', 'TN');
        $allowed = array_keys($api->getSupportedCountries());
        if (!in_array(strtoupper($country), $allowed, true)) {
            $country = 'TN';
        }

        $data = $api->fetchAllEconomicData($country);
        $economicFactor = $engine->computeEconomicFactor($data);

        $data['economicRiskFactor'] = round($economicFactor, 1);
        $data['riskLevel'] = EconomicRiskEngine::getRiskLevel((int) $economicFactor);
        $data['riskColor'] = EconomicRiskEngine::getRiskColor((int) $economicFactor);
        $data['recommendation'] = EconomicRiskEngine::getRecommendation((int) $economicFactor);

        return $this->json($data);
    }

    // ─── Currency Converter ──────────────────────────────────

    #[Route('/currency-convert', name: 'app_invest_currency_convert', methods: ['GET'])]
    public function currencyConvert(Request $request, CurrencyService $currency): JsonResponse
    {
        $currency->fetchRates();

        $amount = (float) $request->query->get('amount', 1);
        $from = strtoupper($request->query->get('from', 'EUR'));
        $to = strtoupper($request->query->get('to', 'TND'));

        if (!in_array($from, CurrencyService::CURRENCIES, true)) $from = 'EUR';
        if (!in_array($to, CurrencyService::CURRENCIES, true)) $to = 'TND';

        $converted = $currency->convert($amount, $from, $to);

        return $this->json([
            'amount' => $amount,
            'from' => $from,
            'to' => $to,
            'converted' => round($converted, 4),
            'formatted' => CurrencyService::format($converted, $to),
            'rate' => round($currency->getRate($from, $to), 6),
            'rates' => $currency->getRates(),
        ]);
    }

    // ─── Risk Analysis ───────────────────────────────────────

    #[Route('/risk-analysis/{id}', name: 'app_invest_risk_analysis', requirements: ['id' => '\d+'])]
    public function riskAnalysis(InvestmentOpportunity $opp): Response
    {
        $isOwner = $this->getUser() && $opp->getProject()?->getUser() === $this->getUser();

        return $this->render('front/investment/risk_analysis.html.twig', [
            'opportunity' => $opp,
            'isOwner' => $isOwner,
        ]);
    }

    #[Route('/risk-compute/{id}', name: 'app_invest_risk_compute', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function riskCompute(
        InvestmentOpportunity $opp,
        Request $request,
        EconomicApiService $api,
        EconomicRiskEngine $engine,
    ): JsonResponse {
        $country = $request->query->get('country', 'TN');
        $data = $api->fetchAllEconomicData($country);

        $targetAmount = (float) $opp->getTargetAmount();
        $deadline = $opp->getDeadline();

        $score = $engine->calculateFullRisk($targetAmount, $deadline, $data);
        $economicFactor = $engine->computeEconomicFactor($data);

        return $this->json([
            'score' => $score,
            'level' => EconomicRiskEngine::getRiskLevel($score),
            'color' => EconomicRiskEngine::getRiskColor($score),
            'recommendation' => EconomicRiskEngine::getRecommendation($score),
            'factors' => [
                'amount' => round($engine->normalizeAmount($targetAmount), 1),
                'duration' => round($engine->normalizeDuration($deadline), 1),
                'economic' => round($economicFactor, 1),
            ],
            'economicData' => [
                'country' => $data['countryName'] ?? $country,
                'eurUsd' => round($data['exchangeRateEurUsd'] ?? 0, 4),
                'eurTnd' => round($data['exchangeRateEurTnd'] ?? 0, 3),
                'gdp' => round($data['gdpBillions'] ?? 0, 1),
                'inflation' => round($data['inflationRate'] ?? 0, 1),
                'year' => $data['dataYear'] ?? 'N/A',
            ],
        ]);
    }

    // ─── AI Matching ─────────────────────────────────────────

    #[Route('/matching', name: 'app_invest_matching')]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function matching(InvestorProfileRepository $profileRepo): Response
    {
        $profile = $profileRepo->findByUser($this->getUser());

        return $this->render('front/investment/matching.html.twig', [
            'profile' => $profile,
        ]);
    }

    #[Route('/matching/results', name: 'app_invest_matching_results', methods: ['GET'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function matchingResults(
        InvestorProfileRepository $profileRepo,
        InvestmentMatchingService $matchingService,
    ): JsonResponse {
        $profile = $profileRepo->findByUser($this->getUser());
        if ($profile === null) {
            return $this->json(['error' => 'Creez votre profil investisseur d\'abord.', 'matches' => []], 400);
        }

        $matches = $matchingService->findMatches($profile);

        $data = [];
        foreach ($matches as $match) {
            $opp = $match['opportunity'];
            $data[] = [
                'id' => $opp->getId(),
                'projectTitle' => $opp->getProject()?->getTitre() ?? 'Projet',
                'sector' => $opp->getProject()?->getSecteur() ?? 'N/A',
                'targetAmount' => number_format((float) $opp->getTargetAmount(), 0, ',', ' '),
                'deadline' => $opp->getDeadline()?->format('d/m/Y'),
                'riskScore' => $opp->getRiskScore(),
                'compatibilityScore' => $match['score'],
                'explanation' => $match['explanation'],
                'showUrl' => $this->generateUrl('app_invest_opportunity_show', ['id' => $opp->getId()]),
                'riskUrl' => $this->generateUrl('app_invest_risk_analysis', ['id' => $opp->getId()]),
            ];
        }

        return $this->json(['matches' => $data]);
    }

    #[Route('/profile/save', name: 'app_invest_profile_save', methods: ['POST'])]
    #[IsGranted('ROLE_INVESTISSEUR')]
    public function saveProfile(
        Request $request,
        InvestorProfileRepository $profileRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('investor_profile', $token)) {
            return $this->json(['error' => 'Jeton de securite invalide.'], 403);
        }

        $profile = $profileRepo->findByUser($this->getUser());
        if ($profile === null) {
            $profile = new InvestorProfile();
            $profile->setUser($this->getUser());
        }

        $riskTolerance = max(1, min(10, (int) $request->request->get('riskTolerance', 5)));
        $budgetMin = max(0, (float) $request->request->get('budgetMin', 0));
        $budgetMax = max(0, (float) $request->request->get('budgetMax', 10000000));
        $horizonMonths = max(1, (int) $request->request->get('horizonMonths', 12));

        if ($budgetMin >= $budgetMax) {
            return $this->json([
                'error' => 'Le budget minimum doit etre strictement inferieur au budget maximum.',
            ], 400);
        }

        $profile->setPreferredSectors($request->request->get('sectors', ''));
        $profile->setRiskTolerance($riskTolerance);
        $profile->setBudgetMin((string) $budgetMin);
        $profile->setBudgetMax((string) $budgetMax);
        $profile->setHorizonMonths($horizonMonths);
        $profile->setDescription($request->request->get('description'));

        $em->persist($profile);
        $em->flush();

        return $this->json(['success' => true, 'message' => 'Profil enregistre avec succes.']);
    }

    // ─── Chatbot ─────────────────────────────────────────────

    #[Route('/chatbot', name: 'app_invest_chatbot', methods: ['POST'])]
    public function chatbot(
        Request $request,
        InvestmentChatbotService $chatbot,
        InvestmentOpportunityRepository $oppRepo,
        InvestorProfileRepository $profileRepo,
    ): JsonResponse {
        $message = trim($request->request->get('message', ''));
        if ($message === '') {
            return $this->json(['response' => null, 'error' => 'Message vide.'], 400);
        }

        if (mb_strlen($message) > 2000) {
            return $this->json(['response' => null, 'error' => 'Message trop long (max 2000 caracteres).'], 400);
        }

        // Decode conversation history
        $historyRaw = $request->request->get('conversationHistory', '[]');
        $conversationHistory = json_decode($historyRaw, true);
        if (!is_array($conversationHistory)) {
            $conversationHistory = [];
        }

        // Build context from POST params
        $context = [
            'mode' => 'risk',
            'opportunityTitle' => $request->request->get('opportunityTitle', 'N/A'),
            'sector' => $request->request->get('sector', 'N/A'),
            'fundingTarget' => $request->request->get('fundingTarget', 'N/A'),
            'deadline' => $request->request->get('deadline', 'N/A'),
            'riskScore' => $request->request->get('riskScore', 'N/A'),
            'riskLevel' => $request->request->get('riskLevel', 'N/A'),
            'inflationRate' => $request->request->get('inflationRate', 'N/A'),
            'gdpGrowth' => $request->request->get('gdpGrowth', 'N/A'),
            'exchangeRate' => $request->request->get('exchangeRate', 'N/A'),
            'investorBudgetMin' => $request->request->get('investorBudgetMin', 'N/A'),
            'investorBudgetMax' => $request->request->get('investorBudgetMax', 'N/A'),
            'investorPreferredSectors' => $request->request->get('investorPreferredSectors', 'N/A'),
            'investorRiskTolerance' => $request->request->get('investorRiskTolerance', 'N/A'),
        ];

        // Enrich context from DB if opportunityId provided
        $oppId = (int) $request->request->get('opportunityId', 0);
        if ($oppId > 0) {
            $opp = $oppRepo->find($oppId);
            if ($opp) {
                if ($context['opportunityTitle'] === 'N/A') {
                    $context['opportunityTitle'] = $opp->getProject()?->getTitre() ?? 'N/A';
                }
                if ($context['sector'] === 'N/A') {
                    $context['sector'] = $opp->getProject()?->getSecteur() ?? 'N/A';
                }
                if ($context['fundingTarget'] === 'N/A') {
                    $context['fundingTarget'] = (string) $opp->getTargetAmount();
                }
                if ($context['deadline'] === 'N/A') {
                    $context['deadline'] = $opp->getDeadline()?->format('d/m/Y') ?? 'N/A';
                }
            }
        }

        // Enrich investor profile from DB
        $profile = $profileRepo->findByUser($this->getUser());
        if ($profile) {
            if ($context['investorBudgetMin'] === 'N/A') {
                $context['investorBudgetMin'] = $profile->getBudgetMin();
            }
            if ($context['investorBudgetMax'] === 'N/A') {
                $context['investorBudgetMax'] = $profile->getBudgetMax();
            }
            if ($context['investorPreferredSectors'] === 'N/A') {
                $context['investorPreferredSectors'] = $profile->getPreferredSectors() ?? 'N/A';
            }
            if ($context['investorRiskTolerance'] === 'N/A') {
                $context['investorRiskTolerance'] = (string) $profile->getRiskTolerance();
            }
        }

        try {
            $response = $chatbot->chatWithContext($message, $context, $conversationHistory);
            return $this->json(['response' => $response, 'error' => null]);
        } catch (\Throwable $e) {
            return $this->json(['response' => null, 'error' => 'Erreur du service IA.'], 500);
        }
    }

    #[Route('/chatbot/analyze-risk', name: 'app_invest_chatbot_risk', methods: ['POST'])]
    public function chatbotRiskAnalysis(
        Request $request,
        InvestmentChatbotService $chatbot,
        InvestmentOpportunityRepository $oppRepo,
    ): JsonResponse {
        $oppId = (int) $request->request->get('opportunityId', 0);
        $opp = $oppRepo->find($oppId);
        if ($opp === null) {
            return $this->json(['error' => 'Opportunite introuvable.'], 404);
        }

        $response = $chatbot->analyzeRisk(
            $opp->getProject()?->getTitre() ?? 'Projet',
            $opp->getProject()?->getSecteur() ?? 'N/A',
            (float) $opp->getTargetAmount(),
            $opp->getDeadline()?->format('d/m/Y') ?? 'N/A',
            $opp->getDescription() ?? '',
            $opp->getRiskScore() ?? 50.0,
        );

        return $this->json(['response' => $response]);
    }

    #[Route('/risk-verdict/{id}', name: 'app_invest_risk_verdict', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function riskVerdict(
        InvestmentOpportunity $opp,
        Request $request,
        InvestmentChatbotService $chatbot,
        EconomicApiService $api,
        EconomicRiskEngine $engine,
    ): JsonResponse {
        $country = $request->query->get('country', 'TN');
        $data = $api->fetchAllEconomicData($country);
        $riskScore = (float) ($opp->getRiskScore() ?? $engine->calculateFullRisk(
            (float) $opp->getTargetAmount(),
            $opp->getDeadline(),
            $data,
        ));
        $riskLevel = EconomicRiskEngine::getRiskLevel((int) $riskScore);

        $deterministicVerdict = $engine->buildDeterministicVerdict(
            (int) round($riskScore),
            $data,
            (float) $opp->getTargetAmount(),
            $opp->getDeadline(),
        );

        $aiAnalysis = null;
        if ($chatbot->isConfigured()) {
            $candidate = $chatbot->analyzeRisk(
                $opp->getProject()?->getTitre() ?? 'Projet',
                $opp->getProject()?->getSecteur() ?? 'N/A',
                (float) $opp->getTargetAmount(),
                $opp->getDeadline()?->format('d/m/Y') ?? 'N/A',
                ($opp->getDescription() ?? '') . sprintf(
                    "\n\nEconomic context: country %s, inflation %.1f%%, GDP %.1f Bn $, EUR/USD %.4f.",
                    $data['countryName'] ?? $country,
                    (float) ($data['inflationRate'] ?? 0),
                    (float) ($data['gdpBillions'] ?? 0),
                    (float) ($data['exchangeRateEurUsd'] ?? 0),
                ),
                $riskScore,
            );

            if (!$chatbot->isFailureResponse($candidate)) {
                $aiAnalysis = $candidate;
            }
        }

        return $this->json([
            'verdict' => $deterministicVerdict,
            'aiAnalysis' => $aiAnalysis,
            'riskLevel' => $riskLevel,
            'configured' => $chatbot->isConfigured(),
        ]);
    }
}
