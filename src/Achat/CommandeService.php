<?php

namespace App\Achat;

use App\Entity\Commande;
use App\Entity\EnvoiCommande;
use App\Entity\Fournisseur;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Enum\StatutCommande;
use App\Enum\StatutEnvoi;
use App\Service\AuditLogger;
use App\Service\Numeroteur;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Commandes fournisseurs (CO-01, CO-03, CO-05) : composition du brouillon, passage de la commande (numéro CMD),
 * envoi par email avec historique, annulation et abandon du reliquat.
 */
class CommandeService
{
    public const PREFIXE = 'CMD';
    private const PRIX_MAXIMAL = 100_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Numeroteur $numeroteur,
        private readonly EmailCommande $email,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Nouveau brouillon, vide ou avec des lignes (suggestions CO-02), au prix d'achat de référence des produits.
     *
     * @param list<array{0: Produit, 1: int}> $lignes produit et quantité
     *
     * @throws AchatException
     */
    public function creer(Fournisseur $fournisseur, array $lignes = []): Commande
    {
        if (!$fournisseur->isActif()) {
            throw new AchatException(\sprintf('Le fournisseur %s est archivé.', $fournisseur->getNom()));
        }
        $commande = new Commande($fournisseur, $this->tenantContext->getUtilisateur(), $this->horloge->now());
        foreach ($lignes as [$produit, $quantite]) {
            $this->ajouterSansEnregistrer($commande, $produit, $quantite);
        }
        $this->em->persist($commande);
        $this->em->flush();

        return $commande;
    }

    /**
     * Ajoute un produit au brouillon ; s'il y est déjà, sa quantité augmente.
     *
     * @throws AchatException
     */
    public function ajouter(Commande $commande, Produit $produit, int $quantite): LigneCommande
    {
        $this->exigerBrouillon($commande);
        $ligne = $this->ajouterSansEnregistrer($commande, $produit, $quantite);
        $this->em->flush();

        return $ligne;
    }

    /**
     * Met à jour les quantités (0 retire la ligne) et, si fournis, les prix estimés du brouillon.
     *
     * @param array<array-key, mixed>      $quantites par id de ligne
     * @param array<array-key, mixed>|null $prix      par id de ligne ; null pour ne pas les modifier (vendeur)
     *
     * @throws AchatException
     */
    public function modifierLignes(Commande $commande, array $quantites, ?array $prix): void
    {
        $this->exigerBrouillon($commande);
        $modifications = [];
        foreach ($commande->getLignes() as $ligne) {
            $id = (int) $ligne->getId();
            $nom = $ligne->getProduit()->getNomCommercial();
            $quantite = \array_key_exists($id, $quantites) ? self::entier($quantites[$id]) : $ligne->getQuantite();
            if (null === $quantite || $quantite > LigneCommande::QUANTITE_MAXIMALE) {
                throw new AchatException(\sprintf('%s : quantité invalide.', $nom));
            }
            $prixEstime = null !== $prix && \array_key_exists($id, $prix) ? self::entier($prix[$id]) : $ligne->getPrixEstime();
            if (null === $prixEstime || $prixEstime > self::PRIX_MAXIMAL) {
                throw new AchatException(\sprintf('%s : prix d\'achat estimé invalide.', $nom));
            }
            $modifications[] = [$ligne, $quantite, $prixEstime];
        }

        foreach ($modifications as [$ligne, $quantite, $prixEstime]) {
            if (0 === $quantite) {
                $commande->retirerLigne($ligne);
            } else {
                $ligne->modifier($quantite, $prixEstime);
            }
        }
        $this->em->flush();
    }

    /**
     * @throws AchatException
     */
    public function retirer(Commande $commande, LigneCommande $ligne): void
    {
        $this->exigerBrouillon($commande);
        if ($ligne->getCommande() !== $commande) {
            throw new AchatException('Cette ligne n\'appartient pas à la commande.');
        }
        $commande->retirerLigne($ligne);
        $this->em->flush();
    }

