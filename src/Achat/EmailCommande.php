<?php

namespace App\Achat;

use App\Entity\Commande;
use App\Entity\Utilisateur;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;

/**
 * Email du bon de commande au fournisseur, avec l'Excel en pièce jointe (CO-05, NO-02).
 *
 * Envoyé tout de suite, sans file d'attente : la pharmacie sait immédiatement si le fournisseur l'a reçu,
 * et l'historique des envois enregistre le résultat réel.
 */
class EmailCommande
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ExportCommandeExcel $export,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function envoyer(Commande $commande, ?Utilisateur $expediteur): void
    {
        $pharmacie = $commande->getPharmacie() ?? throw new \LogicException('Commande sans pharmacie.');
        $fournisseur = $commande->getFournisseur();
        $destinataire = $fournisseur->getEmail() ?? throw new \LogicException('Fournisseur sans email.');

        $email = (new TemplatedEmail())
            ->to(new Address($destinataire, $fournisseur->getNom()))
            ->subject(\sprintf('Bon de commande %s — %s', $commande->getNumero(), $pharmacie->getNom()))
            ->htmlTemplate('email/commande_fournisseur.html.twig')
            ->context(['commande' => $commande, 'pharmacie' => $pharmacie, 'expediteur' => $expediteur])
            ->attach($this->export->contenu($commande), $this->export->nomFichier($commande), ExportCommandeExcel::TYPE_MIME);

        // Le fournisseur répond à la pharmacie, pas à l'adresse technique de la plateforme.
        $reponse = $pharmacie->getEmail() ?? $expediteur?->getEmail();
        if (null !== $reponse) {
            $email->replyTo(new Address($reponse, $pharmacie->getNom()));
        }

        $this->transport->send($email);
    }
}
