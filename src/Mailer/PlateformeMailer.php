<?php

namespace App\Mailer;

use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Service\EtatAbonnement;
use App\Service\LienCompte;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Emails de la plateforme (NO-02). Envoyés de façon asynchrone via Messenger.
 */
class PlateformeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LienCompte $liens,
    ) {
    }

    public function activation(Utilisateur $utilisateur, ?Pharmacie $pharmacie): void
    {
        $this->envoyer($utilisateur, 'Activez votre compte PharmaGest', 'email/activation.html.twig', [
            'pharmacie' => $pharmacie,
            'lien' => $this->liens->generer($utilisateur, LienCompte::ACTIVATION),
        ]);
    }

    public function reinitialisation(Utilisateur $utilisateur): void
    {
        $this->envoyer($utilisateur, 'Réinitialisation de votre mot de passe', 'email/reinitialisation.html.twig', [
            'lien' => $this->liens->generer($utilisateur, LienCompte::REINITIALISATION),
        ]);
    }

    public function rappelEcheance(Utilisateur $proprietaire, Pharmacie $pharmacie, EtatAbonnement $etat): void
    {
        $this->envoyer($proprietaire, \sprintf('Votre abonnement PharmaGest se termine dans %d jours', $etat->joursRestants), 'email/rappel_echeance.html.twig', [
            'pharmacie' => $pharmacie,
            'etat' => $etat,
        ]);
    }

    /**
     * Archivage dans 30 jours (12 mois après l'échéance sans renouvellement, § 3.2 étape 8).
     */
    public function annonceArchivage(Utilisateur $proprietaire, Pharmacie $pharmacie, \DateTimeImmutable $echeance, \DateTimeImmutable $archivage): void
    {
        $this->envoyer($proprietaire, \sprintf('%s sera archivée le %s', $pharmacie->getNom(), $archivage->format('d/m/Y')), 'email/annonce_archivage.html.twig', [
            'pharmacie' => $pharmacie,
            'echeance' => $echeance,
            'archivage' => $archivage,
        ]);
    }

    /**
     * Export complet envoyé au propriétaire au moment de l'archivage.
     */
    public function exportArchivage(Utilisateur $proprietaire, Pharmacie $pharmacie, string $cheminArchive, string $nomArchive): void
    {
        $this->envoyer($proprietaire, \sprintf('%s : export complet de vos données', $pharmacie->getNom()), 'email/export_archivage.html.twig', [
            'pharmacie' => $pharmacie,
        ], static fn (TemplatedEmail $email) => $email->attachFromPath($cheminArchive, $nomArchive, 'application/zip'));
    }

    /**
     * @param array<string, mixed>                   $contexte
     * @param (callable(TemplatedEmail): mixed)|null $completer
     */
    private function envoyer(Utilisateur $destinataire, string $sujet, string $template, array $contexte, ?callable $completer = null): void
    {
        $email = (new TemplatedEmail())
            ->to(new Address($destinataire->getEmail(), $destinataire->getNom()))
            ->subject($sujet)
            ->htmlTemplate($template)
            ->context(['utilisateur' => $destinataire, ...$contexte]);
        if (null !== $completer) {
            $completer($email);
        }
        $this->mailer->send($email);
    }
}