    /**
     * Un brouillon n'a pas encore de numéro : il peut être supprimé sans laisser de trou (RG-02).
     *
     * @throws AchatException
     */
    public function supprimer(Commande $commande): void
    {
        $this->exigerBrouillon($commande);
        $this->em->remove($commande);
        $this->em->flush();
    }

    /**
     * Commande passée sans email (téléphone, WhatsApp, bon imprimé) : numéro CMD et statut « envoyée ».
     *
     * @throws AchatException
     */
    public function passer(Commande $commande): void
    {
        $this->exigerPassable($commande);
        $this->transaction(function () use ($commande): void {
            $this->attribuerNumero($commande);
        });
    }

    /**
     * Envoi par email au fournisseur, Excel en pièce jointe (CO-05). Un brouillon est passé dans la même opération :
     * si l'email échoue, il reste brouillon et son numéro n'est pas consommé. Chaque tentative est historisée.
     *
     * @throws AchatException
     */
    public function envoyer(Commande $commande): EnvoiCommande
    {
        if ($commande->estBrouillon()) {
            $this->exigerPassable($commande);
        } elseif (!$commande->estReceptionnable()) {
            throw new AchatException(\sprintf('La commande %s est %s : elle ne s\'envoie plus.', $commande->getLibelle(), mb_strtolower($commande->getStatut()->libelle())));
        }
        $destinataire = $commande->getFournisseur()->getEmail();
        if (null === $destinataire) {
            throw new AchatException(\sprintf('Le fournisseur %s n\'a pas d\'adresse email : complétez sa fiche, ou passez la commande sans email et transmettez l\'Excel autrement.', $commande->getFournisseur()->getNom()));
        }
        $utilisateur = $this->tenantContext->getUtilisateur();

        try {
            return $this->transaction(function () use ($commande, $destinataire, $utilisateur): EnvoiCommande {
                if ($commande->estBrouillon()) {
                    $this->attribuerNumero($commande);
                }
                $this->email->envoyer($commande, $utilisateur);
                $envoi = new EnvoiCommande($commande, $this->horloge->now(), $destinataire, StatutEnvoi::Envoye, $utilisateur);
                $this->em->persist($envoi);
                $this->em->flush();

                return $envoi;
            });
        } catch (TransportExceptionInterface $e) {
            // La transaction est annulée : on remet la commande dans son état d'avant, puis on garde la trace de l'échec.
            $this->em->refresh($commande);
            $this->em->persist(new EnvoiCommande($commande, $this->horloge->now(), $destinataire, StatutEnvoi::Echec, $utilisateur, mb_substr($e->getMessage(), 0, 255)));
            $this->em->flush();

            throw new AchatException(\sprintf('L\'email n\'a pas pu être envoyé à %s : %s La commande n\'a pas changé ; réessayez plus tard ou passez-la sans email.', $destinataire, rtrim($e->getMessage(), '.').'.'), previous: $e);
        }
    }

    /**
     * Annulation d'une commande passée dont rien n'a encore été reçu. Tracée (AU-01).
     *
     * @throws AchatException
     */
    public function annuler(Commande $commande, string $motif): void
    {
        if (StatutCommande::Envoyee !== $commande->getStatut()) {
            throw new AchatException(match ($commande->getStatut()) {
                StatutCommande::Brouillon => 'Un brouillon se supprime : il n\'a pas besoin d\'être annulé.', StatutCommande::RecuePartiellement => 'Une partie de la commande est déjà reçue : abandonnez plutôt le reliquat.', default => \sprintf('La commande %s est %s : elle ne peut plus être annulée.', $commande->getLibelle(), mb_strtolower($commande->getStatut()->libelle())),
            });
        }
        $this->cloturer($commande, StatutCommande::Annulee, $motif, AuditLogger::COMMANDE_ANNULEE);
    }

