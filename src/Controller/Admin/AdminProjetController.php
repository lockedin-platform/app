<?php

namespace App\Controller\Admin;

use App\Entity\Projet;
use App\Repository\ProjetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/projets')]
#[IsGranted('ROLE_ADMIN')]
class AdminProjetController extends AbstractController
{
    #[Route('', name: 'admin_projets')]
    public function index(Request $request, ProjetRepository $repo, PaginatorInterface $paginator): Response
    {
        $search = $request->query->get('q', '');
        if ($search) {
            $qb = $repo->findBySearch($search);
            $qb->leftJoin('p.user', 'u')->addSelect('u');
        } else {
            $qb = $repo->createQueryBuilderWithUser()->orderBy('p.dateCreation', 'DESC');
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('admin/projet/index.html.twig', [
            'pagination' => $pagination,
            'search' => $search,
        ]);
    }

    #[Route('/{id}', name: 'admin_projets_show')]
    public function show(Projet $projet): Response
    {
        return $this->render('admin/projet/show.html.twig', ['projet' => $projet]);
    }

    #[Route('/{id}/delete', name: 'admin_projets_delete', methods: ['POST'])]
    public function delete(Projet $projet, EntityManagerInterface $em): Response
    {
        $em->remove($projet);
        $em->flush();
        $this->addFlash('success', 'Projet supprimé.');
        return $this->redirectToRoute('admin_projets');
    }

    #[Route('/{id}/evaluate', name: 'admin_projets_evaluate', methods: ['POST'])]
    public function evaluate(Projet $projet, Request $request, EntityManagerInterface $em): Response
    {
        $projet->setStatutProjet('EVALUE');
        $projet->setScoreGlobal((float) $request->request->get('score', 0));
        $projet->setDiagnosticIa($request->request->get('diagnostic'));
        $projet->setDateEvaluation(new \DateTime());
        $em->flush();
        $this->addFlash('success', 'Projet évalué avec succès.');
        return $this->redirectToRoute('admin_projets_show', ['id' => $projet->getId()]);
    }
}
