<?php

namespace App\Controller\Admin;

use App\Entity\Badge;
use App\Entity\Cours;
use App\Repository\BadgeRepository;
use App\Repository\CoursRepository;
use App\Repository\ProgressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/apprentissage')]
#[IsGranted('ROLE_ADMIN')]
class AdminApprentissageController extends AbstractController
{
    // ========== COURS ==========
    #[Route('/cours', name: 'admin_cours')]
    public function cours(Request $request, CoursRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('c')->orderBy('c.id', 'DESC');
        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);
        return $this->render('admin/apprentissage/cours.html.twig', ['pagination' => $pagination]);
    }

    #[Route('/cours/new', name: 'admin_cours_new', methods: ['GET', 'POST'])]
    public function newCours(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $cours = new Cours();
            $this->hydrateCours($cours, $request);
            $this->handleDocumentUpload($cours, $request);

            $errors = $validator->validate($cours);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('admin/apprentissage/cours_form.html.twig', ['cours' => $cours, 'fieldErrors' => $fieldErrors]);
            }

            $em->persist($cours);
            $em->flush();
            $this->addFlash('success', 'Cours créé !');
            return $this->redirectToRoute('admin_cours');
        }
        return $this->render('admin/apprentissage/cours_form.html.twig', ['cours' => null]);
    }

    #[Route('/cours/{id}/edit', name: 'admin_cours_edit', methods: ['GET', 'POST'])]
    public function editCours(Cours $cours, Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $this->hydrateCours($cours, $request);
            $this->handleDocumentUpload($cours, $request);

            $errors = $validator->validate($cours);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('admin/apprentissage/cours_form.html.twig', ['cours' => $cours, 'fieldErrors' => $fieldErrors]);
            }

            $em->flush();
            $this->addFlash('success', 'Cours modifié !');
            return $this->redirectToRoute('admin_cours');
        }
        return $this->render('admin/apprentissage/cours_form.html.twig', ['cours' => $cours]);
    }

    #[Route('/cours/{id}/delete', name: 'admin_cours_delete', methods: ['POST'])]
    public function deleteCours(Cours $cours, EntityManagerInterface $em): Response
    {
        // Supprimer le fichier physique si existant
        if ($cours->getDocumentPath()) {
            $filePath = $this->getParameter('cours_documents_directory') . '/' . $cours->getDocumentPath();
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $em->remove($cours);
        $em->flush();
        $this->addFlash('success', 'Cours supprimé.');
        return $this->redirectToRoute('admin_cours');
    }

    private function hydrateCours(Cours $c, Request $r): void
    {
        $c->setTitre($r->request->get('titre'));
        $c->setDescription($r->request->get('description'));
        $c->setCategorie($r->request->get('categorie'));
        $c->setNiveauDifficulte($r->request->get('niveau_difficulte', 'DEBUTANT'));
        $c->setPointsXp((int) $r->request->get('points_xp', 0));
        $c->setDureeEstimee((int) $r->request->get('duree_estimee', 0));
        $c->setCertification($r->request->getBoolean('certification'));
        $c->setActif($r->request->getBoolean('actif', true));
        $c->setVideoUrl($r->request->get('video_url'));
    }

    private function handleDocumentUpload(Cours $cours, Request $request): void
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('document_file');

        if (!$file) {
            return;
        }

        if (!$file->isValid()) {
            $this->addFlash('error', 'Le televersement du document a echoue.');
            return;
        }

        // Size cap (20 MB) — prevent disk-fill / DoS via huge uploads.
        if ($file->getSize() > 20 * 1024 * 1024) {
            $this->addFlash('error', 'Le document ne doit pas depasser 20 Mo.');
            return;
        }

        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
        ];

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            $this->addFlash('error', 'Format non supporté. Utilisez PDF, DOC, DOCX ou TXT.');
            return;
        }

        $uploadDir = $this->getParameter('cours_documents_directory');

        // Créer le dossier s'il n'existe pas
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Supprimer l'ancien fichier si existant
        if ($cours->getDocumentPath()) {
            $oldPath = $uploadDir . '/' . $cours->getDocumentPath();
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        $newFilename = uniqid('doc_') . '.' . $file->guessExtension();

        try {
            $file->move($uploadDir, $newFilename);
            $cours->setDocumentPath($newFilename);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'upload : ' . $e->getMessage());
        }
    }

    // ========== BADGES ==========
    #[Route('/badges', name: 'admin_badges')]
    public function badges(Request $request, BadgeRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('b')->orderBy('b.id', 'DESC');
        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);
        return $this->render('admin/apprentissage/badges.html.twig', ['pagination' => $pagination]);
    }

    #[Route('/badges/new', name: 'admin_badges_new', methods: ['GET', 'POST'])]
    public function newBadge(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $badge = new Badge();
            $this->hydrateBadge($badge, $request);

            $errors = $validator->validate($badge);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('admin/apprentissage/badge_form.html.twig', ['badge' => $badge, 'fieldErrors' => $fieldErrors]);
            }

            $em->persist($badge);
            $em->flush();
            $this->addFlash('success', 'Badge créé !');
            return $this->redirectToRoute('admin_badges');
        }
        return $this->render('admin/apprentissage/badge_form.html.twig', ['badge' => null]);
    }

    #[Route('/badges/{id}/edit', name: 'admin_badges_edit', methods: ['GET', 'POST'])]
    public function editBadge(Badge $badge, Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $this->hydrateBadge($badge, $request);

            $errors = $validator->validate($badge);
            if (count($errors) > 0) {
                $fieldErrors = [];
                foreach ($errors as $error) {
                    $field = $error->getPropertyPath();
                    if (!isset($fieldErrors[$field])) {
                        $fieldErrors[$field] = $error->getMessage();
                    }
                }
                return $this->render('admin/apprentissage/badge_form.html.twig', ['badge' => $badge, 'fieldErrors' => $fieldErrors]);
            }

            $em->flush();
            $this->addFlash('success', 'Badge modifié !');
            return $this->redirectToRoute('admin_badges');
        }
        return $this->render('admin/apprentissage/badge_form.html.twig', ['badge' => $badge]);
    }

    #[Route('/badges/{id}/delete', name: 'admin_badges_delete', methods: ['POST'])]
    public function deleteBadge(Badge $badge, EntityManagerInterface $em): Response
    {
        $em->remove($badge);
        $em->flush();
        $this->addFlash('success', 'Badge supprimé.');
        return $this->redirectToRoute('admin_badges');
    }

    private function hydrateBadge(Badge $b, Request $r): void
    {
        $b->setNom($r->request->get('nom'));
        $b->setDescription($r->request->get('description'));
        $b->setIcone($r->request->get('icone'));
        $b->setConditionObtention($r->request->get('condition_obtention'));
        $b->setPointsRequis((int) $r->request->get('points_requis', 0));
        $b->setCoursRequis((int) $r->request->get('cours_requis', 0));
        $b->setNiveauRequis((int) $r->request->get('niveau_requis', 0));
        $b->setCategorie($r->request->get('categorie'));
        $b->setRarete($r->request->get('rarete', 'COMMUN'));
        $b->setActif($r->request->getBoolean('actif', true));
    }

    // ========== PROGRESSIONS ==========
    #[Route('/progressions', name: 'admin_progressions')]
    public function progressions(Request $request, ProgressionRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('p')->orderBy('p.dateDebut', 'DESC');
        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);
        return $this->render('admin/apprentissage/progressions.html.twig', ['pagination' => $pagination]);
    }
}