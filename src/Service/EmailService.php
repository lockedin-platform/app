<?php

namespace App\Service;

use App\Entity\Event;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Twig\Environment;

class EmailService
{
    private const SENDER_EMAIL = 'ilyessguesmi7@gmail.com';
    private const SENDER_NAME = 'Najahni';

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
    ) {}

    public function sendVerificationCode(string $to, string $code, string $firstname): void
    {
        $html = $this->twig->render('emails/verification_code.html.twig', [
            'code' => $code,
            'firstname' => $firstname,
        ]);

        $email = (new Email())
            ->from(new Address(self::SENDER_EMAIL, self::SENDER_NAME))
            ->to($to)
            ->subject('Code de vérification - Najahni')
            ->html($html);

        $this->mailer->send($email);
    }

    public function sendPasswordResetCode(string $to, string $code, string $firstname): void
    {
        $html = $this->twig->render('emails/password_reset.html.twig', [
            'code' => $code,
            'firstname' => $firstname,
        ]);

        $email = (new Email())
            ->from(new Address(self::SENDER_EMAIL, self::SENDER_NAME))
            ->to($to)
            ->subject('Réinitialisation de mot de passe - Najahni')
            ->html($html);

        $this->mailer->send($email);
    }

    public function sendPasswordChangeConfirmation(string $to, string $firstname): void
    {
        $html = $this->twig->render('emails/password_changed.html.twig', [
            'firstname' => $firstname,
        ]);

        $email = (new Email())
            ->from(new Address(self::SENDER_EMAIL, self::SENDER_NAME))
            ->to($to)
            ->subject('Confirmation de changement de mot de passe - Najahni')
            ->html($html);

        $this->mailer->send($email);
    }

    public function sendWelcomeEmail(string $to, string $firstname): void
    {
        $html = $this->twig->render('emails/welcome.html.twig', [
            'firstname' => $firstname,
        ]);

        $email = (new Email())
            ->from(new Address(self::SENDER_EMAIL, self::SENDER_NAME))
            ->to($to)
            ->subject('Bienvenue sur Najahni !')
            ->html($html);

        $this->mailer->send($email);
    }

    /** @param array<string, mixed> $ticket */
    public function sendCommunityEventTicket(
        string $to,
        string $firstname,
        Event $event,
        array $ticket,
        ?string $qrPng,
        string $eventUrl,
    ): void {
        $html = $this->twig->render('emails/community_event_ticket.html.twig', [
            'firstname' => $firstname,
            'event' => $event,
            'ticket' => $ticket,
            'eventUrl' => $eventUrl,
            'hasQrAttachment' => is_string($qrPng) && $qrPng !== '',
        ]);

        $email = (new Email())
            ->from(new Address(self::SENDER_EMAIL, self::SENDER_NAME))
            ->to($to)
            ->subject('Votre ticket pour '.((string) $event->getTitle()).' - Najahni')
            ->html($html);

        if (is_string($qrPng) && $qrPng !== '') {
            $email->attach($qrPng, 'ticket-evenement-'.((int) $event->getId()).'.png', 'image/png');
        }

        $this->mailer->send($email);
    }

    /** @param array<int, string> $recipients */
    public function sendBroadcast(array $recipients, string $subject, string $body): int
    {
        $sent = 0;
        foreach ($recipients as $to) {
            $html = $this->twig->render('emails/broadcast.html.twig', [
                'subject' => $subject,
                'body' => $body,
            ]);

            $email = (new Email())
                ->from(new Address(self::SENDER_EMAIL, self::SENDER_NAME))
                ->to($to)
                ->subject($subject . ' - Najahni')
                ->html($html);

            $this->mailer->send($email);
            $sent++;
            usleep(200000); // 200ms rate limiting
        }
        return $sent;
    }

    public function sendInvestmentPaymentConfirmation(
        string $to,
        string $firstname,
        string $projectTitle,
        string $amountLabel,
        string $paidAtLabel,
        ?string $paymentReference = null,
        ?string $contractUrl = null,
        ?string $paymentLabel = null,
    ): void {
        $html = $this->twig->render('emails/payment_confirmation.html.twig', [
            'firstname' => $firstname,
            'projectTitle' => $projectTitle,
            'amountLabel' => $amountLabel,
            'paidAtLabel' => $paidAtLabel,
            'paymentReference' => $paymentReference,
            'contractUrl' => $contractUrl,
            'paymentLabel' => $paymentLabel ?? 'Paiement d\'investissement confirme',
        ]);

        $email = (new Email())
            ->from(new Address(self::SENDER_EMAIL, self::SENDER_NAME))
            ->to($to)
            ->subject('Confirmation de paiement - Najahni')
            ->html($html);

        $this->mailer->send($email);
    }
}
