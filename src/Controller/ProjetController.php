<?php

namespace App\Controller;

use App\Entity\DonneesBusiness;
use App\Entity\Projet;
use App\Repository\ProjetRepository;
use App\Service\ProjetBusinessPlanService;
use App\Service\ProjetExportService;
use App\Service\ProjetRecommendationService;
use App\Service\ProjetScoringService;
use App\Service\MlEventLogger;
use App\Service\SubmissionQualityService;
use App\Service\ExchangeRateService;
use App\Service\Investment\MacroRiskService;
use App\Service\NewsApiService;
use App\Service\GeminiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

#[Route('/projets')]
#[IsGranted('ROLE_USER')]
class ProjetController extends AbstractController
{
    public const SECTEURS = [
        'Technologie', 'Santé', 'Éducation', 'Finance', 'Commerce',
        'Agriculture', 'Tourisme', 'Immobilier', 'Transport', 'Énergie',
        'Alimentation', 'Mode & Textile', 'Industrie', 'Services', 'Artisanat',
    ];

    #[Route('', name: 'app_projet_index')]
    public function index(Request $request, ProjetRepository $repo): Response
    {
        $search = $request->query->get('q', '');
        $secteur = $request->query->get('secteur', '');
        $sort = $request->query->get('sort', 'dateCreation');
        $direction = $request->query->get('dir', 'DESC');

        $projets = $repo->findByUserWithFilters($this->getUser(), $search ?: null, $secteur ?: null, $sort, $direction);

        return $this->render('front/projet/index.html.twig', [
            'projets' => $projets,
            'secteurs' => self::SECTEURS,
            'search' => $search,
            'selectedSecteur' => $secteur,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    #[Route('/new', name: 'app_projet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ValidatorInterface $validator, MailerInterface $mailer, MlEventLogger $mlEventLogger, SubmissionQualityService $qualityService): Response
    {
        if ($request->isMethod('POST')) {
            $projet = new Projet();
            $projet->setUser($this->getUser());
            $this->hydrateProjet($projet, $request);
            $projet->setDateCreation(new \DateTime());
            $projet->setStatutProjet('BROUILLON');

            $donnees = new DonneesBusiness();
            $this->hydrateDonnees($donnees, $request);
            $donnees->setProjet($projet);
            $projet->setDonneesBusiness($donnees);

            $errors = $validator->validate($projet);
            $erreursDonnees = $validator->validate($donnees);
            if (count($errors) > 0 || count($erreursDonnees) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                foreach ($erreursDonnees as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('front/projet/form.html.twig', ['projet' => $projet, 'fieldErrors' => $fieldErrors]);
            }

            $em->persist($projet);
            $em->persist($donnees);
            $em->flush();

            // Model 5 v0: rule-based quality/fraud screen (works with zero data)
            $quality = $qualityService->analyze($projet);

            // ML auto-learn: record the submission so models can learn from real platform data
            $logUser = $this->getUser();
            $mlEventLogger->log('project_submitted', 'projet', $projet->getId(),
                $logUser instanceof \App\Entity\User ? $logUser->getId() : null, [
                    'sector' => $projet->getSecteur(),
                    'country' => $projet->getPays(),
                    'stage' => $projet->getEtape(),
                    'team_size' => $donnees->getTailleEquipe(),
                    'founder_experience_years' => $donnees->getExperienceEquipe(),
                    'product_traction_users' => $donnees->getTraction(),
                    'funding_target' => $donnees->getObjectifFinancement(),
                    'expected_revenue' => $donnees->getRevenusAttendus(),
                    'estimated_costs' => $donnees->getCoutsEstimes(),
                    'quality_score' => $quality['score'],
                    'quality_flags' => $quality['flags'],
                ]);

            // Flag suspicious submissions for the admin moderation queue
            if ($quality['suspicious']) {
                $mlEventLogger->log('submission_flagged', 'projet', $projet->getId(),
                    $logUser instanceof \App\Entity\User ? $logUser->getId() : null, [
                        'quality_score' => $quality['score'],
                        'flags' => $quality['flags'],
                    ]);
            }

            // Send confirmation email
            try {
                /** @var \App\Entity\User $currentUser */
                $currentUser = $this->getUser();
                $email = (new TemplatedEmail())
                    ->from('mahdibenmariem1@gmail.com')
                    ->to($currentUser->getEmail())
                    ->subject('🚀 Projet créé : ' . $projet->getTitre())
                    ->htmlTemplate('emails/projet_created.html.twig')
                    ->context(['projet' => $projet]);
                $mailer->send($email);
            } catch (\Throwable $e) {
                // Don't block project creation if email fails
            }

            $this->addFlash('success', 'Projet créé avec succès !');
            return $this->redirectToRoute('app_projet_index');
        }

        return $this->render('front/projet/form.html.twig', ['projet' => null]);
    }

    #[Route('/{id}', name: 'app_projet_show', requirements: ['id' => '\d+'])]
    public function show(Projet $projet, GeminiService $ai): Response
    {
        return $this->render('front/projet/show.html.twig', [
            'projet' => $projet,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_projet_edit', methods: ['GET', 'POST'])]
    public function edit(Projet $projet, Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $this->hydrateProjet($projet, $request);
            $donnees = $projet->getDonneesBusiness();
            if ($donnees) {
                $this->hydrateDonnees($donnees, $request);
            }

            $errors = $validator->validate($projet);
            $erreursDonnees = $donnees ? $validator->validate($donnees) : [];
            if (count($errors) > 0 || count($erreursDonnees) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                foreach ($erreursDonnees as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('front/projet/form.html.twig', ['projet' => $projet, 'fieldErrors' => $fieldErrors]);
            }

            $em->flush();
            $this->addFlash('success', 'Projet modifié avec succès !');
            return $this->redirectToRoute('app_projet_show', ['id' => $projet->getId()]);
        }

        return $this->render('front/projet/form.html.twig', ['projet' => $projet]);
    }

    #[Route('/{id}/delete', name: 'app_projet_delete', methods: ['POST'])]
    public function delete(Projet $projet, EntityManagerInterface $em): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        $em->remove($projet);
        $em->flush();
        $this->addFlash('success', 'Projet supprimé.');
        return $this->redirectToRoute('app_projet_index');
    }

    // ==================== AI SCORING ====================

    #[Route('/{id}/evaluate-ai', name: 'app_projet_evaluate_ai', methods: ['POST'])]
    public function evaluateAi(Projet $projet, ProjetScoringService $scoring, EntityManagerInterface $em, MlEventLogger $mlEventLogger): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $result = $scoring->evaluateWithAi($projet);
        $em->flush();

        // ML auto-learn: record the score + its drivers for model calibration
        $db = $projet->getDonneesBusiness();
        $mlEventLogger->log('project_evaluated', 'projet', $projet->getId(),
            $projet->getUser()?->getId(), [
                'score_global' => $result['scoreGlobal'],
                'scores' => $result['scores'] ?? null,
                'sector' => $projet->getSecteur(),
                'country' => $projet->getPays(),
                'team_size' => $db?->getTailleEquipe(),
                'founder_experience_years' => $db?->getExperienceEquipe(),
                'product_traction_users' => $db?->getTraction(),
            ]);

        $this->addFlash('success', sprintf('Évaluation IA terminée ! Score global : %.1f/100', $result['scoreGlobal']));
        return $this->redirectToRoute('app_projet_show', ['id' => $projet->getId()]);
    }

    // ==================== BUSINESS PLAN ====================

    #[Route('/{id}/business-plan', name: 'app_projet_business_plan')]
    public function businessPlan(Projet $projet, ProjetBusinessPlanService $bpService): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $plan = $bpService->generate($projet);

        return $this->render('front/projet/business_plan.html.twig', [
            'projet' => $projet,
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}/business-plan/pdf', name: 'app_projet_business_plan_pdf')]
    public function businessPlanPdf(Projet $projet, ProjetBusinessPlanService $bpService): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $plan = $bpService->generate($projet);
        $pdf = $bpService->generatePdf($projet, $plan);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="business-plan-' . $projet->getId() . '.pdf"',
        ]);
    }

    // ==================== DASHBOARD ====================

    #[Route('/dashboard', name: 'app_projet_dashboard', priority: 10)]
    public function dashboard(ProjetRepository $repo): Response
    {
        $projets = $repo->findByUser($this->getUser());

        $stats = [
            'total' => count($projets),
            'brouillon' => 0,
            'soumis' => 0,
            'evalue' => 0,
            'score_moyen' => 0,
            'revenus_total' => 0,
            'couts_total' => 0,
            'marge_totale' => 0,
            'par_secteur' => [],
            'par_etape' => [],
            'scores' => [],
            'projets_top' => [],
        ];

        $totalScore = 0;
        $scored = 0;

        foreach ($projets as $p) {
            match ($p->getStatutProjet()) {
                'BROUILLON' => $stats['brouillon']++,
                'SOUMIS' => $stats['soumis']++,
                'EVALUE' => $stats['evalue']++,
                default => null,
            };

            $secteur = $p->getSecteur() ?? 'Non défini';
            $stats['par_secteur'][$secteur] = ($stats['par_secteur'][$secteur] ?? 0) + 1;

            $etape = $p->getEtape() ?? 'Non défini';
            $stats['par_etape'][$etape] = ($stats['par_etape'][$etape] ?? 0) + 1;

            if ($p->getScoreGlobal() > 0) {
                $totalScore += $p->getScoreGlobal();
                $scored++;
                $stats['scores'][] = [
                    'titre' => $p->getTitre(),
                    'score' => $p->getScoreGlobal(),
                ];
            }

            $db = $p->getDonneesBusiness();
            if ($db) {
                $stats['revenus_total'] += $db->getRevenusAttendus();
                $stats['couts_total'] += $db->getCoutsEstimes();
                $stats['marge_totale'] += $db->getMargeEstimee();
            }
        }

        $stats['score_moyen'] = $scored > 0 ? round($totalScore / $scored, 1) : 0;

        usort($stats['scores'], fn($a, $b) => $b['score'] <=> $a['score']);
        $stats['projets_top'] = array_slice($stats['scores'], 0, 5);

        return $this->render('front/projet/dashboard.html.twig', [
            'projets' => $projets,
            'stats' => $stats,
        ]);
    }

    // ==================== RECOMMENDATIONS ====================

    #[Route('/{id}/recommendations', name: 'app_projet_recommendations')]
    public function recommendations(Projet $projet, ProjetRecommendationService $recService): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $recommendations = $recService->getRecommendations($projet);
        $similarProjects = $recService->getSimilarProjects($projet);

        return $this->render('front/projet/recommendations.html.twig', [
            'projet' => $projet,
            'recommendations' => $recommendations,
            'similarProjects' => $similarProjects,
        ]);
    }

    // ==================== EXPORT ====================

    #[Route('/{id}/export/pdf', name: 'app_projet_export_pdf')]
    public function exportPdf(Projet $projet, ProjetExportService $exportService): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $pdf = $exportService->exportProjectPdf($projet);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="projet-' . $projet->getId() . '.pdf"',
        ]);
    }

    #[Route('/export/csv', name: 'app_projet_export_csv')]
    public function exportCsv(ProjetRepository $repo, ProjetExportService $exportService): Response
    {
        $projets = $repo->findByUser($this->getUser());
        $csv = $exportService->exportProjectCsv($projets);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="projets-export.csv"',
        ]);
    }

    // ==================== EXCHANGE RATE ====================

    #[Route('/{id}/exchange-rates', name: 'app_projet_exchange_rates')]
    public function exchangeRates(Projet $projet, ExchangeRateService $exchangeService, MacroRiskService $macroRisk): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $rates = $exchangeService->getRates();
        $db = $projet->getDonneesBusiness();

        // Model 4 v0 — real macro risk for the project's country (World Bank data, no training)
        $macro = $macroRisk->getRiskBreakdown($projet->getPays());

        $conversions = [];
        if ($db) {
            foreach (['EUR', 'USD', 'GBP'] as $currency) {
                $conversions[$currency] = [
                    'taille_marche' => $exchangeService->convert((float) $db->getTailleMarche(), $currency),
                    'couts_estimes' => $exchangeService->convert((float) $db->getCoutsEstimes(), $currency),
                    'revenus_attendus' => $exchangeService->convert((float) $db->getRevenusAttendus(), $currency),
                    'marge' => $exchangeService->convert((float) $db->getMargeEstimee(), $currency),
                ];
            }
        }

        return $this->render('front/projet/exchange_rates.html.twig', [
            'projet' => $projet,
            'rates' => $rates,
            'conversions' => $conversions,
            'macroRisk' => $macro,
        ]);
    }

    // ==================== NEWS ====================

    #[Route('/{id}/news', name: 'app_projet_news')]
    public function news(Projet $projet, NewsApiService $newsService): Response
    {
        if ($projet->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $news = $newsService->getNewsBySector($projet->getSecteur() ?? 'Technologie', 6);

        return $this->render('front/projet/news.html.twig', [
            'projet' => $projet,
            'news' => $news,
        ]);
    }

    // ==================== CHATBOT ====================

    #[Route('/chatbot', name: 'app_projet_chatbot', priority: 10)]
    public function chatbot(ProjetRepository $repo): Response
    {
        $projets = $repo->findByUser($this->getUser());

        return $this->render('front/projet/chatbot.html.twig', [
            'projets' => $projets,
        ]);
    }

    #[Route('/chatbot/ask', name: 'app_projet_chatbot_ask', priority: 10, methods: ['POST'])]
    public function chatbotAsk(Request $request, GeminiService $ai, ProjetRepository $repo, \App\Service\SimpleRateLimiter $rateLimiter): Response
    {
        // Rate-limit per user to protect the external LLM quota/cost.
        $uid = $this->getUser()?->getUserIdentifier() ?? (string) $request->getClientIp();
        if ($rateLimiter->tooManyAttempts('ai_chatbot_' . $uid, 20, 60)) {
            return $this->json(['error' => 'Trop de requêtes. Réessayez dans une minute.'], 429);
        }

        $data = json_decode($request->getContent(), true);
        $message = trim($data['message'] ?? '');

        if (empty($message)) {
            return $this->json(['error' => 'Message vide'], 400);
        }

    // Build full portfolio context
    // Choisis la méthode selon ce qui existe dans ProjetRepository
    if (method_exists($repo, 'findByUser')) {
        $projets = $repo->findByUser($this->getUser());
    } else {
        $projets = $repo->findBy(['user' => $this->getUser()]);
    }
    $context = "L'entrepreneur a " . count($projets) . " projet(s) au total.\n\n";

        foreach ($projets as $i => $p) {
            $num = $i + 1;
            $context .= "--- Projet {$num}: {$p->getTitre()} ---\n";
            $context .= "Secteur: " . ($p->getSecteur() ?? 'N/A') . "\n";
            $context .= "Étape: " . ($p->getEtape() ?? 'N/A') . "\n";
            $context .= "Statut: " . ($p->getStatutProjet() ?? 'N/A') . "\n";
            $context .= "Description: " . mb_substr($p->getDescription() ?? '', 0, 150) . "\n";

            if ($p->getScoreGlobal() > 0) {
                $context .= "Score IA: " . number_format($p->getScoreGlobal(), 1) . "/100\n";
            }

            $db = $p->getDonneesBusiness();
            if ($db) {
                $context .= sprintf(
                    "Coûts: %s DT | Revenus: %s DT | Marge: %s DT | Marché: %s | Risque: %s | Équipe: %s/10\n",
                    number_format((float) ($db->getCoutsEstimes() ?? 0), 0, ',', ' '),
                    number_format((float) ($db->getRevenusAttendus() ?? 0), 0, ',', ' '),
                    number_format((float) ($db->getMargeEstimee() ?? 0), 0, ',', ' '),
                    $db->getTailleMarche() ?? 'N/A',
                    $db->getNiveauRisque() ?? 'N/A',
                    $db->getForceEquipe() ?? 'N/A'
                );
            }
            $context .= "\n";
        }

        $prompt = <<<PROMPT
Tu es "LockedIn AI", un assistant expert en entrepreneuriat, gestion de projets et business en Tunisie et en Afrique du Nord.

Voici le portefeuille complet de projets de l'entrepreneur:
{$context}

Règles:
- Réponds TOUJOURS en français, de manière concise, structurée et pratique
- Tu connais tous les projets de l'utilisateur et peux les comparer, analyser, conseiller
- Donne des conseils adaptés au marché tunisien et nord-africain
- Tu peux aider sur: stratégie, finances, marketing, risques, comparaison de projets, priorisation, tendances sectorielles, financement, KPIs, etc.
- Si l'utilisateur demande quelque chose hors sujet de l'entrepreneuriat ou la gestion de projets, ramène poliment la conversation

Question de l'entrepreneur: {$message}
PROMPT;

        $response = $ai->generate($prompt, 0.7);

        if ($response === null) {
            return $this->json(['error' => 'Service IA indisponible'], 503);
        }

        return $this->json(['response' => $response]);
    }

    // ==================== PRIVATE HELPERS ====================

    private function hydrateProjet(Projet $p, Request $r): void
    {
        $p->setTitre($r->request->get('titre'));
        $p->setDescription($r->request->get('description'));
        $p->setSecteur($r->request->get('secteur'));
        $p->setEtape($r->request->get('etape'));
        $p->setStatut($r->request->get('statut'));
        $p->setPays($r->request->get('pays') ?: null);
    }

    private function extractNumeric(string $raw): float
    {
        $raw = trim($raw);
        // Collapse digit-space-digit pairs (space-separated thousands like "500 000")
        $noSpaces = (string) preg_replace('/(\d)\s+(\d)/', '$1$2', $raw);
        // Apply again in case of triple-space groups ("1 000 000")
        $noSpaces = (string) preg_replace('/(\d)\s+(\d)/', '$1$2', $noSpaces);
        // Remove thousand-separator commas: "1,000,000" → "1000000"
        if (preg_match('/\d,\d{3}/', $noSpaces)) {
            $noSpaces = str_replace(',', '', $noSpaces);
        }
        // Extract first number (integer or decimal)
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $noSpaces, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return 0.0;
    }

    private function hydrateDonnees(DonneesBusiness $d, Request $r): void
    {
        // tailleMarche is a string field — store as-is so descriptions are preserved
        $d->setTailleMarche((string) $r->request->get('taille_marche', ''));
        $d->setModeleRevenu($r->request->get('modele_revenu'));
        $d->setCoutsEstimes($this->extractNumeric((string) $r->request->get('couts_estimes', '0')));
        $d->setRevenusAttendus($this->extractNumeric((string) $r->request->get('revenus_attendus', '0')));
        $d->setNiveauRisque($r->request->get('niveau_risque'));
        $force = (int) $this->extractNumeric((string) $r->request->get('force_equipe', '5'));
        $d->setForceEquipe(max(1, min(10, $force ?: 5)));

        // Model 1 scoring inputs (optional, funding-independent). Empty => null (not collected yet).
        $taille = (int) $this->extractNumeric((string) $r->request->get('taille_equipe', ''));
        $d->setTailleEquipe($taille > 0 ? $taille : null);
        $objectif = $this->extractNumeric((string) $r->request->get('objectif_financement', ''));
        $d->setObjectifFinancement($objectif > 0 ? $objectif : null);
        $traction = (int) $this->extractNumeric((string) $r->request->get('traction', ''));
        $d->setTraction($traction > 0 ? $traction : null);
        $exp = (int) $this->extractNumeric((string) $r->request->get('experience_equipe', ''));
        $d->setExperienceEquipe($exp > 0 ? $exp : null);
    }
}
