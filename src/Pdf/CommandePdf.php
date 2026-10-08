<?php

namespace App\Pdf;

use App\Entity\Commande;
use App\Entity\Pharmacie;
use App\Stockage\StockageFichiers;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bon de commande PDF (A4), disponible dès que la commande est passée : même contenu que l'Excel (CO-04).
 */
class CommandePdf
{
    public function __construct(
        private readonly GenerateurPdf $generateur,
        private readonly StockageFichiers $stockage,
    ) {
    }

    public function rendre(Commande $commande): string
    {
        if ($commande->estBrouillon()) {
            throw new \LogicException('Un brouillon de commande n\'a pas de bon de commande PDF.');
        }
        $pharmacie = $commande->getPharmacie() ?? throw new \LogicException('Commande sans pharmacie.');

        return $this->generateur->rendre('pdf/bon_commande.html.twig', [
            'commande' => $commande,
            'pharmacie' => $pharmacie,
            'logo' => $this->logo($pharmacie),
        ]);
    }

    public function reponse(Commande $commande): Response
    {
        return GenerateurPdf::reponse($this->rendre($commande), 'bon-de-commande-'.$commande->getNumero().'.pdf');
    }

    /** Logo en data URI : Dompdf ne charge aucune ressource distante. */
    private function logo(Pharmacie $pharmacie): ?string
    {
        $chemin = null === $pharmacie->getLogo() ? null : $this->stockage->chemin($pharmacie, 'logo', $pharmacie->getLogo());
        if (null === $chemin) {
            return null;
        }
        $type = str_ends_with($chemin, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$type.';base64,'.base64_encode((string) file_get_contents($chemin));
    }
}
