<?php

namespace App\Controller;

use App\Entity\CoursComment;
use App\Entity\Progression;
use App\Entity\User;
use App\Repository\BadgeRepository;
use App\Repository\CoursCommentRepository;
use App\Repository\CoursRepository;
use App\Repository\ProgressionRepository;
use App\Service\AvisRatingService;
use App\Service\CoursQuizService;
use App\Service\ProfanityCheckService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Nucleos\DompdfBundle\Wrapper\DompdfWrapperInterface;

#[Route('/apprentissage')]
#[IsGranted('ROLE_USER')]
class ApprentissageController extends AbstractController
{
    #[Route('/cours', name: 'app_apprentissage_cours')]
    public function cours(CoursRepository $repo, Request $request): Response
    {
        $search = $request->query->get('q', '');
        $cours = $search ? $repo->findBySearch($search)->getQuery()->getResult() : $repo->findBy(['actif' => true]);
        return $this->render('front/apprentissage/cours.html.twig', ['cours' => $cours, 'search' => $search]);
    }

    #[Route('/cours/{id}', name: 'app_apprentissage_cours_show', requirements: ['id' => '\d+'])]
    public function showCours(\App\Entity\Cours $cours, ProgressionRepository $progRepo, CoursCommentRepository $commentRepo): Response
    {
        $progression = $progRepo->findOneBy(['user' => $this->getUser(), 'cours' => $cours]);
        $comments = $commentRepo->findByCours($cours);

        return $this->render('front/apprentissage/cours_show.html.twig', [
            'cours' => $cours,
            'progression' => $progression,
            'comments' => $comments,
        ]);
    }

    #[Route('/cours/{id}/document', name: 'app_apprentissage_cours_document', requirements: ['id' => '\d+'])]
    public function serveDocument(\App\Entity\Cours $cours): Response
    {
        if (!$cours->getDocumentPath()) {
            throw new NotFoundHttpException('documentPath est null en base.');
        }

        $uploadDir = $this->getParameter('cours_documents_directory');
        $filePath = $uploadDir . '/' . $cours->getDocumentPath();

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $disposition = in_array($ext, ['pdf', 'txt'])
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        return $this->file($filePath, $cours->getTitre() . '.' . $ext, $disposition);
    }

    #[Route('/cours/{id}/enroll', name: 'app_apprentissage_enroll', methods: ['POST'])]
    public function enroll(\App\Entity\Cours $cours, EntityManagerInterface $em, ProgressionRepository $repo): Response
    {
        $existing = $repo->findOneBy(['user' => $this->getUser(), 'cours' => $cours]);
        if (!$existing) {
            /** @var User $currentUser */
            $currentUser = $this->getUser();
            $prog = new Progression();
            $prog->setUser($currentUser);
            $prog->setCours($cours);
            $prog->setPourcentage((string) 0);
            $prog->setPointsXp(0);
            $prog->setNiveau(1);
            $prog->setEtat('EN_COURS');
            $prog->setDateDebut(new \DateTime());
            $em->persist($prog);
            $em->flush();
            $this->addFlash('success', 'Inscription au cours réussie !');
        }
        return $this->redirectToRoute('app_apprentissage_cours_show', ['id' => $cours->getId()]);
    }

    #[Route('/cours/{id}/progress', name: 'app_apprentissage_progress_update', methods: ['POST'])]
    public function updateProgress(
        \App\Entity\Cours $cours,
        Request $request,
        EntityManagerInterface $em,
        ProgressionRepository $repo,
        BadgeRepository $badgeRepo
    ): Response
    {
        $prog = $repo->findOneBy(['user' => $this->getUser(), 'cours' => $cours]);
        if ($prog) {
            $newPct = min(100, (int) $request->request->get('pourcentage', $prog->getPourcentage()));
            $prog->setPourcentage($newPct);
            if ($newPct >= 100) {
                $prog->setEtat('COMPLETE');
                $prog->setDateObtention(new \DateTime());
                $prog->setPointsXp($cours->getPointsXp());

                $user = $em->getRepository(\App\Entity\User::class)->find($this->getUser()->getUserIdentifier());
                $user->setTotalXp($user->getTotalXp() + $cours->getPointsXp());
                $em->persist($user);
            }
            $em->flush();

            $this->checkAndAwardBadges($em, $repo, $badgeRepo);
            $this->addFlash('success', 'Progression mise à jour !');
        }
        return $this->redirectToRoute('app_apprentissage_cours_show', ['id' => $cours->getId()]);
    }

