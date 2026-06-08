<?php

namespace App\Controller;

use App\Entity\ContractMilestone;
use App\Entity\InvestmentContract;
use App\Entity\InvestmentContractMessage;
use App\Entity\InvestmentOffer;
use App\Entity\InvestmentOpportunity;
use App\Entity\User;
use App\Repository\ContractMilestoneRepository;
use App\Repository\InvestmentContractMessageRepository;
use App\Repository\InvestmentContractRepository;
use App\Service\Investment\ContractSignatureService;
use App\Service\Investment\ContractQrCodeService;
use App\Service\Investment\InvestmentChatbotService;
use App\Service\Investment\StripePaymentService;
use App\Service\EmailService;
use App\Service\NotificationService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/investissement/offers/{id}/contract')]
#[IsGranted('ROLE_USER')]
class InvestmentContractController extends AbstractController
{
    #[Route('', name: 'app_invest_contract_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        InvestmentOffer $offer,
        EntityManagerInterface $em,
        InvestmentContractMessageRepository $messageRepository,
        ContractMilestoneRepository $milestoneRepository,
        ContractSignatureService $signatureService,
        ContractQrCodeService $contractQrCodeService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $messages = $messageRepository->findChronological($contract);
        $milestones = $milestoneRepository->findByContract($contract);
        $verifyUrl = null;
        $verifyUrlIsPublic = false;

