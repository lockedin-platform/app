<?php

namespace App\Controller;

use App\Entity\LoginHistory;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class FaceAuthController extends AbstractController
{
    private const MATCH_THRESHOLD = 0.6;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {}

    /**
     * Register face descriptor for the currently logged-in user.
     * Expects JSON body: { "descriptor": [128 floats] }
     */
    #[Route('/api/face-register', name: 'api_face_register', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['descriptor']) || !is_array($data['descriptor'])) {
            return $this->json(['success' => false, 'message' => 'Descriptor manquant.'], 400);
        }

        $descriptor = $data['descriptor'];
        if (count($descriptor) !== 128) {
            return $this->json(['success' => false, 'message' => 'Descriptor invalide (128 valeurs attendues).'], 400);
        }

        // Validate all values are numeric
        foreach ($descriptor as $val) {
            if (!is_numeric($val)) {
                return $this->json(['success' => false, 'message' => 'Descriptor contient des valeurs non numériques.'], 400);
            }
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->setFaceEncoding(json_encode(array_map('floatval', $descriptor)));
        $user->setFaceRegistered(true);
        $this->em->flush();

        return $this->json(['success' => true, 'message' => 'Visage enregistré avec succès !']);
    }

    /**
     * Remove face registration for the currently logged-in user.
     */
    #[Route('/api/face-unregister', name: 'api_face_unregister', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unregister(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $user->setFaceEncoding(null);
        $user->setFaceRegistered(false);
        $this->em->flush();

        return $this->json(['success' => true, 'message' => 'Face ID supprimé.']);
    }

    /**
     * Login via face descriptor.
     * Expects JSON body: { "descriptor": [128 floats] }
     * Compares against all registered face encodings using Euclidean distance.
     */
    #[Route('/api/face-login', name: 'api_face_login', methods: ['POST'])]
    public function faceLogin(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['descriptor']) || !is_array($data['descriptor'])) {
            return $this->json(['success' => false, 'message' => 'Descriptor manquant.'], 400);
        }

        $descriptor = $data['descriptor'];
        if (count($descriptor) !== 128) {
            return $this->json(['success' => false, 'message' => 'Descriptor invalide.'], 400);
        }

        $inputDescriptor = array_map('floatval', $descriptor);

        // Load all users with face encodings
        $users = $this->userRepository->findFaceRegisteredUsers();

        $bestMatch = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($users as $user) {
            $stored = json_decode($user->getFaceEncoding(), true);
            if (!$stored || count($stored) !== 128) {
                continue;
            }

            $distance = $this->euclideanDistance($inputDescriptor, $stored);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $user;
            }
        }

        if (!$bestMatch || $bestDistance >= self::MATCH_THRESHOLD) {
            return $this->json([
                'success' => false,
                'message' => 'Visage non reconnu. Assurez-vous d\'avoir enregistré votre visage dans votre profil.',
            ]);
        }

        // Check user status
        if ($bestMatch->getIsBanned()) {
            return $this->json(['success' => false, 'message' => 'Ce compte a été banni.']);
        }
        if (!$bestMatch->getIsActive()) {
            return $this->json(['success' => false, 'message' => 'Ce compte est désactivé.']);
        }

        // Programmatically log in the matched user
        $token = new UsernamePasswordToken($bestMatch, 'main', $bestMatch->getRoles());
        $this->container->get('security.token_storage')->setToken($token);
        $request->getSession()->set('_security_main', serialize($token));

        // Reset login attempts
        $bestMatch->resetLoginAttempts();
        $this->em->flush();

        // Record login history
        $this->recordLogin($bestMatch, $request);

        $redirect = in_array('ROLE_ADMIN', $bestMatch->getRoles()) ? '/admin' : '/';

        return $this->json([
            'success' => true,
            'message' => 'Bienvenue, ' . $bestMatch->getFirstname() . ' !',
            'redirect' => $redirect,
        ]);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $diff = ($a[$i] ?? 0) - ($b[$i] ?? 0);
            $sum += $diff * $diff;
        }
        return sqrt($sum);
    }

    private function recordLogin(User $user, Request $request): void
    {
        $history = new LoginHistory();
        $history->setUser($user);
        $history->setIpAddress($request->getClientIp());
        $history->setDeviceInfo($request->headers->get('User-Agent', 'Unknown'));
        $history->setLoginMethod('FACE_ID');
        $history->setSuccess(true);
        $history->setLocation('Web');
        $this->em->persist($history);
        $this->em->flush();
    }
}
