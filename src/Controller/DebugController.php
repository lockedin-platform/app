<?php
namespace App\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DebugController extends AbstractController
{
    #[Route('/debug-me', name: 'debug_me')]
    public function debug(): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return new Response('NOT LOGGED IN');
        }
        return new Response(
            'Email: ' . $user->getUserIdentifier() . '<br>' .
            'Roles: ' . implode(', ', $user->getRoles()) . '<br>' .
            'Role field: ' . ($user instanceof \App\Entity\User ? $user->getRole() : 'N/A')
        );
    }
}