        if ($contract->isFullySigned()) {
            $verifyUrl = $contractQrCodeService->resolveVerificationUrl(
                $this->generateUrl('app_invest_contract_verify', ['contractId' => $contract->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
            );
            $verifyUrlIsPublic = $contractQrCodeService->isPublicVerificationUrl($verifyUrl);
        }

        // Compute deal temperature
        [$dealTemperature, $dealTemperatureLabel, $lastMessageAt] = $this->computeDealTemperature($contract, $messages);

        return $this->render('front/investment/contract.html.twig', [
            'offer' => $offer,
            'contract' => $contract,
            'messages' => $messages,
            'milestones' => $milestones,
            'otherParty' => $contract->getOtherParty($user),
            'currentUser' => $user,
            'isInvestorParty' => $contract->getInvestor()?->getId() === $user->getId(),
            'isEntrepreneurParty' => $contract->getEntrepreneur()?->getId() === $user->getId(),
            'hasCurrentUserSigned' => $contract->hasSigned($user),
            'pdfPreviewUrl' => $contract->isFullySigned() ? $this->generateUrl('app_invest_contract_pdf', ['id' => $offer->getId()]) : null,
            'pdfDownloadUrl' => $contract->isFullySigned() ? $this->generateUrl('app_invest_contract_pdf', ['id' => $offer->getId(), 'download' => 1]) : null,
            'printViewUrl' => $contract->isFullySigned() ? $this->generateUrl('app_invest_contract_print', ['id' => $offer->getId()]) : null,
            'dealTemperature' => $dealTemperature,
            'dealTemperatureLabel' => $dealTemperatureLabel,
            'lastMessageAt' => $lastMessageAt,
            'qrCodeUrl' => $contract->isFullySigned() ? $this->generateUrl('app_invest_contract_qr', ['contractId' => $contract->getId()]) : null,
            'verifyUrl' => $verifyUrl,
            'verifyUrlIsPublic' => $verifyUrlIsPublic,
        ]);
    }

    #[Route('/pdf', name: 'app_invest_contract_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        ContractQrCodeService $contractQrCodeService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $this->assertContractReadyForDocument($contract);

        $html = $this->renderView('front/investment/contract_pdf.html.twig', [
            'offer' => $offer,
            'contract' => $contract,
            'generatedAt' => new \DateTime(),
            'qrCodeDataUri' => $this->buildQrBase64($contract, $contractQrCodeService),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $safeProject = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($offer->getOpportunity()?->getProject()?->getTitre() ?? 'contract'));
        $filename = 'investment_contract_' . trim((string) $safeProject, '_') . '_' . $offer->getId() . '.pdf';
        $disposition = $request->query->getBoolean('download') ? 'attachment' : 'inline';

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
        ]);
    }

    #[Route('/print', name: 'app_invest_contract_print', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function printView(
        InvestmentOffer $offer,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        ContractQrCodeService $contractQrCodeService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $this->assertContractReadyForDocument($contract);

        return $this->render('front/investment/contract_print.html.twig', [
            'offer' => $offer,
            'contract' => $contract,
            'generatedAt' => new \DateTime(),
            'qrCodeDataUri' => $this->buildQrBase64($contract, $contractQrCodeService),
        ]);
    }

    #[Route('/terms', name: 'app_invest_contract_update_terms', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateTerms(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        NotificationService $notificationService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        if (!$this->isCsrfTokenValid('contract_terms_' . $offer->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $title = trim((string) $request->request->get('title', ''));
        $terms = trim((string) $request->request->get('terms', ''));
        $consideration = trim((string) $request->request->get('consideration', ''));
        $milestones = trim((string) $request->request->get('milestones', ''));
        $equityInput = trim((string) $request->request->get('equity_percentage', ''));

        if ($title === '' || mb_strlen($title) < 5) {
            $this->addFlash('danger', 'Contract title must be at least 5 characters.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if ($terms === '' || mb_strlen($terms) < 30) {
            $this->addFlash('danger', 'Contract terms must be at least 30 characters.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $equity = null;
        if ($equityInput !== '') {
            if (!is_numeric($equityInput) || (float) $equityInput < 0 || (float) $equityInput > 100) {
                $this->addFlash('danger', 'Equity percentage must be between 0 and 100.');
                return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
            }

            $equity = number_format((float) $equityInput, 2, '.', '');
        }

        $hasChanged = $contract->getTitle() !== $title
            || $contract->getTerms() !== $terms
            || (string) $contract->getEquityPercentage() !== (string) $equity
            || (string) $contract->getConsideration() !== $consideration
            || (string) $contract->getMilestones() !== $milestones;

        $contract->setTitle($title);
        $contract->setTerms($terms);
        $contract->setEquityPercentage($equity);
        $contract->setConsideration($consideration !== '' ? $consideration : null);
        $contract->setMilestones($milestones !== '' ? $milestones : null);
        $signatureService->refreshDigest($contract);

        if ($hasChanged) {
            $contract->clearSignatures();

            $message = new InvestmentContractMessage();
            $message->setContract($contract);
            $message->setSender($user);
            $message->setBody($user->getFullName() . ' updated the contract terms.');
            $message->setSystemMessage(true);

            $contract->setLastMessageAt(new \DateTime());
            $em->persist($message);

            $otherParty = $contract->getOtherParty($user);
            if ($otherParty) {
                $contractUrl = $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]);
                $notificationService->notify(
                    $otherParty,
                    'Contract terms updated',
                    $user->getFullName() . ' proposed a new version of the contract for ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'this project') . '.',
                    'CONTRACT',
                    $contractUrl,
                    'Review contract'
                );
            }
        }

        $em->flush();

        $this->addFlash('success', 'Contract terms saved.');
        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    #[Route('/sign', name: 'app_invest_contract_sign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sign(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        NotificationService $notificationService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        if (!$this->isCsrfTokenValid('contract_sign_' . $offer->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $signatureName = trim((string) $request->request->get('signature_name', ''));
        if (mb_strlen($signatureName) < 3) {
            $this->addFlash('danger', 'Please enter a valid full name to sign the contract.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $signatureImage = trim((string) $request->request->get('signature_image', ''));
        if ($signatureImage === '' || !str_starts_with($signatureImage, 'data:image/png;base64,')) {
            $this->addFlash('danger', 'Please draw your signature before confirming.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        try {
            $signatureHash = $signatureService->sign(
                $contract,
                $user,
                $signatureName,
                $signatureImage,
                $request->getClientIp(),
                $request->headers->get('User-Agent')
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $message = new InvestmentContractMessage();
        $message->setContract($contract);
        $message->setSender($user);
        $message->setBody($user->getFullName() . ' digitally signed the contract. SHA-256 fingerprint: ' . substr($signatureHash, 0, 16) . '...');
        $message->setSystemMessage(true);

        $contract->setLastMessageAt(new \DateTime());
        $em->persist($message);

        $otherParty = $contract->getOtherParty($user);
        if ($otherParty) {
            $contractUrl = $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]);
            $notificationService->notify(
                $otherParty,
                $contract->isFullySigned() ? 'Contract fully signed' : 'Signature received',
                $contract->isFullySigned()
                    ? 'The contract for ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'this project') . ' is now signed by both parties.'
                    : $user->getFullName() . ' signed the contract. Your signature is required.',
                'CONTRACT',
                $contractUrl,
                'Open contract'
            );
        }

        $em->flush();

        $this->addFlash('success', $contract->isFullySigned()
            ? 'Contract signed by both parties. Payment can now be processed.'
            : 'Your digital signature has been recorded.');

        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    #[Route('/messages', name: 'app_invest_contract_messages', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function messages(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        InvestmentContractMessageRepository $messageRepository,
        ContractSignatureService $signatureService,
    ): JsonResponse {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $afterId = max(0, (int) $request->query->get('afterId', 0));
        $messages = $afterId > 0
            ? $messageRepository->findAfterId($contract, $afterId)
            : $messageRepository->findChronological($contract);

        return $this->json([
            'messages' => array_map(fn (InvestmentContractMessage $message) => $this->serializeMessage($message, $user), $messages),
            'contract' => $this->serializeContract($contract, $user),
        ]);
    }

    #[Route('/messages', name: 'app_invest_contract_send_message', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendMessage(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        NotificationService $notificationService,
    ): JsonResponse {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        if (!$this->isCsrfTokenValid('contract_message_' . $offer->getId(), $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid security token.'], 403);
        }

        $body = trim((string) $request->request->get('message', ''));
        if ($body === '') {
            return $this->json(['error' => 'Message cannot be empty.'], 400);
        }

        if (mb_strlen($body) > 2000) {
            return $this->json(['error' => 'Message exceeds 2000 characters.'], 400);
        }

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $message = new InvestmentContractMessage();
        $message->setContract($contract);
        $message->setSender($user);
        $message->setBody($body);
        $contract->setLastMessageAt(new \DateTime());

        $em->persist($message);

        $otherParty = $contract->getOtherParty($user);
        if ($otherParty) {
            $contractUrl = $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]);
            $notificationService->notify(
                $otherParty,
                'New negotiation message',
                $user->getFullName() . ' sent you a message about the contract for ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'this project') . '.',
                'MESSAGE',
                $contractUrl,
                'View discussion'
            );
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'message' => $this->serializeMessage($message, $user),
            'contract' => $this->serializeContract($contract, $user),
        ]);
    }

    // ─── Milestone Management ─────────────────────────────────

    #[Route('/milestones/save', name: 'app_invest_contract_save_milestones', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveMilestones(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        ContractMilestoneRepository $milestoneRepo,
        NotificationService $notificationService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        if ($contract->getInvestor()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'message' => 'Only the investor can define funding milestones.'], 403);
        }

        if (!$this->isCsrfTokenValid('milestones_' . $offer->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        // Only allow milestone editing before any milestone is released
        foreach ($contract->getFundingMilestones() as $existing) {
            if ($existing->isReleased()) {
                $this->addFlash('danger', 'Milestones cannot be changed once a payment has been released.');
                return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
            }
        }

        $labels = $request->request->all('milestone_label');
        $percentages = $request->request->all('milestone_percentage');

        if (empty($labels) || count($labels) < 2 || count($labels) > 4) {
            $this->addFlash('danger', 'Define between 2 and 4 funding milestones.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $totalPct = 0.0;
        $totalAmount = (float) $offer->getProposedAmount();
        $parsed = [];

        for ($i = 0; $i < count($labels); $i++) {
            $lbl = trim($labels[$i] ?? '');
            $pct = round((float) ($percentages[$i] ?? 0), 2);

            if ($lbl === '' || mb_strlen($lbl) < 3) {
                $this->addFlash('danger', 'Each milestone must have a label of at least 3 characters.');
                return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
            }
            if ($pct < 5 || $pct > 80) {
                $this->addFlash('danger', 'Each milestone must represent between 5% and 80% of the total amount.');
                return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
            }

            $totalPct += $pct;
            $parsed[] = ['label' => $lbl, 'percentage' => $pct, 'amount' => round($totalAmount * $pct / 100, 2)];
        }

        if (abs($totalPct - 100.0) > 0.01) {
            $this->addFlash('danger', 'Percentages must total exactly 100%. Current total: ' . number_format($totalPct, 2) . '%.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        // Remove old milestones
        foreach ($contract->getFundingMilestones() as $old) {
            $em->remove($old);
        }
        $em->flush();

        // Create new milestones
        foreach ($parsed as $pos => $data) {
            $ms = new ContractMilestone();
            $ms->setContract($contract);
            $ms->setLabel($data['label']);
            $ms->setPercentage(number_format($data['percentage'], 2, '.', ''));
            $ms->setAmount(number_format($data['amount'], 2, '.', ''));
            $ms->setPosition($pos);
            $em->persist($ms);
        }

        // System message
        $msg = new InvestmentContractMessage();
        $msg->setContract($contract);
        $msg->setSender($user);
        $msg->setBody($user->getFullName() . ' defined ' . count($parsed) . ' funding milestones.');
        $msg->setSystemMessage(true);
        $contract->setLastMessageAt(new \DateTime());
        $em->persist($msg);

        // Notify other party
        $otherParty = $contract->getOtherParty($user);
        if ($otherParty) {
            $notificationService->notify(
                $otherParty,
                'Funding milestones defined',
                $user->getFullName() . ' configured the payment milestones (' . count($parsed) . ' steps) for ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'this project') . '.',
                'CONTRACT',
                $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]),
                'View contract'
            );
        }

        $em->flush();

        $this->addFlash('success', count($parsed) . ' funding milestones saved.');
        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    #[Route('/milestones/{milestoneId}/complete', name: 'app_invest_milestone_complete', requirements: ['id' => '\d+', 'milestoneId' => '\d+'], methods: ['POST'])]
    public function milestoneComplete(
        InvestmentOffer $offer,
        int $milestoneId,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        ContractMilestoneRepository $milestoneRepo,
        NotificationService $notificationService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        if ($contract->getEntrepreneur()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Only the entrepreneur can mark a milestone as complete.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if (!$this->isCsrfTokenValid('milestone_action_' . $milestoneId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $milestone = $milestoneRepo->find($milestoneId);
        if (!$milestone || $milestone->getContract()->getId() !== $contract->getId()) {
            $this->addFlash('danger', 'Milestone not found.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if (!$milestone->canBeMarkedComplete()) {
            $this->addFlash('danger', 'This milestone cannot be marked as complete in its current state.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $milestone->setStatus(ContractMilestone::STATUS_COMPLETED);
        $milestone->setCompletedAt(new \DateTime());

        $msg = new InvestmentContractMessage();
        $msg->setContract($contract);
        $msg->setSender($user);
        $msg->setBody('Milestone "' . $milestone->getLabel() . '" marked as complete by the entrepreneur. Awaiting investor confirmation.');
        $msg->setSystemMessage(true);
        $contract->setLastMessageAt(new \DateTime());
        $em->persist($msg);

        $investor = $contract->getInvestor();
        if ($investor) {
            $notificationService->notify(
                $investor,
                'Milestone completed',
                'The entrepreneur marked milestone "' . $milestone->getLabel() . '" as complete. Confirm to release payment.',
                'CONTRACT',
                $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]),
                'View contract'
            );
        }

        $em->flush();
        $this->addFlash('success', 'Milestone marked as complete.');
        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    #[Route('/milestones/{milestoneId}/confirm', name: 'app_invest_milestone_confirm', requirements: ['id' => '\d+', 'milestoneId' => '\d+'], methods: ['POST'])]
    public function milestoneConfirm(
        InvestmentOffer $offer,
        int $milestoneId,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        ContractMilestoneRepository $milestoneRepo,
        NotificationService $notificationService,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        if ($contract->getInvestor()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Only the investor can confirm a milestone.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if (!$this->isCsrfTokenValid('milestone_action_' . $milestoneId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $milestone = $milestoneRepo->find($milestoneId);
        if (!$milestone || $milestone->getContract()->getId() !== $contract->getId()) {
            $this->addFlash('danger', 'Milestone not found.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if (!$milestone->canBeConfirmed()) {
            $this->addFlash('danger', 'This milestone cannot be confirmed in its current state.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $milestone->setStatus(ContractMilestone::STATUS_CONFIRMED);
        $milestone->setConfirmedAt(new \DateTime());

        $msg = new InvestmentContractMessage();
        $msg->setContract($contract);
        $msg->setSender($user);
        $msg->setBody('Milestone "' . $milestone->getLabel() . '" confirmed by the investor. Partial payment ready to be released.');
        $msg->setSystemMessage(true);
        $contract->setLastMessageAt(new \DateTime());
        $em->persist($msg);

        $entrepreneur = $contract->getEntrepreneur();
        if ($entrepreneur) {
            $notificationService->notify(
                $entrepreneur,
                'Milestone confirmed',
                'The investor confirmed milestone "' . $milestone->getLabel() . '". The partial payment of ' . number_format((float) $milestone->getAmount(), 0, ',', ' ') . ' TND can now be released.',
                'CONTRACT',
                $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]),
                'View contract'
            );
        }

        $em->flush();
        $this->addFlash('success', 'Milestone confirmed. Partial payment can now be released.');
        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    #[Route('/milestones/{milestoneId}/release', name: 'app_invest_milestone_release', requirements: ['id' => '\d+', 'milestoneId' => '\d+'], methods: ['POST'])]
    public function milestoneRelease(
        InvestmentOffer $offer,
        int $milestoneId,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        ContractMilestoneRepository $milestoneRepo,
        StripePaymentService $paymentService,
        NotificationService $notificationService,
        EmailService $emailService,
        LoggerInterface $logger,
    ): Response {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        if ($contract->getInvestor()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Only the investor can release a payment.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if (!$contract->isFullySigned()) {
            $this->addFlash('danger', 'The contract must be signed by both parties.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if (!$this->isCsrfTokenValid('milestone_action_' . $milestoneId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $milestone = $milestoneRepo->find($milestoneId);
        if (!$milestone || $milestone->getContract()->getId() !== $contract->getId()) {
            $this->addFlash('danger', 'Milestone not found.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        if (!$milestone->canBeReleased()) {
            $this->addFlash('danger', 'This milestone must be confirmed before releasing payment.');
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        // Create a partial Stripe payment for this milestone
        $partialOffer = clone $offer;
        $partialOffer->setProposedAmount($milestone->getAmount());

        $result = $paymentService->payAcceptedOffer($partialOffer);
        if (!($result['success'] ?? false)) {
            $this->addFlash('danger', 'Stripe payment failed: ' . ($result['error'] ?? 'Unknown error.'));
            return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
        }

        $milestone->setStatus(ContractMilestone::STATUS_RELEASED);
        $milestone->setReleasedAt(new \DateTime());
        $milestone->setPaymentIntentId($result['paymentIntentId'] ?? null);

        // Check if all milestones are now released — mark offer as paid
        $allReleased = true;
        foreach ($contract->getFundingMilestones() as $m) {
            if ($m->getId() === $milestone->getId()) continue;
            if (!$m->isReleased()) { $allReleased = false; break; }
        }

        if ($allReleased) {
            $offer->setPaid(true);
            $offer->setPaidAt(new \DateTime());
            $offer->setPaymentIntentId('milestones_complete');
            $contract->setStatus(InvestmentContract::STATUS_FUNDED);
            $this->markOpportunityFundedIfPaidOut($offer->getOpportunity());
        }

        $msg = new InvestmentContractMessage();
        $msg->setContract($contract);
        $msg->setSender($user);
        $msg->setBody('Payment of ' . number_format((float) $milestone->getAmount(), 0, ',', ' ') . ' TND released for milestone "' . $milestone->getLabel() . '".' . ($allReleased ? ' All milestones are funded.' : ''));
        $msg->setSystemMessage(true);
        $contract->setLastMessageAt(new \DateTime());
        $em->persist($msg);

        $entrepreneur = $contract->getEntrepreneur();
        if ($entrepreneur) {
            $notificationService->notify(
                $entrepreneur,
                $allReleased ? 'Fully funded' : 'Partial payment received',
                $allReleased
                    ? 'All milestones for ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'this project') . ' are funded.'
                    : 'The investor released ' . number_format((float) $milestone->getAmount(), 0, ',', ' ') . ' TND for milestone "' . $milestone->getLabel() . '".',
                'SUCCESS',
                $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]),
                'View contract'
            );
        }

        if ($allReleased) {
            $notificationService->notify(
                $user,
                'Fully funded',
                'All milestones for ' . ($offer->getOpportunity()->getProject()?->getTitre() ?? 'this project') . ' have been successfully funded.',
                'SUCCESS',
                $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()]),
                'View contract'
            );
        }

        $em->flush();

        try {
            if ($user->getEmail()) {
                $emailService->sendInvestmentPaymentConfirmation(
                    $user->getEmail(),
                    $user->getFirstname() ?? 'Utilisateur',
                    $offer->getOpportunity()->getProject()?->getTitre() ?? 'Projet',
                    number_format((float) $milestone->getAmount(), 0, ',', ' ') . ' DT',
                    $milestone->getReleasedAt()?->format('d/m/Y H:i') ?? (new \DateTime())->format('d/m/Y H:i'),
                    $milestone->getPaymentIntentId(),
                    $this->generateUrl('app_invest_contract_show', ['id' => $offer->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    $allReleased
                        ? 'Final milestone payment — contract fully funded'
                        : 'Milestone payment confirmed — ' . $milestone->getLabel()
                );
            }
        } catch (\Throwable $exception) {
            $logger->error('Unable to send milestone payment confirmation email.', [
                'offerId' => $offer->getId(),
                'milestoneId' => $milestone->getId(),
                'paymentIntentId' => $milestone->getPaymentIntentId(),
                'exception' => $exception,
            ]);
        }

        $this->addFlash('success', 'Payment of ' . number_format((float) $milestone->getAmount(), 0, ',', ' ') . ' TND released.');
        return $this->redirectToRoute('app_invest_contract_show', ['id' => $offer->getId()]);
    }

    private function markOpportunityFundedIfPaidOut(?InvestmentOpportunity $opportunity): void
    {
        if ($opportunity && $opportunity->getTotalFunded() >= (float) $opportunity->getTargetAmount()) {
            $opportunity->setStatus(InvestmentOpportunity::STATUS_FUNDED);
        }
    }

    #[Route('/advisor', name: 'app_invest_contract_advisor', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function advisor(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        InvestmentChatbotService $chatbot,
        InvestmentContractMessageRepository $messageRepository,
        ContractMilestoneRepository $milestoneRepository,
    ): JsonResponse {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $message = trim($request->request->get('message', ''));
        if ($message === '') {
            return $this->json(['response' => null, 'error' => 'Empty message.'], 400);
        }

        if (mb_strlen($message) > 2000) {
            return $this->json(['response' => null, 'error' => 'Message too long (max 2000 characters).'], 400);
        }

        $historyRaw = $request->request->get('conversationHistory', '[]');
        $conversationHistory = json_decode($historyRaw, true);
        if (!is_array($conversationHistory)) {
            $conversationHistory = [];
        }

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $messages = $messageRepository->findChronological($contract);
        $milestones = $milestoneRepository->findByContract($contract);

        $context = [
            'mode' => 'contract',
            'projectName' => $offer->getOpportunity()?->getProject()?->getTitre() ?? 'N/A',
            'sector' => $offer->getOpportunity()?->getProject()?->getSecteur() ?? 'N/A',
            'amount' => (string) $offer->getProposedAmount(),
            'equity' => $contract->getEquityPercentage() !== null ? (string) $contract->getEquityPercentage() : 'Not set',
            'contractStatus' => $contract->getStatus(),
            'milestoneCount' => (string) count($milestones),
            'messageCount' => (string) count($messages),
            'bothSigned' => $contract->isFullySigned() ? 'yes' : 'no',
        ];

        try {
            $response = $chatbot->chatWithContext($message, $context, $conversationHistory);
            return $this->json(['response' => $response, 'error' => null]);
        } catch (\Throwable $e) {
            return $this->json(['response' => null, 'error' => 'AI service error.'], 500);
        }
    }

    #[Route('/ai-suggest', name: 'app_invest_contract_ai_suggest', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function aiSuggest(
        InvestmentOffer $offer,
        Request $request,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
        InvestmentChatbotService $chatbot,
    ): JsonResponse {
        $user = $this->requireUser();
        $this->assertContractAccess($offer, $user);

        $field = trim((string) $request->request->get('field', ''));
        if (!in_array($field, ['equity', 'milestones', 'terms'], true)) {
            return $this->json(['suggestion' => $this->getStaticFallback('equity', $offer)]);
        }

        $contract = $this->getOrCreateContract($offer, $em, $signatureService);
        $amount = (float) $offer->getProposedAmount();
        $sector = $offer->getOpportunity()?->getProject()?->getSecteur() ?? 'general';
        $projectTitle = $offer->getOpportunity()?->getProject()?->getTitre() ?? 'ce projet';

        // Try AI via chatbot service
        try {
            $prompt = $this->buildSuggestPrompt($field, $sector, $amount, $projectTitle, $contract);
            $response = $chatbot->chat($prompt);

            if (!$chatbot->isFailureResponse($response)) {
                return $this->json(['suggestion' => $response]);
            }
        } catch (\Throwable $e) {
            // fall through to static fallback
        }

        return $this->json(['suggestion' => $this->getStaticFallback($field, $offer)]);
    }

    private function buildSuggestPrompt(string $field, string $sector, float $amount, string $projectTitle, InvestmentContract $contract): string
    {
        return match ($field) {
            'equity' => sprintf(
                'For an investment of %.0f TND in the "%s" sector (project: %s), what equity percentage range is typical? '
                . 'Answer in 2-3 concise sentences with a suggested percentage.',
                $amount, $sector, $projectTitle
            ),
            'milestones' => sprintf(
                'Suggest a funding milestone structure for the project "%s" in the "%s" sector with an investment of %.0f TND. '
                . 'Propose 3 stages with percentage breakdown. Answer in 3-4 sentences.',
                $projectTitle, $sector, $amount
            ),
            'terms' => sprintf(
                'What key clauses should appear in a %.0f TND investment contract for project "%s" (sector: %s)? '
                . 'List the 4-5 most important clauses in one sentence each.',
                $amount, $projectTitle, $sector
            ),
            default => 'Provide a general investment recommendation for this contract.',
        };
    }

    private function getStaticFallback(string $field, InvestmentOffer $offer): string
    {
        $amount = (float) $offer->getProposedAmount();

        return match ($field) {
            'equity' => $amount < 50000
                ? 'For an investment under 50,000 TND in a small structure (1-10 employees), an equity stake of 5% to 15% is typical. Adjust based on project maturity and existing revenue.'
                : 'For an investment of this size, an equity stake of 10% to 25% is typically negotiated. Factor in the pre-money valuation and growth potential.',
            'milestones' => $amount < 50000
                ? 'Suggested structure: (1) 40% at kickoff after signing, (2) 30% on prototype or first version delivery, (3) 30% at commercial launch. Adapt based on project complexity.'
                : 'Suggested structure: (1) 30% at kickoff, (2) 25% at first technical milestone, (3) 25% at market validation, (4) 20% at final deployment. Each stage should have clear validation criteria.',
            'terms' => $amount < 50000
                ? 'Recommended clauses: (1) Right of oversight on strategic decisions, (2) Mandatory quarterly reporting, (3) Exit clause with right of first refusal, (4) Anti-dilution protection, (5) Founder non-compete clause.'
                : 'Recommended clauses: (1) Board seat, (2) Monthly financial and operational reporting, (3) Tag-along right, (4) Enhanced anti-dilution protection, (5) Liquidation preference clause.',
            default => 'Consult a legal advisor to tailor the terms to your specific situation.',
        };
    }

    private function getOrCreateContract(
        InvestmentOffer $offer,
        EntityManagerInterface $em,
        ContractSignatureService $signatureService,
    ): InvestmentContract {
        $existing = $offer->getContract();
        if ($existing) {
            return $existing;
        }

        [$investor, $entrepreneur] = $this->resolveParties($offer, $em, null);
        if (!$entrepreneur) {
            throw $this->createNotFoundException('Project not found for this contract.');
        }

        if (!$investor) {
            throw $this->createNotFoundException('Investor not found for this contract.');
        }

        $contract = new InvestmentContract();
        $contract->setOffer($offer);
        $contract->setInvestor($investor);
        $contract->setEntrepreneur($entrepreneur);
        $contract->setTitle('Investment Agreement — ' . ($offer->getOpportunity()?->getProject()?->getTitre() ?? 'Project'));
        $contract->setTerms($signatureService->createDefaultTerms($offer));
        $contract->setConsideration('To be defined: equity stake, early product access, distribution rights, or other negotiated consideration.');
        $contract->setMilestones('To be defined: delivery schedule, validation checkpoints, and respective obligations of each party.');
        $signatureService->refreshDigest($contract);

        $em->persist($contract);
        $em->flush();

        return $contract;
    }

    private function assertContractAccess(InvestmentOffer $offer, User $user): void
    {
        if ($offer->getStatus() !== InvestmentOffer::STATUS_ACCEPTED) {
            throw $this->createNotFoundException('The contract is only available for an accepted offer.');
        }

        $contract = $offer->getContract();
        [$investor, $entrepreneur] = $this->resolveParties($offer, null, $contract);
        $isInvestor = $investor?->getId() === $user->getId();
        $isEntrepreneur = $entrepreneur?->getId() === $user->getId();

        if (!$isInvestor && !$isEntrepreneur) {
            throw $this->createAccessDeniedException('You do not have access to this contract.');
        }
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication required.');
        }

        return $user;
    }

    private function serializeMessage(InvestmentContractMessage $message, User $currentUser): array
    {
        return [
            'id' => $message->getId(),
            'body' => $message->getBody(),
            'createdAt' => $message->getCreatedAt()?->format('d/m/Y H:i'),
            'system' => $message->isSystemMessage(),
            'mine' => $message->getSender()?->getId() === $currentUser->getId(),
            'senderName' => $message->getSender()?->getFullName() ?? 'User',
        ];
    }

    private function serializeContract(InvestmentContract $contract, User $currentUser): array
    {
        return [
            'status' => $contract->getStatus(),
            'digest' => $contract->getTermsDigest(),
            'fullySigned' => $contract->isFullySigned(),
            'investorSigned' => $contract->getInvestorSignedAt()?->format('d/m/Y H:i'),
            'entrepreneurSigned' => $contract->getEntrepreneurSignedAt()?->format('d/m/Y H:i'),
            'signedByMe' => $contract->hasSigned($currentUser),
            'isInvestorParty' => $contract->getInvestor()?->getId() === $currentUser->getId(),
            'isEntrepreneurParty' => $contract->getEntrepreneur()?->getId() === $currentUser->getId(),
        ];
    }

    private function assertContractReadyForDocument(InvestmentContract $contract): void
    {
        if (!$contract->isFullySigned()) {
            throw $this->createAccessDeniedException('The PDF is only available once the contract is signed by both parties.');
        }
    }

    private function resolveParties(
        InvestmentOffer $offer,
        ?EntityManagerInterface $em = null,
        ?InvestmentContract $contract = null,
    ): array {
        $investor = $contract?->getInvestor() ?? $offer->getInvestor();
        $entrepreneur = $contract?->getEntrepreneur() ?? $offer->getOpportunity()?->getProject()?->getUser();

        if (!$entrepreneur && $em !== null) {
            $entrepreneurId = $offer->getOpportunity()?->getProject()?->getEntrepreneurId();
            if ($entrepreneurId) {
                $resolved = $em->getRepository(User::class)->find($entrepreneurId);
                if ($resolved instanceof User) {
                    $entrepreneur = $resolved;
                }
            }
        }

        return [$investor, $entrepreneur];
    }

    /**
     * Compute deal temperature from behavioral signals already in the database.
     * @return array{int, string, ?\DateTimeInterface} [score 0-100, label, lastMessageAt]
     */
    private function buildQrBase64(InvestmentContract $contract, ContractQrCodeService $contractQrCodeService): string
    {
        $verifyUrl = $contractQrCodeService->resolveVerificationUrl(
            $this->generateUrl('app_invest_contract_verify', ['contractId' => $contract->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
        );

        return $contractQrCodeService->buildDataUri($contract, $verifyUrl, 200, 6);
    }

    private function computeDealTemperature(InvestmentContract $contract, array $messages): array
    {
        $now = new \DateTimeImmutable();
        $score = 0;

        // Find last message timestamp and sender set
        $lastMessageAt = null;
        $senderIds = [];
        foreach ($messages as $msg) {
            $msgDate = $msg->getCreatedAt();
            if ($msgDate && (!$lastMessageAt || $msgDate > $lastMessageAt)) {
                $lastMessageAt = $msgDate;
            }
            if ($msg->getSender()) {
                $senderIds[$msg->getSender()->getId()] = true;
            }
        }

        // +25 if a message in last 24h, +20 if in last 72h (but not 24h)
        if ($lastMessageAt) {
            $hoursSince = ($now->getTimestamp() - $lastMessageAt->getTimestamp()) / 3600;
            if ($hoursSince <= 24) {
                $score += 25;
            } elseif ($hoursSince <= 72) {
                $score += 20;
            }
        }

        // +20 if both parties have sent at least one message each
        if (count($senderIds) >= 2) {
            $score += 20;
        }

        // +15 if terms were edited (updatedAt differs from createdAt by >1 min)
        $created = $contract->getCreatedAt();
        $updated = $contract->getUpdatedAt();
        if ($created && $updated) {
            $editDiff = abs($updated->getTimestamp() - $created->getTimestamp());
            if ($editDiff > 60) {
                $score += 15;
            }
        }

        // +15 if both parties have viewed (both sent messages, or at least one has signed)
        if (count($senderIds) >= 2 || $contract->getInvestorSignedAt() || $contract->getEntrepreneurSignedAt()) {
            $score += 15;
        }

        // -20 if no message in last 7 days and contract not yet signed
        if (!$contract->isFullySigned()) {
            if ($lastMessageAt) {
                $daysSince = ($now->getTimestamp() - $lastMessageAt->getTimestamp()) / 86400;
                if ($daysSince > 7) {
                    $score -= 20;
                }
            } else {
                $score -= 20;
            }
        }

        // -10 if deadline within 5 days and unsigned
        if (!$contract->isFullySigned()) {
            $deadline = $contract->getOffer()?->getOpportunity()?->getDeadline();
            if ($deadline) {
                $daysLeft = ($deadline->getTimestamp() - $now->getTimestamp()) / 86400;
                if ($daysLeft >= 0 && $daysLeft <= 5) {
                    $score -= 10;
                }
            }
        }

        $score = max(0, min(100, $score));

        if ($score <= 35) {
            $label = 'cold';
        } elseif ($score <= 65) {
            $label = 'active';
        } else {
            $label = 'hot';
        }

        return [$score, $label, $lastMessageAt];
    }
}