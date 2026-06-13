<?php

namespace App\Command;

use App\Entity\ContractMilestone;
use App\Entity\InvestmentContract;
use App\Entity\InvestmentContractMessage;
use App\Entity\InvestmentOffer;
use App\Entity\InvestmentOpportunity;
use App\Entity\InvestorProfile;
use App\Entity\Projet;
use App\Entity\User;
use App\Repository\InvestorProfileRepository;
use App\Repository\UserRepository;
use App\Service\Investment\ContractSignatureService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:investment:seed-demo',
    description: 'Reset investment data and seed professional-grade projects, opportunities, offers, contracts, and portfolio examples.',
)]
class SeedInvestmentDemoDataCommand extends Command
{
    private const SEED_MARKER = 'DEMO_SEED::investment';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly InvestorProfileRepository $profileRepository,
        private readonly ContractSignatureService $signatureService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $investors = $this->userRepository->findBy(['role' => User::ROLE_INVESTISSEUR], ['id' => 'ASC']);
        $entrepreneurs = $this->userRepository->findBy(['role' => User::ROLE_ENTREPRENEUR], ['id' => 'ASC']);

        if (count($investors) < 2 || count($entrepreneurs) < 3) {
            $io->error('Not enough users found. At least 2 investors and 3 entrepreneurs are required.');
            return Command::FAILURE;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->resetInvestmentData();
            $this->seedInvestorProfiles($investors);
            $summary = $this->seedDataset($entrepreneurs, $investors);

            $this->entityManager->flush();
            $connection->commit();

            $io->success('Investment dataset recreated successfully.');
            $io->table(['Metric', 'Value'], [
                ['Projects created', (string) $summary['projects']],
                ['Opportunities created', (string) $summary['opportunities']],
                ['Offers created', (string) $summary['offers']],
                ['Contracts created', (string) $summary['contracts']],
                ['Milestones created', (string) $summary['milestones']],
                ['Paid portfolio examples', (string) $summary['paidOffers']],
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $io->error($exception->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($exception->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    private function resetInvestmentData(): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('DELETE FROM contract_milestone');
        $connection->executeStatement('DELETE FROM investment_contract_message');
        $connection->executeStatement('DELETE FROM investment_contract');
        $connection->executeStatement('DELETE FROM investment_offer');
        $connection->executeStatement('DELETE FROM investment_opportunity');
        $connection->executeStatement('DELETE FROM projet WHERE titre LIKE ?', ['LockedIn Demo:%']);
        $connection->executeStatement('DELETE FROM projet WHERE diagnostic_ia LIKE ?', [self::SEED_MARKER . '%']);
    }

    /**
     * @param User[] $investors
     */
    private function seedInvestorProfiles(array $investors): void
    {
        $profiles = [
            [
                'budgetMin' => '12000',
                'budgetMax' => '85000',
                'preferredSectors' => 'AgriTech, FinTech, Clean Energy, SaaS, Supply Chain',
                'riskTolerance' => 6,
                'horizonMonths' => 24,
                'description' => 'Investor looking for growth-stage Tunisian SMEs with clear operational milestones and disciplined execution.',
            ],
            [
                'budgetMin' => '8000',
                'budgetMax' => '60000',
                'preferredSectors' => 'HealthTech, EdTech, Tourism, Food Processing',
                'riskTolerance' => 4,
                'horizonMonths' => 18,
                'description' => 'Prefers capital-efficient businesses with tangible traction, predictable delivery plans, and moderate risk.',
            ],
        ];

        foreach ($investors as $index => $investor) {
            $profile = $this->profileRepository->findByUser($investor) ?? (new InvestorProfile())->setUser($investor);
            $data = $profiles[$index % count($profiles)];

            $profile
                ->setBudgetMin($data['budgetMin'])
                ->setBudgetMax($data['budgetMax'])
                ->setPreferredSectors($data['preferredSectors'])
                ->setRiskTolerance($data['riskTolerance'])
                ->setHorizonMonths($data['horizonMonths'])
                ->setDescription($data['description']);

            $this->entityManager->persist($profile);
        }
    }

    /**
     * @param User[] $entrepreneurs
     * @param User[] $investors
     * @return array{projects:int, opportunities:int, offers:int, contracts:int, milestones:int, paidOffers:int}
     */
    private function seedDataset(array $entrepreneurs, array $investors): array
    {
        $summary = [
            'projects' => 0,
            'opportunities' => 0,
            'offers' => 0,
            'contracts' => 0,
            'milestones' => 0,
            'paidOffers' => 0,
        ];

        $projectBlueprints = [
            [
                'title' => 'Sahara Smart Irrigation',
                'sector' => 'AgriTech',
                'description' => 'A connected irrigation platform for Tunisian farms combining low-cost soil sensors, automated valves, and predictive watering schedules to reduce water waste by up to 28%.',
                'stage' => 'Prototype pilote',
                'targetAmount' => '48000',
                'opportunityDescription' => 'Seed round to scale the pilot from 12 farms to 60 farms across Kairouan and Sidi Bouzid, strengthen installation operations, and industrialize sensor maintenance.',
                'riskScore' => 39,
                'riskLabel' => 'Modere',
                'deadlineOffsetDays' => 120,
                'entrepreneurIndex' => 0,
            ],
            [
                'title' => 'Atlas SME Treasury Cloud',
                'sector' => 'FinTech',
                'description' => 'A SaaS treasury and cashflow planning suite built for Tunisian SMEs that need invoice forecasting, payment visibility, and basic financial scenario planning.',
                'stage' => 'Commercialisation',
                'targetAmount' => '72000',
                'opportunityDescription' => 'Growth capital to expand sales capacity, onboard 120 paying SME accounts, and finalize bank export integrations used by accountants and CFO teams.',
                'riskScore' => 31,
                'riskLabel' => 'Faible',
                'deadlineOffsetDays' => 150,
                'entrepreneurIndex' => 1,
            ],
            [
                'title' => 'MedAssist Home Diagnostics',
                'sector' => 'HealthTech',
                'description' => 'A telehealth workflow for at-home diagnostics and nurse coordination aimed at chronic care patients who need structured follow-up outside hospitals.',
                'stage' => 'Validation marche',
                'targetAmount' => '65000',
                'opportunityDescription' => 'Funding to certify key kits, secure clinic partnerships, and formalize a recurring care coordination model in Tunis and Sfax.',
                'riskScore' => 52,
                'riskLabel' => 'Modere',
                'deadlineOffsetDays' => 95,
                'entrepreneurIndex' => 2,
            ],
            [
                'title' => 'Djerba Eco Retreat',
                'sector' => 'Tourisme',
                'description' => 'A boutique eco-retreat concept blending low-impact hospitality, curated wellness experiences, and local sourcing partnerships in Djerba.',
                'stage' => 'Lancement',
                'targetAmount' => '90000',
                'opportunityDescription' => 'Capital raise to finish fit-out, pre-launch a direct booking funnel, and secure a first operating season with an experience-led positioning.',
                'riskScore' => 58,
                'riskLabel' => 'Modere',
                'deadlineOffsetDays' => 80,
                'entrepreneurIndex' => 3,
            ],
            [
                'title' => 'Carthage Solar Microgrid',
                'sector' => 'Clean Energy',
                'description' => 'A modular solar microgrid deployment model for small industrial sites seeking more stable energy costs and lower dependence on peak grid pricing.',
                'stage' => 'Execution',
                'targetAmount' => '110000',
                'opportunityDescription' => 'Project finance to complete equipment procurement, installation engineering, and two contracted industrial pilot sites.',
                'riskScore' => 44,
                'riskLabel' => 'Modere',
                'deadlineOffsetDays' => 140,
                'entrepreneurIndex' => 4,
            ],
            [
                'title' => 'Maghreb Learning Studio',
                'sector' => 'EdTech',
                'description' => 'A B2B learning content studio producing Arabic-French vocational microlearning paths for hospitality, retail, and frontline operations teams.',
                'stage' => 'Traction initiale',
                'targetAmount' => '38000',
                'opportunityDescription' => 'Bridge financing to deliver signed training contracts, expand the authoring team, and productize course libraries into reusable subscriptions.',
                'riskScore' => 47,
                'riskLabel' => 'Modere',
                'deadlineOffsetDays' => 105,
                'entrepreneurIndex' => 0,
            ],
            [
                'title' => 'Crescent Logistics OS',
                'sector' => 'Supply Chain',
                'description' => 'A workflow platform for route planning, delivery proof, and SME fleet efficiency aimed at regional distributors moving goods between Tunis, Sousse, and Sfax.',
                'stage' => 'Scale-up',
                'targetAmount' => '58000',
                'opportunityDescription' => 'Capital raise to deepen dispatch automation, deploy mobile proof-of-delivery tooling, and win three anchor distribution clients.',
                'riskScore' => 42,
                'riskLabel' => 'Modere',
                'deadlineOffsetDays' => 125,
                'entrepreneurIndex' => 1,
            ],
            [
                'title' => 'Nour Artisan Foods',
                'sector' => 'Food Processing',
                'description' => 'A premium packaged foods brand building export-ready Tunisian pantry products with strong branding, shelf stability, and hospitality partnerships.',
                'stage' => 'Expansion',
                'targetAmount' => '54000',
                'opportunityDescription' => 'Growth funding to expand production capacity, secure HACCP-aligned packaging upgrades, and accelerate GCC and EU distributor outreach.',
                'riskScore' => 36,
                'riskLabel' => 'Faible',
                'deadlineOffsetDays' => 110,
                'entrepreneurIndex' => 2,
            ],
        ];

        $opportunities = [];
        foreach ($projectBlueprints as $blueprint) {
            $entrepreneur = $entrepreneurs[$blueprint['entrepreneurIndex'] % count($entrepreneurs)];
            $project = $this->createProject($entrepreneur, $blueprint);
            $opportunity = $this->createOpportunity($project, $blueprint);
            $opportunities[] = $opportunity;
            $summary['projects']++;
            $summary['opportunities']++;
        }

        $offerScenarios = [
            [
                'opportunityIndex' => 0,
                'investorIndex' => 0,
                'amount' => '18000',
                'status' => InvestmentOffer::STATUS_PENDING,
                'paid' => false,
            ],
            [
                'opportunityIndex' => 0,
                'investorIndex' => 1,
                'amount' => '12000',
                'status' => InvestmentOffer::STATUS_REJECTED,
                'paid' => false,
            ],
            [
                'opportunityIndex' => 6,
                'investorIndex' => 0,
                'amount' => '26000',
                'status' => InvestmentOffer::STATUS_ACCEPTED,
                'paid' => false,
            ],
            [
                'opportunityIndex' => 1,
                'investorIndex' => 0,
                'amount' => '42000',
                'status' => InvestmentOffer::STATUS_ACCEPTED,
                'paid' => false,
                'contract' => [
                    'title' => 'Atlas SME Treasury Cloud - Strategic Seed Agreement',
                    'equity' => '9.50',
                    'consideration' => 'Minority equity participation with quarterly investor reporting and priority access to dashboard KPI exports.',
                    'milestonesText' => 'Initial release to 40 paying SMEs, bank export certification, and onboarding playbook delivery.',
                    'status' => InvestmentContract::STATUS_NEGOTIATING,
                    'messages' => [
                        ['sender' => 'investor', 'body' => 'I am aligned on the amount, but I need clearer quarterly reporting and churn visibility in the contract.'],
                        ['sender' => 'entrepreneur', 'body' => 'Agreed. I can add a monthly dashboard summary plus a board-style review every quarter.'],
                    ],
                ],
            ],
            [
                'opportunityIndex' => 7,
                'investorIndex' => 1,
                'amount' => '21000',
                'status' => InvestmentOffer::STATUS_ACCEPTED,
                'paid' => false,
                'contract' => [
                    'title' => 'Nour Artisan Foods - Signature Pending Agreement',
                    'equity' => '10.00',
                    'consideration' => 'Equity participation with a structured export-readiness roadmap and monthly commercial KPI updates.',
                    'milestonesText' => 'Packaging upgrade validation, distributor sampling, and first export purchase orders.',
                    'status' => InvestmentContract::STATUS_READY_TO_SIGN,
                    'signatures' => 'investor_only',
                    'messages' => [
                        ['sender' => 'entrepreneur', 'body' => 'The export packaging specification is final. I will countersign once the updated distributor clause is reflected.'],
                        ['sender' => 'investor', 'body' => 'I have signed my side. Please confirm once you are comfortable with the distributor protection wording.'],
                    ],
                ],
            ],
            [
                'opportunityIndex' => 6,
                'investorIndex' => 1,
                'amount' => '19500',
                'status' => InvestmentOffer::STATUS_ACCEPTED,
                'paid' => false,
                'contract' => [
                    'title' => 'Crescent Logistics OS - Founder Signed Draft',
                    'equity' => '8.75',
                    'consideration' => 'Equity participation with a delivery performance dashboard and monthly route economics review.',
                    'milestonesText' => 'Mobile rollout launch, first anchor distributor integration, and route margin optimization review.',
                    'status' => InvestmentContract::STATUS_READY_TO_SIGN,
                    'signatures' => 'entrepreneur_only',
                    'messages' => [
                        ['sender' => 'entrepreneur', 'body' => 'I signed the current draft because the commercial structure works for us. I am waiting for your confirmation on the KPI annex.'],
                        ['sender' => 'investor', 'body' => 'Understood. I want to review the route margin formula once more before adding my signature.'],
                    ],
                ],
            ],
            [
                'opportunityIndex' => 2,
                'investorIndex' => 0,
                'amount' => '25000',
                'status' => InvestmentOffer::STATUS_ACCEPTED,
                'paid' => false,
                'contract' => [
                    'title' => 'MedAssist Home Diagnostics - Ready to Fund Agreement',
                    'equity' => '11.00',
                    'consideration' => 'Equity participation plus strategic introductions to two private clinic groups.',
                    'milestonesText' => 'Certification submission, first two clinic rollouts, and launch of the home diagnostics support desk.',
                    'status' => InvestmentContract::STATUS_SIGNED,
                    'signatures' => 'both',
                    'messages' => [
                        ['sender' => 'investor', 'body' => 'The final version looks good to me. Once both signatures are in place, I will proceed to payment.'],
                        ['sender' => 'entrepreneur', 'body' => 'Confirmed. The revised compliance wording and onboarding calendar are now included.'],
                    ],
                ],
            ],
            [
                'opportunityIndex' => 3,
                'investorIndex' => 1,
                'amount' => '30000',
                'status' => InvestmentOffer::STATUS_REJECTED,
                'paid' => false,
            ],
            [
                'opportunityIndex' => 4,
                'investorIndex' => 0,
                'amount' => '55000',
                'status' => InvestmentOffer::STATUS_ACCEPTED,
                'paid' => true,
                'paidAtOffsetDays' => -12,
                'paymentIntentId' => 'pi_demo_solar_001',
                'contract' => [
                    'title' => 'Carthage Solar Microgrid - Executed Investment Contract',
                    'equity' => '14.00',
                    'consideration' => 'Equity participation, observer rights on monthly project reviews, and milestone-based capital release control.',
                    'milestonesText' => 'Procurement approved, site installation completed, and industrial client commissioning signed off.',
                    'status' => InvestmentContract::STATUS_FUNDED,
                    'signatures' => 'both',
                    'messages' => [
                        ['sender' => 'entrepreneur', 'body' => 'Procurement is locked in and the EPC partner is ready to start once the first release lands.'],
                        ['sender' => 'investor', 'body' => 'Good. Keep the milestone verification tied to site acceptance documents and commissioning evidence.'],
                    ],
                    'milestones' => [
                        ['label' => 'Equipment procurement', 'percentage' => '35.00', 'status' => ContractMilestone::STATUS_RELEASED, 'paymentIntentId' => 'pi_demo_solar_m1'],
                        ['label' => 'On-site installation', 'percentage' => '35.00', 'status' => ContractMilestone::STATUS_RELEASED, 'paymentIntentId' => 'pi_demo_solar_m2'],
                        ['label' => 'Commissioning and handover', 'percentage' => '30.00', 'status' => ContractMilestone::STATUS_RELEASED, 'paymentIntentId' => 'pi_demo_solar_m3'],
                    ],
                ],
            ],
            [
                'opportunityIndex' => 5,
                'investorIndex' => 0,
                'amount' => '22000',
                'status' => InvestmentOffer::STATUS_ACCEPTED,
                'paid' => true,
                'paidAtOffsetDays' => -5,
                'paymentIntentId' => 'pi_demo_edtech_001',
                'contract' => [
                    'title' => 'Maghreb Learning Studio - Active Portfolio Contract',
                    'equity' => '8.00',
                    'consideration' => 'Equity participation with milestone-based capital release and content usage reporting.',
                    'milestonesText' => 'Pilot contract delivery, subscription packaging, and first recurring B2B renewal cycle.',
                    'status' => InvestmentContract::STATUS_SIGNED,
                    'signatures' => 'both',
                    'messages' => [
                        ['sender' => 'entrepreneur', 'body' => 'We delivered the first client cohort and started packaging the recurring subscription offer.'],
                        ['sender' => 'investor', 'body' => 'Keep milestone two tied to signed renewals and not only content delivery.'],
                    ],
                    'milestones' => [
                        ['label' => 'Initial client cohort delivery', 'percentage' => '30.00', 'status' => ContractMilestone::STATUS_RELEASED, 'paymentIntentId' => 'pi_demo_edtech_m1'],
                        ['label' => 'Subscription packaging and renewal assets', 'percentage' => '20.00', 'status' => ContractMilestone::STATUS_COMPLETED],
                        ['label' => 'Sales collateral and renewal engine', 'percentage' => '25.00', 'status' => ContractMilestone::STATUS_CONFIRMED],
                        ['label' => 'First renewal cycle signed', 'percentage' => '25.00', 'status' => ContractMilestone::STATUS_PENDING],
                    ],
                ],
            ],
        ];

        foreach ($offerScenarios as $scenario) {
            $offer = $this->createOffer(
                $opportunities[$scenario['opportunityIndex']],
                $investors[$scenario['investorIndex']],
                $scenario
            );

            $summary['offers']++;
            if ($offer->isPaid()) {
                $summary['paidOffers']++;
            }

            if (isset($scenario['contract'])) {
                $this->createContract($offer, $scenario['contract']);
                $summary['contracts']++;
                $summary['milestones'] += count($scenario['contract']['milestones'] ?? []);
            }
        }

        $opportunities[3]->setStatus(InvestmentOpportunity::STATUS_CLOSED);
        $opportunities[4]->setStatus(InvestmentOpportunity::STATUS_FUNDED);
        $opportunities[5]->setStatus(InvestmentOpportunity::STATUS_FUNDED);

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createProject(User $entrepreneur, array $data): Projet
    {
        $project = new Projet();
        $project
            ->setUser($entrepreneur)
            ->setEntrepreneurId($entrepreneur->getId())
            ->setTitre($data['title'])
            ->setDescription($data['description'])
            ->setSecteur($data['sector'])
            ->setEtape($data['stage'])
            ->setStatut('ACTIF')
            ->setStatutProjet(Projet::STATUT_EVALUE)
            ->setScoreGlobal(86.0)
            ->setDiagnosticIa(self::SEED_MARKER . ' | Projet cree pour illustrer un dossier d\'investissement professionnel avec traction, planning et these de croissance clairs.');

        $this->entityManager->persist($project);

        return $project;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createOpportunity(Projet $project, array $data): InvestmentOpportunity
    {
        $opportunity = new InvestmentOpportunity();
        $opportunity
            ->setProject($project)
            ->setTargetAmount($data['targetAmount'])
            ->setDescription($data['opportunityDescription'])
            ->setDeadline(new \DateTime('+' . (int) $data['deadlineOffsetDays'] . ' days'))
            ->setStatus(InvestmentOpportunity::STATUS_OPEN)
            ->setRiskScore((float) $data['riskScore'])
            ->setRiskLabel($data['riskLabel']);

        $this->entityManager->persist($opportunity);

        return $opportunity;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createOffer(InvestmentOpportunity $opportunity, User $investor, array $data): InvestmentOffer
    {
        $offer = new InvestmentOffer();
        $offer
            ->setOpportunity($opportunity)
            ->setInvestor($investor)
            ->setProposedAmount($data['amount'])
            ->setStatus($data['status'])
            ->setPaid((bool) ($data['paid'] ?? false))
            ->setRiskAcknowledged((bool) ($data['riskAcknowledged'] ?? true));

        if (!empty($data['paymentIntentId'])) {
            $offer->setPaymentIntentId((string) $data['paymentIntentId']);
        }

        if ($offer->isPaid()) {
            $paidAt = new \DateTime();
            if (isset($data['paidAtOffsetDays'])) {
                $paidAt = $paidAt->modify((int) $data['paidAtOffsetDays'] . ' days');
            }
            $offer->setPaidAt($paidAt);
        }

        $this->entityManager->persist($offer);

        return $offer;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createContract(InvestmentOffer $offer, array $data): InvestmentContract
    {
        $contract = new InvestmentContract();
        $contract
            ->setOffer($offer)
            ->setInvestor($offer->getInvestor())
            ->setEntrepreneur($offer->getOpportunity()?->getProject()?->getUser())
            ->setTitle((string) $data['title'])
            ->setTerms($this->buildTerms($offer, $data))
            ->setEquityPercentage((string) $data['equity'])
            ->setConsideration((string) $data['consideration'])
            ->setMilestones((string) $data['milestonesText'])
            ->setStatus((string) ($data['status'] ?? InvestmentContract::STATUS_NEGOTIATING));

        $this->signatureService->refreshDigest($contract);
        $this->entityManager->persist($contract);

        foreach ($data['messages'] ?? [] as $messageData) {
            $sender = $messageData['sender'] === 'investor' ? $contract->getInvestor() : $contract->getEntrepreneur();
            $message = new InvestmentContractMessage();
            $message
                ->setContract($contract)
                ->setSender($sender)
                ->setBody((string) $messageData['body'])
                ->setSystemMessage(false);
            $contract->setLastMessageAt(new \DateTime());
            $this->entityManager->persist($message);
        }

        $signatureMode = $data['signatures'] ?? null;
        if ($signatureMode === 'both') {
            $signedAt = new \DateTime('-7 days');
            $contract
                ->setInvestorSignatureName($contract->getInvestor()?->getFullName())
                ->setInvestorSignatureHash(hash('sha256', 'demo-investor-' . $offer->getId()))
                ->setInvestorSignedAt($signedAt)
                ->setEntrepreneurSignatureName($contract->getEntrepreneur()?->getFullName())
                ->setEntrepreneurSignatureHash(hash('sha256', 'demo-entrepreneur-' . $offer->getId()))
                ->setEntrepreneurSignedAt((clone $signedAt)->modify('+1 day'));

            $contract->setStatus(($data['status'] ?? null) === InvestmentContract::STATUS_FUNDED ? InvestmentContract::STATUS_FUNDED : InvestmentContract::STATUS_SIGNED);
        } elseif ($signatureMode === 'investor_only') {
            $contract
                ->setInvestorSignatureName($contract->getInvestor()?->getFullName())
                ->setInvestorSignatureHash(hash('sha256', 'demo-investor-only-' . $offer->getId()))
                ->setInvestorSignedAt(new \DateTime('-3 days'))
                ->setStatus(InvestmentContract::STATUS_READY_TO_SIGN);
        } elseif ($signatureMode === 'entrepreneur_only') {
            $contract
                ->setEntrepreneurSignatureName($contract->getEntrepreneur()?->getFullName())
                ->setEntrepreneurSignatureHash(hash('sha256', 'demo-entrepreneur-only-' . $offer->getId()))
                ->setEntrepreneurSignedAt(new \DateTime('-3 days'))
                ->setStatus(InvestmentContract::STATUS_READY_TO_SIGN);
        }

        foreach ($data['milestones'] ?? [] as $position => $milestoneData) {
            $milestone = new ContractMilestone();
            $amount = round(((float) $offer->getProposedAmount()) * ((float) $milestoneData['percentage']) / 100, 2);

            $milestone
                ->setContract($contract)
                ->setLabel((string) $milestoneData['label'])
                ->setPercentage((string) $milestoneData['percentage'])
                ->setAmount(number_format($amount, 2, '.', ''))
                ->setStatus((string) $milestoneData['status'])
                ->setPosition($position);

            if (!empty($milestoneData['paymentIntentId'])) {
                $milestone->setPaymentIntentId((string) $milestoneData['paymentIntentId']);
            }

            switch ($milestoneData['status']) {
                case ContractMilestone::STATUS_RELEASED:
                    $milestone
                        ->setCompletedAt(new \DateTime('-4 days'))
                        ->setConfirmedAt(new \DateTime('-3 days'))
                        ->setReleasedAt(new \DateTime('-2 days'));
                    break;
                case ContractMilestone::STATUS_CONFIRMED:
                    $milestone
                        ->setCompletedAt(new \DateTime('-2 days'))
                        ->setConfirmedAt(new \DateTime('-1 day'));
                    break;
                case ContractMilestone::STATUS_COMPLETED:
                    $milestone->setCompletedAt(new \DateTime('-1 day'));
                    break;
            }

            $this->entityManager->persist($milestone);
        }

        return $contract;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildTerms(InvestmentOffer $offer, array $data): string
    {
        $projectName = $offer->getOpportunity()?->getProject()?->getTitre() ?? 'ce projet';
        $amount = number_format((float) $offer->getProposedAmount(), 0, ',', ' ');

        return implode("\n\n", [
            '1. Objet du contrat',
            'Le present accord encadre un investissement de ' . $amount . ' DT dans ' . $projectName . ' afin d\'accelerer l\'execution commerciale et operationnelle du projet.',
            '2. Participation et gouvernance',
            'L\'investisseur recevra une participation de ' . $data['equity'] . '% avec un reporting structure et un suivi des principaux indicateurs de performance.',
            '3. Contreparties',
            (string) $data['consideration'],
            '4. Jalons et execution',
            (string) $data['milestonesText'],
            '5. Discipline contractuelle',
            'Toute modification substantielle reinitialise le cycle de signature et impose une nouvelle validation bilaterale.',
        ]);
    }
}
