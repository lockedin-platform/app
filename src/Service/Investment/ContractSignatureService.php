<?php

namespace App\Service\Investment;

use App\Entity\InvestmentContract;
use App\Entity\InvestmentOffer;
use App\Entity\User;

class ContractSignatureService
{
    public function refreshDigest(InvestmentContract $contract): string
    {
        $snapshot = [
            'offerId' => $contract->getOffer()?->getId(),
            'amount' => $contract->getOffer()?->getProposedAmount(),
            'project' => $contract->getOffer()?->getOpportunity()?->getProject()?->getTitre(),
            'title' => trim($contract->getTitle()),
            'terms' => trim($contract->getTerms()),
            'equity' => $contract->getEquityPercentage(),
            'consideration' => trim((string) $contract->getConsideration()),
            'milestones' => trim((string) $contract->getMilestones()),
        ];

        $digest = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $contract->setTermsDigest($digest);

        return $digest;
    }

    public function sign(
        InvestmentContract $contract,
        User $user,
        string $signatureName,
        string $signatureImage,
        ?string $ipAddress,
        ?string $userAgent,
    ): string {
        if (!$contract->belongsTo($user)) {
            throw new \InvalidArgumentException('Utilisateur non autorise a signer ce contrat.');
        }

        $signedAt = new \DateTime();
        $digest = $this->refreshDigest($contract);
        $role = $contract->getInvestor()?->getId() === $user->getId() ? 'INVESTOR' : 'ENTREPRENEUR';

        $evidence = implode('|', [
            $digest,
            $role,
            trim($signatureName),
            (string) $user->getEmail(),
            $signedAt->format(DATE_ATOM),
            trim((string) $ipAddress),
            trim(substr((string) $userAgent, 0, 255)),
            hash('sha256', $signatureImage),
        ]);

        $signatureHash = hash('sha256', $evidence);
        $contract->markSignedBy($user, trim($signatureName), $signatureHash, $signedAt);

        // Store the drawn signature image
        if ($contract->getInvestor()?->getId() === $user->getId()) {
            $contract->setInvestorSignatureImage($signatureImage);
        } else {
            $contract->setEntrepreneurSignatureImage($signatureImage);
        }

        return $signatureHash;
    }

    public function createDefaultTerms(InvestmentOffer $offer): string
    {
        $projectTitle = $offer->getOpportunity()?->getProject()?->getTitre() ?? 'Projet';

        return implode("\n\n", [
            '1. Subject of the Agreement',
            'This contract formalises the investment proposal between the entrepreneur and the investor for the project ' . $projectTitle . '.',
            '2. Investment Amount',
            'The investor agrees to fund the accepted amount of ' . number_format((float) $offer->getProposedAmount(), 2, '.', '') . ' TND, subject to the signature of both parties.',
            '3. Counterparties and Rights',
            'The parties define below the counterparties, including an equity stake, priority product access, or any other negotiated benefit.',
            '4. Execution',
            'Any modification of the terms cancels previous signatures and requires a new SHA-256 signature from both parties.',
        ]);
    }
}