    private function checkAndAwardBadges(
        EntityManagerInterface $em,
        ProgressionRepository $progRepo,
        BadgeRepository $badgeRepo
    ): void
    {
        // Recharger l'utilisateur depuis la base pour éviter le cache Doctrine
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $user = $em->getRepository(\App\Entity\User::class)->find($currentUser->getId());

        $progressions = $progRepo->findByUser($user);

        $totalXp = $user->getTotalXp();
        $coursTermines = 0;
        foreach ($progressions as $p) {
            if ($p->getEtat() === 'COMPLETE') $coursTermines++;
        }

        foreach ($badgeRepo->findActifs() as $badge) {
            if ($user->hasBadge($badge)) {
                continue;
            }

            $unlocked = false;

            if ($badge->getPointsRequis() > 0 && $totalXp >= $badge->getPointsRequis()) {
                $unlocked = true;
            }
            if ($badge->getCoursRequis() > 0 && $coursTermines >= $badge->getCoursRequis()) {
                $unlocked = true;
            }

            if ($unlocked) {
                $user->addBadge($badge);
                $this->addFlash('success', '🏆 Badge débloqué : ' . $badge->getNom() . ' !');
            }
        }

        $em->persist($user);
        $em->flush();
    }

    #[Route('/cours/{id}/comment', name: 'app_apprentissage_comment', methods: ['POST'])]
    public function comment(
        \App\Entity\Cours $cours,
        Request $request,
        EntityManagerInterface $em,
        AvisRatingService $avisRatingService,
        ProfanityCheckService $profanityCheckService
    ): Response
    {
        $contenu = trim((string) $request->request->get('contenu', ''));
        if ($contenu === '') {
            $this->addFlash('danger', 'Le contenu de votre avis est requis.');

            return $this->redirectToRoute('app_apprentissage_cours_show', ['id' => $cours->getId()]);
        }

        try {
            if ($profanityCheckService->containsProfanity($contenu)) {
                $this->addFlash('danger', 'Votre avis contient des mots inappropriés. Merci de le reformuler avant publication.');

                return $this->redirectToRoute('app_apprentissage_cours_show', ['id' => $cours->getId()]);
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Impossible de vérifier le contenu de votre avis pour le moment. Veuillez réessayer dans quelques instants.');

            return $this->redirectToRoute('app_apprentissage_cours_show', ['id' => $cours->getId()]);
        }

        $comment = new CoursComment();
        $comment->setCours($cours);
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $comment->setUser($currentUser);
        $comment->setContenu($contenu);
        $comment->setRating((string) $avisRatingService->rateAvis($contenu));

        $em->persist($comment);
        $em->flush();
        $this->addFlash('success', 'Avis ajouté !');
        return $this->redirectToRoute('app_apprentissage_cours_show', ['id' => $cours->getId()]);
    }

    #[Route('/cours/{id}/quiz', name: 'app_apprentissage_cours_quiz', methods: ['GET'])]
    public function quiz(
        \App\Entity\Cours $cours,
        ProgressionRepository $progressionRepository,
        CoursQuizService $coursQuizService
    ): JsonResponse
    {
        $progression = $progressionRepository->findOneBy([
            'user' => $this->getUser(),
            'cours' => $cours,
        ]);

        if (!$progression || (int) $progression->getPourcentage() < 100) {
            return $this->json([
                'message' => 'Quiz disponible uniquement après 100% de progression.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'course' => $cours->getTitre(),
            'questions' => $coursQuizService->generateQuizForCours($cours),
        ]);
    }

    #[Route('/progression', name: 'app_apprentissage_progression')]
    public function progression(ProgressionRepository $repo): Response
    {
        $progressions = $repo->findByUser($this->getUser());

        $totalXp = 0;
        $totalPct = 0;
        $coursTermines = 0;
        $coursEnCours = 0;

        foreach ($progressions as $p) {
            $totalXp += $p->getPointsXp();
            $totalPct += $p->getPourcentage();
            if ($p->getEtat() === 'COMPLETE') $coursTermines++;
            if ($p->getEtat() === 'EN_COURS') $coursEnCours++;
        }

        $totalCours = count($progressions);
        $moyennePct = $totalCours > 0 ? round($totalPct / $totalCours) : 0;

        return $this->render('front/apprentissage/progression.html.twig', [
            'progressions'  => $progressions,
            'totalCours'    => $totalCours,
            'coursTermines' => $coursTermines,
            'coursEnCours'  => $coursEnCours,
            'totalXp'       => $totalXp,
            'moyennePct'    => $moyennePct,
        ]);
    }

    #[Route('/progression/export-pdf', name: 'app_apprentissage_progression_pdf')]
    public function exportProgressionPdf(
        ProgressionRepository $repo,
        DompdfWrapperInterface $wrapper
    ): Response
    {
        $progressions = $repo->findByUser($this->getUser());
        $completed = array_filter($progressions, fn($p) => $p->getEtat() === 'COMPLETE');

        /** @var User $user */
        $user = $this->getUser();
        $date = (new \DateTime())->format('d/m/Y');

        $html = '
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: DejaVu Sans, sans-serif; color: #333; padding: 40px; }
                .header { text-align: center; margin-bottom: 40px; border-bottom: 3px solid #6c3fc5; padding-bottom: 20px; }
                .logo { font-size: 32px; font-weight: bold; color: #6c3fc5; }
                .subtitle { color: #888; font-size: 14px; margin-top: 5px; }
                .congrats { background: #f0faf0; border-left: 5px solid #28a745; padding: 20px; border-radius: 8px; margin: 30px 0; text-align: center; }
                .congrats h2 { color: #28a745; margin: 0 0 10px 0; font-size: 22px; }
                .congrats p { color: #555; margin: 0; font-size: 14px; }
                .course { background: #fafafa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px 20px; margin-bottom: 14px; }
                .course-title { font-size: 16px; font-weight: bold; color: #333; margin-bottom: 6px; }
                .course-meta { font-size: 12px; color: #888; }
                .badge { display: inline-block; background: #28a745; color: white; padding: 3px 10px; border-radius: 20px; font-size: 11px; float: right; }
                .xp { color: #6c3fc5; font-weight: bold; }
                .footer { text-align: center; margin-top: 50px; font-size: 11px; color: #aaa; border-top: 1px solid #eee; padding-top: 15px; }
                .empty { text-align: center; color: #888; padding: 40px; font-size: 16px; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="logo">Najahni</div>
                <div class="subtitle">Attestation de progression — ' . $date . '</div>
            </div>';

        if (count($completed) > 0) {
            $html .= '
            <div class="congrats">
                <h2>Felicitations ' . htmlspecialchars($user->getFirstname()) . ' !</h2>
                <p>Vous avez complete ces cours avec succes. Continuez sur cette lancee !</p>
            </div>';

            foreach ($completed as $p) {
                $html .= '
                <div class="course">
                    <span class="badge">COMPLETE</span>
                    <div class="course-title">' . htmlspecialchars($p->getCours()->getTitre()) . '</div>
                    <div class="course-meta">
                        Categorie : ' . htmlspecialchars($p->getCours()->getCategorie()) . ' &nbsp;|&nbsp;
                        <span class="xp">+' . $p->getPointsXp() . ' XP</span> &nbsp;|&nbsp;
                        Termine le : ' . ($p->getDateObtention() ? $p->getDateObtention()->format('d/m/Y') : 'N/A') . '
                    </div>
                </div>';
            }
        } else {
            $html .= '<div class="empty">Aucun cours complete pour le moment.</div>';
        }

        $html .= '
            <div class="footer">
                Genere par Najahni &bull; ' . $date . ' &bull; ' . htmlspecialchars($user->getEmail()) . '
            </div>
        </body>
        </html>';

        return $wrapper->getStreamResponse($html, 'progression-' . date('Y-m-d') . '.pdf', [
            'attachment' => true,
        ]);
    }
 

    #[Route('/badges', name: 'app_apprentissage_badges')]
    public function badges(BadgeRepository $repo): Response
    {
        return $this->render('front/apprentissage/badges.html.twig', [
            'badges'     => $repo->findActifs(),
            'userBadges' => $this->getUser() instanceof User ? $this->getUser()->getBadges() : [],
        ]);
    }
}