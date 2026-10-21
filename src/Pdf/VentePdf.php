<?php

namespace App\Pdf;

use App\Entity\Pharmacie;
use App\Entity\Vente;
use App\Service\ParametresPharmacie;
use App\Stockage\StockageFichiers;
use App\Vente\SyntheseSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents de la caisse : ticket 80 mm et facture A4 d'une vente (VE-06), rapport Z de clôture (FI-07).
 */
class VentePdf
{
    /** 80 mm en points PDF. */
    private const LARGEUR_TICKET = 226.77;

    public function __construct(
        private readonly GenerateurPdf $generateur,
        private readonly ParametresPharmacie $parametres,
        private readonly StockageFichiers $stockage,
    ) {
    }

    public function ticket(Vente $vente): string
    {
        // Le rouleau n'a pas de fin : la hauteur suit le contenu.
        $hauteur = 330 + 26 * $vente->getLignes()->count() + 14 * $vente->getPaiements()->count() + ($vente->getOrdonnance() ? 30 : 0) + ($vente->getPartAmo() > 0 ? 40 : 0);
        $mentions = $this->parametres->pour($this->pharmacie($vente))->getMentionsTicket();
        $hauteur += null === $mentions ? 0 : 12 * (1 + intdiv(mb_strlen($mentions), 38) + substr_count($mentions, "\n"));

        return $this->generateur->rendre('pdf/ticket.html.twig', $this->contexte($vente), [0, 0, self::LARGEUR_TICKET, (float) $hauteur]);
    }

    public function facture(Vente $vente): string
    {
        return $this->generateur->rendre('pdf/facture_vente.html.twig', $this->contexte($vente) + ['logo' => $this->logo($this->pharmacie($vente))]);
    }

    public function rapportZ(SyntheseSession $synthese): string
    {
        $session = $synthese->session;

        return $this->generateur->rendre('pdf/rapport_z.html.twig', [
            'synthese' => $synthese,
            'session' => $session,
            'pharmacie' => $session->getPharmacie(),
            'coupures' => \App\Entity\SessionCaisse::COUPURES,
        ]);
    }

    public function reponseTicket(Vente $vente): Response
    {
        return GenerateurPdf::reponse($this->ticket($vente), 'ticket-'.$vente->getNumero().'.pdf');
    }

    public function reponseFacture(Vente $vente): Response
    {
        return GenerateurPdf::reponse($this->facture($vente), 'facture-'.$vente->getNumero().'.pdf');
    }

    public function reponseRapportZ(SyntheseSession $synthese): Response
    {
        return GenerateurPdf::reponse($this->rapportZ($synthese), 'rapport-z-'.$synthese->session->getNumero().'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function contexte(Vente $vente): array
    {
        $pharmacie = $this->pharmacie($vente);

        return [
            'vente' => $vente,
            'pharmacie' => $pharmacie,
            'mentions' => $this->parametres->pour($pharmacie)->getMentionsTicket(),
        ];
    }

    private function pharmacie(Vente $vente): Pharmacie
    {
        return $vente->getPharmacie() ?? throw new \LogicException('Vente sans pharmacie.');
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
