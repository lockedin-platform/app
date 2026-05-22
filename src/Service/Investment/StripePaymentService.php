<?php

namespace App\Service\Investment;

use App\Entity\InvestmentOffer;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripePaymentService
{
    private const TEST_CURRENCY = 'eur';

    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly string $stripeSecretKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->stripeSecretKey !== ''
            && $this->stripeSecretKey !== 'sk_test_your_stripe_key'
            && str_starts_with($this->stripeSecretKey, 'sk_');
    }

    /** @return array<string, bool|string> */
    public function payAcceptedOffer(InvestmentOffer $offer): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Stripe n\'est pas configure. Verifiez STRIPE_SECRET_KEY dans .env.',
            ];
        }

        try {
            $intent = $this->stripeClient->paymentIntents->create([
                'amount' => $this->toCents((float) $offer->getProposedAmount()),
                'currency' => self::TEST_CURRENCY,
                'description' => sprintf(
                    'Investissement Najahni - Offre #%d - Opportunite #%d',
                    $offer->getId(),
                    $offer->getOpportunity()?->getId() ?? 0,
                ),
                'payment_method' => 'pm_card_visa',
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'offer_id' => (string) $offer->getId(),
                    'opportunity_id' => (string) ($offer->getOpportunity()?->getId() ?? 0),
                    'investor_id' => (string) ($offer->getInvestor()?->getId() ?? 0),
                ],
            ]);

            return [
                'success' => in_array($intent->status, ['succeeded', 'processing', 'requires_capture'], true),
                'paymentIntentId' => $intent->id,
                'status' => $intent->status,
                'currency' => strtoupper((string) $intent->currency),
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'error' => $e->getError()->message ?? $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Le paiement Stripe a echoue. Reessayez dans quelques instants.',
            ];
        }
    }

    private function toCents(float $amount): int
    {
        return max(50, (int) round($amount * 100));
    }
}