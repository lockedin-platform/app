<?php

namespace App\Controller;

use App\Entity\MentorAvailability;
use App\Entity\MentorshipRequest;
use App\Entity\MentorshipSession;
use App\Entity\User;
use App\Repository\MentorAvailabilityRepository;
use App\Repository\MentorshipRequestRepository;
use App\Repository\MentorshipSessionRepository;
use App\Repository\ProjetRepository;
use App\Repository\UserRepository;
use App\Service\MentoratMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/mentorat')]
#[IsGranted('ROLE_USER')]
class MentoratController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MentoratMatchingService $matchingService,
        private ProjetRepository $projetRepository,
    ) {}

    // ──────────────────────────────────────────────
    //  REQUESTS TAB
    // ──────────────────────────────────────────────

    #[Route('/requests', name: 'app_mentorat_requests')]
    public function requests(MentorshipRequestRepository $repo): Response
    {
        $user = $this->getUser();
        $sentRequests = $repo->findBy(['entrepreneur' => $user], ['createdAt' => 'DESC']);
        $receivedRequests = $repo->findBy(['mentor' => $user], ['createdAt' => 'DESC']);

        return $this->render('front/mentorat/requests.html.twig', [
            'sentRequests' => $sentRequests,
            'receivedRequests' => $receivedRequests,
        ]);
    }

    #[Route('/mentors', name: 'app_mentorat_mentors')]
    public function mentors(MentorAvailabilityRepository $availRepo): Response
    {
        // Entrepreneur sees all mentor availability slots (future dates)
        $availabilities = $availRepo->createQueryBuilder('a')
            ->join('a.mentor', 'm')
            ->where('a.date >= :today')
            ->andWhere('m.isBanned = false')
            ->andWhere('m.isActive = true')
            ->setParameter('today', new \DateTime('today'))
            ->orderBy('a.date', 'ASC')
            ->addOrderBy('a.startTime', 'ASC')
            ->getQuery()->getResult();

        $projets = $this->projetRepository->findByUser($this->getUser());

        return $this->render('front/mentorat/mentors.html.twig', [
            'availabilities' => $availabilities,
            'projets' => $projets,
        ]);
    }

    #[Route('/request/new/{mentorId}', name: 'app_mentorat_request_new', methods: ['GET', 'POST'])]
    public function newRequest(int $mentorId, Request $request, UserRepository $userRepo): Response
    {
        $mentor = $userRepo->find($mentorId);
        if (!$mentor || $mentor->getRole() !== 'MENTOR') {
            throw $this->createNotFoundException('Mentor non trouvé');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mentorat_request', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $req = new MentorshipRequest();
            /** @var User $currentUser */
            $currentUser = $this->getUser();
            $req->setEntrepreneur($currentUser);
            $req->setMentor($mentor);
            $req->setDate(new \DateTime($request->request->get('date')));
            $req->setTime($request->request->get('time'));
            $req->setMotivation($request->request->get('motivation'));
            $req->setGoals($request->request->get('goals'));

            $projectSecteur = null;
            $projectId = $request->request->get('project_id');
            if ($projectId) {
                $project = $this->em->getRepository(\App\Entity\Projet::class)->find($projectId);
                if ($project) {
                    $req->setProject($project);
                    $projectSecteur = $project->getSecteur();
                }
            }

            // Calculate matching score and potentially auto-accept
            $this->matchingService->processRequest($req, $projectSecteur);

            $this->em->persist($req);

            // If auto-accepted, create a session immediately
            if ($req->getStatus() === MentorshipRequest::STATUS_AUTO_ACCEPTED) {
                $session = new MentorshipSession();
                $session->setMentorshipRequest($req);
                $session->setScheduledAt($req->getDate());
                $session->setDurationMinutes(60);
                $session->setStatus(MentorshipSession::STATUS_SCHEDULED);
                $this->em->persist($session);
                $this->addFlash('success', 'Demande auto-acceptée (match ' . $req->getMatchScore() . '%) ! Session créée.');
            } else {
                $this->addFlash('success', 'Demande envoyée (match ' . $req->getMatchScore() . '%). En attente de réponse du mentor.');
            }

            $this->em->flush();
            return $this->redirectToRoute('app_mentorat_requests');
        }

        // Pre-fill date/time from query string (passed from availability card)
        $prefillDate = $request->query->get('date', '');
        $prefillTime = $request->query->get('time', '');

        $projets = $this->projetRepository->findByUser($this->getUser());
        return $this->render('front/mentorat/request_form.html.twig', [
            'mentor' => $mentor,
            'projets' => $projets,
            'prefillDate' => $prefillDate,
            'prefillTime' => $prefillTime,
        ]);
    }

    #[Route('/requests/{id}/edit', name: 'app_mentorat_request_edit', methods: ['GET', 'POST'])]
    public function editRequest(MentorshipRequest $req, Request $request): Response
    {
        if ($req->getEntrepreneur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!in_array($req->getStatus(), [MentorshipRequest::STATUS_PENDING, MentorshipRequest::STATUS_CANCELLED])) {
            $this->addFlash('warning', 'Cette demande ne peut plus être modifiée.');
            return $this->redirectToRoute('app_mentorat_requests');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mentorat_request_edit_' . $req->getId(), $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $req->setDate(new \DateTime($request->request->get('date')));
            $req->setTime($request->request->get('time'));
            $req->setMotivation($request->request->get('motivation'));
            $req->setGoals($request->request->get('goals'));
            $req->setStatus(MentorshipRequest::STATUS_PENDING);

            $projectSecteur = null;
            $projectId = $request->request->get('project_id');
            if ($projectId) {
                $project = $this->em->getRepository(\App\Entity\Projet::class)->find($projectId);
                $req->setProject($project);
                $projectSecteur = $project?->getSecteur();
            } else {
                $req->setProject(null);
            }

            $this->matchingService->processRequest($req, $projectSecteur);
            if ($req->getStatus() === MentorshipRequest::STATUS_AUTO_ACCEPTED) {
                $session = new MentorshipSession();
                $session->setMentorshipRequest($req);
                $session->setScheduledAt($req->getDate());
                $session->setDurationMinutes(60);
                $session->setStatus(MentorshipSession::STATUS_SCHEDULED);
                $this->em->persist($session);
            }

            $this->em->flush();
            $this->addFlash('success', 'Demande modifiée.');
            return $this->redirectToRoute('app_mentorat_requests');
        }

        $projets = $this->projetRepository->findByUser($this->getUser());
        return $this->render('front/mentorat/request_edit.html.twig', [
            'req' => $req,
            'projets' => $projets,
        ]);
    }

    #[Route('/requests/{id}/cancel', name: 'app_mentorat_request_cancel', methods: ['POST'])]
    public function cancelRequest(MentorshipRequest $req, Request $request): Response
    {
        if ($req->getEntrepreneur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mentorat_cancel_' . $req->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $req->setStatus(MentorshipRequest::STATUS_CANCELLED);
        $this->em->flush();
        $this->addFlash('success', 'Demande annulée.');
        return $this->redirectToRoute('app_mentorat_requests');
    }

    #[Route('/requests/{id}/delete', name: 'app_mentorat_request_delete', methods: ['POST'])]
    public function deleteRequest(MentorshipRequest $req, Request $request): Response
    {
        if ($req->getEntrepreneur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mentorat_delete_req_' . $req->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->em->remove($req);
        $this->em->flush();
        $this->addFlash('success', 'Demande supprimée.');
        return $this->redirectToRoute('app_mentorat_requests');
    }

    #[Route('/requests/{id}/respond', name: 'app_mentorat_request_respond', methods: ['POST'])]
    public function respondRequest(MentorshipRequest $req, Request $request): Response
    {
        if ($req->getMentor() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mentorat_respond_' . $req->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $action = $request->request->get('action');
        if ($action === 'accept') {
            $req->setStatus(MentorshipRequest::STATUS_ACCEPTED);
            $session = new MentorshipSession();
            $session->setMentorshipRequest($req);
            $session->setScheduledAt($req->getDate());
            $session->setDurationMinutes(60);
            $session->setStatus(MentorshipSession::STATUS_SCHEDULED);
            $this->em->persist($session);
        } else {
            $req->setStatus(MentorshipRequest::STATUS_REJECTED);
        }

        $this->em->flush();
        $this->addFlash('success', 'Demande ' . ($action === 'accept' ? 'acceptée' : 'refusée') . '.');
        return $this->redirectToRoute('app_mentorat_requests');
    }

    // ──────────────────────────────────────────────
    //  SESSIONS TAB
    // ──────────────────────────────────────────────

    #[Route('/sessions', name: 'app_mentorat_sessions')]
    public function sessions(MentorshipSessionRepository $repo): Response
    {
        $sessions = $repo->findByUser($this->getUser());
        return $this->render('front/mentorat/sessions.html.twig', ['sessions' => $sessions]);
    }

    #[Route('/sessions/new', name: 'app_mentorat_session_new', methods: ['GET', 'POST'])]
    public function newSession(Request $request, MentorshipRequestRepository $reqRepo): Response
    {
        $user = $this->getUser();
        /** @var User $user */
        if ($user->getRole() !== 'MENTOR') {
            throw $this->createAccessDeniedException('Seul un mentor peut créer une session.');
        }
        $acceptedRequests = $reqRepo->createQueryBuilder('r')
            ->where('(r.mentor = :u OR r.entrepreneur = :u)')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('u', $user)
            ->setParameter('statuses', [MentorshipRequest::STATUS_ACCEPTED, MentorshipRequest::STATUS_AUTO_ACCEPTED])
            ->orderBy('r.date', 'DESC')
            ->getQuery()->getResult();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mentorat_session_new', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $reqId = $request->request->get('request_id');
            $mentorshipReq = $reqRepo->find($reqId);
            if (!$mentorshipReq || ($mentorshipReq->getMentor() !== $user && $mentorshipReq->getEntrepreneur() !== $user)) {
                throw $this->createAccessDeniedException();
            }

            $session = new MentorshipSession();
            $session->setMentorshipRequest($mentorshipReq);
            $session->setScheduledAt(new \DateTime($request->request->get('scheduled_at')));
            $session->setDurationMinutes((int) $request->request->get('duration', 60));
            $session->setMeetingLink($request->request->get('meeting_link'));
            $session->setStatus(MentorshipSession::STATUS_SCHEDULED);

            $this->em->persist($session);
            $this->em->flush();
            $this->addFlash('success', 'Session créée.');
            return $this->redirectToRoute('app_mentorat_sessions');
        }

        return $this->render('front/mentorat/session_form.html.twig', [
            'acceptedRequests' => $acceptedRequests,
            'session' => null,
        ]);
    }

    #[Route('/sessions/{id}/edit', name: 'app_mentorat_session_edit', methods: ['GET', 'POST'])]
    public function editSession(MentorshipSession $session, Request $request, MentorshipRequestRepository $reqRepo): Response
    {
        $user = $this->getUser();
        $mentorshipReq = $session->getMentorshipRequest();
        if (!$mentorshipReq || $mentorshipReq->getMentor() !== $user) {
            throw $this->createAccessDeniedException('Seul le mentor peut modifier cette session.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mentorat_session_edit_' . $session->getId(), $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $session->setScheduledAt(new \DateTime($request->request->get('scheduled_at')));
            $session->setDurationMinutes((int) $request->request->get('duration', 60));
            $session->setMeetingLink($request->request->get('meeting_link'));
            $session->setStatus($request->request->get('status', MentorshipSession::STATUS_SCHEDULED));

            $this->em->flush();
            $this->addFlash('success', 'Session modifiée.');
            return $this->redirectToRoute('app_mentorat_sessions');
        }

        $acceptedRequests = $reqRepo->createQueryBuilder('r')
            ->where('(r.mentor = :u OR r.entrepreneur = :u)')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('u', $user)
            ->setParameter('statuses', [MentorshipRequest::STATUS_ACCEPTED, MentorshipRequest::STATUS_AUTO_ACCEPTED])
            ->orderBy('r.date', 'DESC')
            ->getQuery()->getResult();

        return $this->render('front/mentorat/session_form.html.twig', [
            'acceptedRequests' => $acceptedRequests,
            'session' => $session,
        ]);
    }

    #[Route('/sessions/{id}/delete', name: 'app_mentorat_session_delete', methods: ['POST'])]
    public function deleteSession(MentorshipSession $session, Request $request): Response
    {
        $user = $this->getUser();
        $mentorshipReq = $session->getMentorshipRequest();
        if (!$mentorshipReq || $mentorshipReq->getMentor() !== $user) {
            throw $this->createAccessDeniedException('Seul le mentor peut supprimer cette session.');
        }
        if (!$this->isCsrfTokenValid('mentorat_session_del_' . $session->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->em->remove($session);
        $this->em->flush();
        $this->addFlash('success', 'Session supprimée.');
        return $this->redirectToRoute('app_mentorat_sessions');
    }

    #[Route('/sessions/{id}/feedback', name: 'app_mentorat_session_feedback', methods: ['POST'])]
    public function sessionFeedback(MentorshipSession $session, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mentorat_feedback_' . $session->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $user = $this->getUser();
        $mentorshipReq = $session->getMentorshipRequest();

        if ($mentorshipReq->getMentor() === $user) {
            $session->setMentorFeedback($request->request->get('feedback'));
            $session->setMentorRating((int) $request->request->get('rating'));
        } elseif ($mentorshipReq->getEntrepreneur() === $user) {
            $session->setEntrepreneurFeedback($request->request->get('feedback'));
            $session->setEntrepreneurRating((int) $request->request->get('rating'));
        }

        $this->em->flush();
        $this->addFlash('success', 'Feedback envoyé !');
        return $this->redirectToRoute('app_mentorat_sessions');
    }

    // ──────────────────────────────────────────────
    //  EXPORT — PDF & EXCEL
    // ──────────────────────────────────────────────

    #[Route('/sessions/export/pdf', name: 'app_mentorat_sessions_export_pdf')]
    public function exportSessionsPdf(MentorshipSessionRepository $repo): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $sessions = $repo->findByUser($currentUser);
        $userRole = $currentUser->getRole();
        $html = $this->renderView('front/mentorat/sessions_pdf.html.twig', ['sessions' => $sessions, 'userRole' => $userRole]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="sessions_mentorat.pdf"',
        ]);
    }

    #[Route('/sessions/export/excel', name: 'app_mentorat_sessions_export_excel')]
    public function exportSessionsExcel(MentorshipSessionRepository $repo): StreamedResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $sessions = $repo->findByUser($currentUser);
        $userRole = $currentUser->getRole();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sessions Mentorat');

        $otherLabel = $userRole === 'MENTOR' ? 'Entrepreneur' : 'Mentor';
        $headers = ['#', $otherLabel, 'Date', 'Durée (min)', 'Statut', 'Lien', 'Mon feedback', 'Ma note'];
        foreach ($headers as $col => $h) {
            $sheet->setCellValue([$col + 1, 1], $h);
            $sheet->getStyle([$col + 1, 1])->getFont()->setBold(true);
        }

        $row = 2;
        foreach ($sessions as $s) {
            $r = $s->getMentorshipRequest();
            $otherName = '-';
            if ($r) {
                $otherName = $userRole === 'MENTOR'
                    ? $r->getEntrepreneur()->getFirstname() . ' ' . $r->getEntrepreneur()->getLastname()
                    : $r->getMentor()->getFirstname() . ' ' . $r->getMentor()->getLastname();
            }
            $sheet->setCellValue([1, $row], $s->getId());
            $sheet->setCellValue([2, $row], $otherName);
            $sheet->setCellValue([3, $row], $s->getScheduledAt()?->format('d/m/Y H:i') ?? '-');
            $sheet->setCellValue([4, $row], $s->getDurationMinutes() ?? '-');
            $sheet->setCellValue([5, $row], $s->getStatus());
            $sheet->setCellValue([6, $row], $s->getMeetingLink() ?? '-');
            if ($userRole === 'MENTOR') {
                $sheet->setCellValue([7, $row], $s->getMentorFeedback() ?? '-');
                $sheet->setCellValue([8, $row], $s->getMentorRating() ? $s->getMentorRating() . '/5' : '-');
            } else {
                $sheet->setCellValue([7, $row], $s->getEntrepreneurFeedback() ?? '-');
                $sheet->setCellValue([8, $row], $s->getEntrepreneurRating() ? $s->getEntrepreneurRating() . '/5' : '-');
            }
            $row++;
        }

        foreach (range(1, 8) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="sessions_mentorat.xlsx"');
        return $response;
    }

    // ──────────────────────────────────────────────
    //  AVAILABILITY TAB
    // ──────────────────────────────────────────────

    #[Route('/availability', name: 'app_mentorat_availability')]
    public function availability(MentorAvailabilityRepository $repo): Response
    {
        $availabilities = $repo->findByMentor($this->getUser());
        return $this->render('front/mentorat/availability.html.twig', ['availabilities' => $availabilities]);
    }

    #[Route('/availability/new', name: 'app_mentorat_availability_new', methods: ['POST'])]
    public function newAvailability(Request $request, ValidatorInterface $validator): Response
    {
        if (!$this->isCsrfTokenValid('mentorat_availability', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $avail = new MentorAvailability();
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $avail->setMentor($currentUser);
        $avail->setDate(new \DateTime($request->request->get('date')));
        $avail->setStartTime(new \DateTime($request->request->get('start_time')));
        $avail->setEndTime(new \DateTime($request->request->get('end_time')));

        $errors = $validator->validate($avail);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
            return $this->redirectToRoute('app_mentorat_availability');
        }

        $this->em->persist($avail);
        $this->em->flush();
        $this->addFlash('success', 'Disponibilité ajoutée !');
        return $this->redirectToRoute('app_mentorat_availability');
    }

    #[Route('/availability/{id}/edit', name: 'app_mentorat_availability_edit', methods: ['POST'])]
    public function editAvailability(MentorAvailability $avail, Request $request, ValidatorInterface $validator): Response
    {
        if ($avail->getMentor() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mentorat_avail_edit_' . $avail->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $avail->setDate(new \DateTime($request->request->get('date')));
        $avail->setStartTime(new \DateTime($request->request->get('start_time')));
        $avail->setEndTime(new \DateTime($request->request->get('end_time')));

        $errors = $validator->validate($avail);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
            return $this->redirectToRoute('app_mentorat_availability');
        }

        $this->em->flush();
        $this->addFlash('success', 'Disponibilité modifiée.');
        return $this->redirectToRoute('app_mentorat_availability');
    }

    #[Route('/availability/{id}/delete', name: 'app_mentorat_availability_delete', methods: ['POST'])]
    public function deleteAvailability(MentorAvailability $avail, Request $request): Response
    {
        if ($avail->getMentor() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('mentorat_avail_del_' . $avail->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->em->remove($avail);
        $this->em->flush();
        $this->addFlash('success', 'Disponibilité supprimée.');
        return $this->redirectToRoute('app_mentorat_availability');
    }

    // ──────────────────────────────────────────────
    //  CALENDAR — Entrepreneur view
    // ──────────────────────────────────────────────

    #[Route('/calendar', name: 'app_mentorat_calendar')]
    public function calendar(Request $request, MentorAvailabilityRepository $availRepo): Response
    {
        $year  = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        if ($month < 1)  { $month = 12; $year--; }
        if ($month > 12) { $month = 1;  $year++; }

        $firstDay = new \DateTime("$year-$month-01");
        $lastDay  = (clone $firstDay)->modify('last day of this month');

        // Fetch all mentor availabilities for this month
        $availabilities = $availRepo->createQueryBuilder('a')
            ->join('a.mentor', 'm')
            ->where('a.date BETWEEN :start AND :end')
            ->andWhere('m.isBanned = false')
            ->andWhere('m.isActive = true')
            ->setParameter('start', $firstDay)
            ->setParameter('end', $lastDay)
            ->orderBy('a.date', 'ASC')
            ->addOrderBy('a.startTime', 'ASC')
            ->getQuery()->getResult();

        // Group availabilities by date string (Y-m-d)
        $availByDate = [];
        foreach ($availabilities as $a) {
            $key = $a->getDate()->format('Y-m-d');
            $availByDate[$key][] = $a;
        }

        return $this->render('front/mentorat/calendar.html.twig', [
            'year'        => $year,
            'month'       => $month,
            'firstDay'    => $firstDay,
            'lastDay'     => $lastDay,
            'availByDate' => $availByDate,
        ]);
    }

    // ──────────────────────────────────────────────
    //  MY CALENDAR — Mentor's own availability view
    // ──────────────────────────────────────────────

    #[Route('/my-calendar', name: 'app_mentorat_my_calendar')]
    public function myCalendar(Request $request, MentorAvailabilityRepository $availRepo): Response
    {
        $user = $this->getUser();

        $year  = (int) $request->query->get('year', date('Y'));
        $month = (int) $request->query->get('month', date('n'));

        if ($month < 1)  { $month = 12; $year--; }
        if ($month > 12) { $month = 1;  $year++; }

        $firstDay = new \DateTime("$year-$month-01");
        $lastDay  = (clone $firstDay)->modify('last day of this month');

        // Fetch only THIS mentor's availabilities for the month
        $availabilities = $availRepo->createQueryBuilder('a')
            ->where('a.mentor = :mentor')
            ->andWhere('a.date BETWEEN :start AND :end')
            ->setParameter('mentor', $user)
            ->setParameter('start', $firstDay)
            ->setParameter('end', $lastDay)
            ->orderBy('a.date', 'ASC')
            ->addOrderBy('a.startTime', 'ASC')
            ->getQuery()->getResult();

        $availByDate = [];
        foreach ($availabilities as $a) {
            $key = $a->getDate()->format('Y-m-d');
            $availByDate[$key][] = $a;
        }

        return $this->render('front/mentorat/my_calendar.html.twig', [
            'year'        => $year,
            'month'       => $month,
            'firstDay'    => $firstDay,
            'lastDay'     => $lastDay,
            'availByDate' => $availByDate,
        ]);
    }

    // ──────────────────────────────────────────────
    //  CHATBOT TAB
    // ──────────────────────────────────────────────

    #[Route('/chatbot', name: 'app_mentorat_chatbot')]
    public function chatbot(): Response
    {
        return $this->render('front/mentorat/chatbot.html.twig');
    }

    // ──────────────────────────────────────────────
    //  VOICE TRANSCRIPTION (Groq Whisper fallback)
    // ──────────────────────────────────────────────

    #[Route('/transcribe', name: 'app_mentorat_transcribe', methods: ['POST'])]
    public function transcribe(Request $request): JsonResponse
    {
        $audioFile = $request->files->get('audio');
        if (!$audioFile) {
            return new JsonResponse(['error' => 'Aucun fichier audio reçu.'], 400);
        }

        $apiKey = $this->getParameter('app.groq_api_key');
        if (!$apiKey) {
            return new JsonResponse(['error' => 'Clé API Groq non configurée.'], 500);
        }

        $tmpPath = $audioFile->getPathname();
        $boundary = bin2hex(random_bytes(16));

        // Build multipart form data manually for file_get_contents
        $body = '';
        // file field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"audio.webm\"\r\n";
        $body .= "Content-Type: audio/webm\r\n\r\n";
        $body .= file_get_contents($tmpPath) . "\r\n";
        // model field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
        $body .= "whisper-large-v3\r\n";
        // language field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
        $body .= "fr\r\n";
        $body .= "--{$boundary}--\r\n";

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Bearer {$apiKey}\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\n",
                'content' => $body,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $result = @file_get_contents('https://api.groq.com/openai/v1/audio/transcriptions', false, $context);

        if ($result === false) {
            return new JsonResponse(['error' => 'Échec de la transcription. Vérifiez votre connexion.'], 502);
        }

        $data = json_decode($result, true);
        $text = $data['text'] ?? '';

        return new JsonResponse(['text' => $text]);
    }
}
