<?php

namespace App\Service;

use App\Entity\Projet;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class ProjetExportService
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function exportProjectPdf(Projet $projet): string
    {
        $html = $this->twig->render('front/projet/export_pdf.html.twig', [
            'projet' => $projet,
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @param Projet[] $projets */
    public function exportProjectCsv(array $projets): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'ID', 'Titre', 'Secteur', 'Étape', 'Statut', 'Score Global',
            'Taille Marché', 'Coûts Estimés', 'Revenus Attendus', 'Marge',
            'Niveau Risque', 'Force Équipe', 'Date Création',
        ], ';');

        foreach ($projets as $projet) {
            $db = $projet->getDonneesBusiness();
            fputcsv($handle, [
                $projet->getId(),
                $projet->getTitre(),
                $projet->getSecteur(),
                $projet->getEtape(),
                $projet->getStatutProjet(),
                $projet->getScoreGlobal(),
                $db?->getTailleMarche(),
                $db?->getCoutsEstimes(),
                $db?->getRevenusAttendus(),
                $db?->getMargeEstimee(),
                $db?->getNiveauRisque(),
                $db?->getForceEquipe(),
                $projet->getDateCreation()?->format('d/m/Y'),
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