    /**
     * Le fournisseur ne livrera pas le reste : la commande reçue en partie est close (statut « reçue »)
     * et ses quantités manquantes ne sont plus attendues. Tracé (AU-01).
     *
     * @throws AchatException
     */
    public function solder(Commande $commande, string $motif): void
    {
        if (StatutCommande::RecuePartiellement !== $commande->getStatut()) {
            throw new AchatException('Seule une commande reçue en partie peut être soldée.');
        }
        $this->cloturer($commande, StatutCommande::Recue, $motif, AuditLogger::COMMANDE_SOLDEE);
    }

    private function cloturer(Commande $commande, StatutCommande $statut, string $motif, string $action): void
    {
        $motif = trim($motif);
        if ('' === $motif) {
            throw new AchatException('Le motif est obligatoire.');
        }
        $motif = mb_substr($motif, 0, 255);
        $manquant = array_sum($commande->getLignes()->map(static fn (LigneCommande $l) => $l->getReste())->toArray());
        $commande->cloturer($statut, $motif, $this->horloge->now());
        $this->em->flush();
        $this->audit->journaliser($action, $commande->getPharmacie(), $commande, null, [
            'commande' => $commande->getNumero(),
            'fournisseur' => $commande->getFournisseur()->getNom(),
            'unites_non_livrees' => $manquant,
            'motif' => $motif,
        ]);
        $this->em->flush();
    }

    private function attribuerNumero(Commande $commande): void
    {
        $numero = $this->numeroteur->suivant(self::PREFIXE, $this->tenantContext->exigerPharmacie());
        $commande->passer($numero, $this->horloge->now(), $this->tenantContext->getUtilisateur());
        $this->em->flush();
    }

    private function ajouterSansEnregistrer(Commande $commande, Produit $produit, int $quantite): LigneCommande
    {
        if (!$produit->isActif()) {
            throw new AchatException(\sprintf('Le produit %s est archivé.', $produit->getNomCommercial()));
        }
        if ($produit->getPharmacie() !== $commande->getFournisseur()->getPharmacie()) {
            throw new AchatException('Produit inconnu.');
        }
        $ligne = $commande->ligneDe($produit);
        $total = $quantite + ($ligne?->getQuantite() ?? 0);
        if ($quantite <= 0 || $total > LigneCommande::QUANTITE_MAXIMALE) {
            throw new AchatException(\sprintf('%s : la quantité doit être comprise entre 1 et %d.', $produit->getNomCommercial(), LigneCommande::QUANTITE_MAXIMALE));
        }
        if (null !== $ligne) {
            $ligne->modifier($total, $ligne->getPrixEstime());

            return $ligne;
        }

        return $commande->ajouterLigne($produit, $quantite, (int) $produit->getPrixAchat());
    }

    private function exigerBrouillon(Commande $commande): void
    {
        if (!$commande->estBrouillon()) {
            throw new AchatException(\sprintf('La commande %s est passée : elle n\'est plus modifiable.', $commande->getLibelle()));
        }
    }

    private function exigerPassable(Commande $commande): void
    {
        $this->exigerBrouillon($commande);
        if ($commande->getLignes()->isEmpty()) {
            throw new AchatException('Ajoutez au moins un produit avant de passer la commande.');
        }
        if (!$commande->getFournisseur()->isActif()) {
            throw new AchatException(\sprintf('Le fournisseur %s est archivé.', $commande->getFournisseur()->getNom()));
        }
    }

    /**
     * Transaction qui laisse le gestionnaire ouvert en cas d'échec (voir {@see \App\Stock\StockService::transaction()}).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $resultat = $operation();
            $connexion->commit();

            return $resultat;
        } catch (\Throwable $e) {
            $connexion->rollBack();
            throw $e;
        }
    }

    /** Entier positif ou nul saisi librement (« 1 500 »), sinon null. */
    public static function entier(mixed $valeur): ?int
    {
        $valeur = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim(\is_scalar($valeur) ? (string) $valeur : ''));
        if (1 !== preg_match('/^\d{1,10}$/', $valeur)) {
            return null;
        }

        return (int) $valeur;
    }
}
