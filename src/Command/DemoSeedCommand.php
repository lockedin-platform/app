<?php

namespace App\Command;

use App\Entity\Cours;
use App\Entity\DonneesBusiness;
use App\Entity\Event;
use App\Entity\EventParticipant;
use App\Entity\InvestmentOffer;
use App\Entity\InvestmentOpportunity;
use App\Entity\MentorAvailability;
use App\Entity\MentorshipRequest;
use App\Entity\Post;
use App\Entity\Projet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:demo:seed', description: 'Seed full demo data for bal de projets')]
class DemoSeedCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding demo data for LockedIn');

        // ── 1. Users ──────────────────────────────────────────────────────────
        $io->section('Creating demo users');

        $investor = $this->createUser('Rami',   'Karray',   'rami@najahni.tn',   'INVESTISSEUR', 'Demo1234');
        $mentor   = $this->createUser('Sarra',  'Mansour',  'sarra@najahni.tn',  'MENTOR',       'Demo1234');
        $karim    = $this->createUser('Karim',  'Ben Ali',  'karim@najahni.tn',  'ENTREPRENEUR', 'Demo1234');
        $mahdi    = $this->em->getRepository(User::class)->findOneBy(['email' => 'mahdibenmariem1@gmail.com']);

        $this->em->flush();
        $io->success('Users created: Rami (investor), Sarra (mentor), Karim (entrepreneur)');

        // ── 2. Projects ───────────────────────────────────────────────────────
        $io->section('Creating projects');

        $p1 = $this->createProject($mahdi,
            'PayLink — P2P Fintech',
            'A peer-to-peer payment platform enabling instant, low-cost transfers for Tunisian SMEs and freelancers, leveraging mobile money and QR codes.',
            'FinTech', 'MVP', 'EVALUE', 78.5,
            'Strong market fit in an underbanked segment. Regulatory path is clear. Team has 3 senior devs. Risk: competition from telecom operators. Recommendation: accelerate B2B sales.',
        );

        $db1 = new DonneesBusiness();
        $db1->setProjet($p1);
        $db1->setTailleMarche('$2.4B mobile payments market in Tunisia & Maghreb');
        $db1->setModeleRevenu('0.8% transaction fee + B2B SaaS subscription');
        $db1->setCoutsEstimes(120000);
        $db1->setRevenusAttendus(380000);
        $db1->setNiveauRisque('MEDIUM');
        $db1->setForceEquipe(4);
        $db1->setMargeEstimee(55.2);
        $db1->setRatioRentabilite(3.17);
        $db1->setScoreFinancier(82.0);
        $db1->setScoreMarche(79.0);
        $db1->setScoreEquipeCalcule(75.0);
        $db1->setScoreRisqueCalcule(68.0);
        $this->em->persist($db1);

        $p2 = $this->createProject($mahdi,
            'EduConnect TN',
            'An adaptive e-learning platform connecting Tunisian students with verified tutors, featuring AI-powered personalized learning paths and progress tracking.',
            'Education', 'Prototype', 'SOUMIS', 65.0,
            'Good traction in beta with 200 users. Market fragmented but addressable. Team needs a growth lead. Risk: low willingness to pay in K-12 segment.',
        );

        $db2 = new DonneesBusiness();
        $db2->setProjet($p2);
        $db2->setTailleMarche('$600M EdTech market in North Africa');
        $db2->setModeleRevenu('Freemium + monthly tutor subscription');
        $db2->setCoutsEstimes(75000);
        $db2->setRevenusAttendus(180000);
        $db2->setNiveauRisque('LOW');
        $db2->setForceEquipe(3);
        $db2->setMargeEstimee(42.0);
        $db2->setRatioRentabilite(2.4);
        $db2->setScoreFinancier(63.0);
        $db2->setScoreMarche(70.0);
        $db2->setScoreEquipeCalcule(60.0);
        $db2->setScoreRisqueCalcule(62.0);
        $this->em->persist($db2);

        $p3 = $this->createProject($mahdi,
            'GreenFarm — Smart Agriculture',
            'IoT sensor network for Tunisian olive farms to monitor soil moisture, optimize irrigation and reduce water waste by 40%.',
            'AgriTech', 'Idea', 'BROUILLON', null, null,
        );

        $p4 = $this->createProject($karim,
            'MedConsult AI',
            'Teleconsultation platform connecting Tunisian patients with doctors for remote diagnosis, powered by an AI triage assistant.',
            'HealthTech', 'MVP', 'EVALUE', 84.0,
            'High-growth sector. Regulatory approval pending but team is proactively engaging the Ministry of Health. Strong monetization via insurance partnerships.',
        );

        $db4 = new DonneesBusiness();
        $db4->setProjet($p4);
        $db4->setTailleMarche('$1.1B digital health market in MENA');
        $db4->setModeleRevenu('Per-consultation fee + B2B clinic dashboard');
        $db4->setCoutsEstimes(200000);
        $db4->setRevenusAttendus(650000);
        $db4->setNiveauRisque('MEDIUM');
        $db4->setForceEquipe(5);
        $db4->setMargeEstimee(61.0);
        $db4->setRatioRentabilite(3.25);
        $db4->setScoreFinancier(86.0);
        $db4->setScoreMarche(83.0);
        $db4->setScoreEquipeCalcule(85.0);
        $db4->setScoreRisqueCalcule(72.0);
        $this->em->persist($db4);

        $this->em->flush();
        $io->success('4 projects created with AI scores and business data');

        // ── 3. Investment Opportunities ───────────────────────────────────────
        $io->section('Creating investment opportunities');

        $opp1 = new InvestmentOpportunity();
        $opp1->setProject($p1);
        $opp1->setTargetAmount('80000');
        $opp1->setDescription('PayLink seeks 80,000 DT for a 15% equity stake. Funds will be used for regulatory compliance, team expansion (2 sales engineers) and mobile app launch. ROI expected in 24 months. 3 pilot B2B clients already signed LOIs.');
        $opp1->setDeadline(new \DateTime('+45 days'));
        $opp1->setStatus('OPEN');
        $opp1->setRiskScore(32.0);
        $opp1->setRiskLabel('Moderate Risk');
        $this->em->persist($opp1);

        $opp2 = new InvestmentOpportunity();
        $opp2->setProject($p4);
        $opp2->setTargetAmount('150000');
        $opp2->setDescription('MedConsult AI is raising 150,000 DT for 12% equity. Capital will fund WHO-grade data infrastructure, AI model training, and partnerships with 5 regional hospitals. Market size: $1.1B MENA digital health.');
        $opp2->setDeadline(new \DateTime('+60 days'));
        $opp2->setStatus('OPEN');
        $opp2->setRiskScore(28.0);
        $opp2->setRiskLabel('Low-Moderate Risk');
        $this->em->persist($opp2);

        $opp3 = new InvestmentOpportunity();
        $opp3->setProject($p2);
        $opp3->setTargetAmount('50000');
        $opp3->setDescription('EduConnect TN seeks 50,000 DT for 20% equity to hire a growth lead and launch paid marketing. 200 active beta users already. Platform ready for scale.');
        $opp3->setDeadline(new \DateTime('+30 days'));
        $opp3->setStatus('OPEN');
        $opp3->setRiskScore(22.0);
        $opp3->setRiskLabel('Low Risk');
        $this->em->persist($opp3);

        $this->em->flush();
        $io->success('3 investment opportunities created');

        // ── 4. Investment Offer (from Rami on PayLink) ────────────────────────
        $offer = new InvestmentOffer();
        $offer->setInvestor($investor);
        $offer->setOpportunity($opp1);
        $offer->setProposedAmount('50000');
        $offer->setStatus('ACCEPTED');
        $offer->setPaid(false);
        $offer->setRiskAcknowledged(true);
        $this->em->persist($offer);
        $this->em->flush();
        $io->success('Investment offer created (Rami → PayLink, 50,000 DT)');

        // ── 5. Mentor Availability ────────────────────────────────────────────
        $io->section('Creating mentor availability slots');

        $slots = [
            ['+1 day',  '09:00', '10:00'],
            ['+1 day',  '14:00', '15:30'],
            ['+3 days', '10:00', '11:00'],
            ['+3 days', '15:00', '16:00'],
            ['+7 days', '09:00', '10:30'],
        ];
        foreach ($slots as [$day, $start, $end]) {
            $av = new MentorAvailability();
            $av->setMentor($mentor);
            $av->setDate(new \DateTime($day));
            $av->setStartTime(new \DateTime($start));
            $av->setEndTime(new \DateTime($end));
            $this->em->persist($av);
        }
        $this->em->flush();
        $io->success('5 availability slots created for Sarra');

        // ── 6. Mentorship Request ─────────────────────────────────────────────
        $req = new MentorshipRequest();
        $req->setEntrepreneur($mahdi);
        $req->setMentor($mentor);
        $req->setMotivation('Hi Sarra, I\'d love your guidance on scaling my fintech startup PayLink. I need advice on fundraising strategy and B2B sales for our first enterprise clients.');
        $req->setStatus('ACCEPTED');
        $req->setGoals('Fundraising strategy & first enterprise clients');
        $this->em->persist($req);
        $this->em->flush();
        $io->success('Mentorship request created (Mahdi → Sarra)');

        // ── 7. Community Posts ────────────────────────────────────────────────
        $io->section('Creating community posts');

        $postData = [
            [$mahdi,    'Just submitted PayLink for AI evaluation — scored 78%! The AI identified our B2B sales strategy as the key differentiator. Who else is building fintech in Tunisia? 🚀'],
            [$karim,    'MedConsult AI just hit 500 beta sign-ups in 2 weeks! Huge validation for digital health in Tunisia. The AI triage feature alone reduced consultation wait times by 60%.'],
            [$mentor,   'Mentoring tip of the week: The biggest mistake early-stage founders make is building features before validating the problem. Talk to 100 customers before writing a single line of code.'],
            [$investor, 'LockedIn\'s investment pipeline is impressive. Reviewed 12 projects this month — 3 are genuinely fundable with solid teams and real traction. The platform makes due diligence so much faster.'],
            [$mahdi,    'Lesson learned: I rewrote our pitch deck 6 times. The version that worked had 10 slides, a clear problem, a live demo, and one slide on the team. Simplicity wins every time.'],
            [$karim,    'For anyone building in HealthTech — the key is getting one hospital as a reference client first. Everything else follows from there. Happy to share how we got ours.'],
        ];

        foreach ($postData as [$user, $content]) {
            $post = new Post();
            $post->setUser($user);
            $post->setContent($content);
            $this->em->persist($post);
        }
        $this->em->flush();
        $io->success('6 community posts created');

        // ── 8. Events ─────────────────────────────────────────────────────────
        $io->section('Creating upcoming events');

        $e1 = new Event();
        $e1->setTitle('Startup Weekend Tunis 2026');
        $e1->setDescription('54 hours to build your startup from scratch. Teams of 5, mentors from top tech companies, and 3 winners receive funding + acceleration. Open to all entrepreneurs, developers and designers.');
        $e1->setEventDate(new \DateTime('+18 days'));
        $e1->setCapacity(120);
        $e1->setCreatedBy($mentor);
        $this->em->persist($e1);

        $p1e1 = new EventParticipant();
        $p1e1->setEvent($e1);
        $p1e1->setUser($mahdi);
        $this->em->persist($p1e1);

        $p2e1 = new EventParticipant();
        $p2e1->setEvent($e1);
        $p2e1->setUser($karim);
        $this->em->persist($p2e1);

        $e2 = new Event();
        $e2->setTitle('Investor Pitch Day — LockedIn x BIAT');
        $e2->setDescription('5 selected LockedIn startups pitch live in front of 12 investors from BIAT, Flat6Labs and Wamda. Audience includes 200 ecosystem players. Apply by June 10 to be selected.');
        $e2->setEventDate(new \DateTime('+25 days'));
        $e2->setCapacity(200);
        $e2->setCreatedBy($investor);
        $this->em->persist($e2);

        $p1e2 = new EventParticipant();
        $p1e2->setEvent($e2);
        $p1e2->setUser($mahdi);
        $this->em->persist($p1e2);

        $e3 = new Event();
        $e3->setTitle('AI & FinTech Workshop — Esprit');
        $e3->setDescription('Half-day workshop on applying AI to payment systems and credit scoring. Speakers from Stripe, Orange Money and local fintechs. Hands-on demos with real payment APIs.');
        $e3->setEventDate(new \DateTime('+8 days'));
        $e3->setCapacity(80);
        $e3->setCreatedBy($mahdi);
        $this->em->persist($e3);

        $this->em->flush();
        $io->success('3 upcoming events created');

        // ── 9. Courses ────────────────────────────────────────────────────────
        $io->section('Creating courses');

        $courses = [
            ['Startup Fundamentals: From Idea to MVP', 'Master the lean startup methodology: customer discovery, problem validation, MVP building and first traction. Includes real Tunisian case studies.', 'Entrepreneurship', 'DEBUTANT', 150, 180],
            ['Fundraising for Early-Stage Startups',   'How to build your investor pitch, model your valuation, negotiate term sheets and close your seed round. Includes template pitch deck and financial model.', 'Finance', 'INTERMEDIAIRE', 200, 240],
            ['Product-Led Growth: Build Products Users Love', 'Growth frameworks used by top SaaS companies. Onboarding, activation, retention and viral loops. Real A/B test examples from African tech startups.', 'Product', 'INTERMEDIAIRE', 175, 200],
            ['AI for Business: Non-Technical Guide',   'Understand how to apply AI to your business without writing code. Covers ChatGPT APIs, prompt engineering, AI customer service and predictive analytics.', 'Technology', 'DEBUTANT', 120, 150],
            ['Digital Marketing in MENA',              'SEO, paid social, influencer marketing and community building tailored for Tunisian and North African markets. Real campaigns, real budgets, real results.', 'Marketing', 'DEBUTANT', 100, 120],
        ];

        foreach ($courses as [$titre, $desc, $cat, $niveau, $xp, $duree]) {
            $cours = new Cours();
            $cours->setTitre($titre);
            $cours->setDescription($desc);
            $cours->setCategorie($cat);
            $cours->setNiveauDifficulte($niveau);
            $cours->setPointsXp($xp);
            $cours->setDureeEstimee($duree);
            $cours->setCertification(true);
            $cours->setActif(true);
            $this->em->persist($cours);
        }
        $this->em->flush();
        $io->success('5 courses created');

        // ── Summary ───────────────────────────────────────────────────────────
        $io->success([
            'Demo data seeded successfully!',
            '',
            'Demo accounts (all password: Demo1234):',
            '  → rami@najahni.tn     (Investor)',
            '  → sarra@najahni.tn    (Mentor)',
            '  → karim@najahni.tn    (Entrepreneur)',
            '',
            'Your account: mahdibenmariem1@gmail.com (keep your own password)',
        ]);

        return Command::SUCCESS;
    }

    private function createUser(string $first, string $last, string $email, string $role, string $plain): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            return $existing;
        }
        $u = new User();
        $u->setFirstname($first);
        $u->setLastname($last);
        $u->setEmail($email);
        $u->setRole($role);
        $u->setPassword($this->hasher->hashPassword($u, $plain));
        $u->setVerified(true);
        $u->setIsActive(true);
        $u->setIsBanned(false);
        $u->setPhoneVerified(false);
        $u->setFaceRegistered(false);
        $u->setTotalXp(0);
        $this->em->persist($u);
        return $u;
    }

    private function createProject(
        User $user,
        string $titre,
        string $desc,
        string $secteur,
        string $etape,
        string $statut,
        ?float $score,
        ?string $diagnostic,
    ): Projet {
        $p = new Projet();
        $p->setUser($user);
        $p->setTitre($titre);
        $p->setDescription($desc);
        $p->setSecteur($secteur);
        $p->setEtape($etape);
        $p->setStatutProjet($statut);
        $p->setScoreGlobal($score);
        $p->setDiagnosticIa($diagnostic);
        $p->setDateCreation(new \DateTime('-' . rand(5, 30) . ' days'));
        if ($statut === 'SOUMIS' || $statut === 'EVALUE') {
            $p->setDateSoumission(new \DateTime('-' . rand(1, 5) . ' days'));
        }
        if ($statut === 'EVALUE') {
            $p->setDateEvaluation(new \DateTime('-1 day'));
        }
        $this->em->persist($p);
        return $p;
    }
}
