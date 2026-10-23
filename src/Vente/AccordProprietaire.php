<?php

namespace App\Vente;

use App\Entity\Notification;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Enum\TypeNotification;
use App\Repository\AffectationRepository;
use App\Repository\NotificationRepository;
use App\Service\AuditLogger;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accord du propriétaire à distance (VE-03, RE-03) : quand une vente demande sa confirmation (produit à ordonnance
 * vendu sans ordonnance, remise hors plafond), le vendeur la lui demande depuis la caisse et le propriétaire répond
 * depuis son propre compte (ordinateur ou téléphone), sans jamais communiquer son code PIN. L'accord ne vaut que pour
 * le panier tel qu'il était à la demande : toute modification oblige à redemander.
 */
class AccordProprietaire
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenteService $ventes,
        private readonly AffectationRepository $affectations,
        private readonly NotificationRepository $notifications,
        private readonly TenantContext $tenantContext,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * @return int nombre de propriétaires prévenus
     *
     * @throws VenteException
     */
    public function demander(Vente $vente, Utilisateur $vendeur, Pharmacie $pharmacie): int
    {
        $this->exigerPanier($vente);
        $controle = $this->ventes->controler($vente, $vendeur, $pharmacie);
        if ($controle->estBloquee()) {
            throw new VenteException($controle->blocages[0]);
        }
        if (!$controle->exigeAccord()) {
            throw new VenteException('Cette vente ne demande pas l\'accord du propriétaire.');
        }
        $proprietaires = $this->tenantContext->sansFiltre(fn () => $this->affectations->proprietaires($pharmacie));
        if ([] === $proprietaires) {
            throw new VenteException('Aucun propriétaire actif ne peut donner son accord.');
        }

        $maintenant = $this->horloge->now();
        $this->eteindre($vente);
        $vente->demanderAccord($vendeur, $maintenant);
        $message = \sprintf('%s demande votre accord : %s', $vendeur->getNom(), implode(' ', $controle->autorisations));
        $lien = $this->urls->generate('app_accord_voir', ['id' => $vente->getId()]);
        foreach ($proprietaires as $proprietaire) {
            $this->em->persist(new Notification($pharmacie, $proprietaire, TypeNotification::Accord, mb_substr($message, 0, 250), $lien, self::cle($vente, $maintenant), $maintenant));
        }
        $this->em->flush();

        return \count($proprietaires);
    }

    /**
     * @throws VenteException
     */
    public function repondre(Vente $vente, Utilisateur $proprietaire, bool $donne): void
    {
        if (!$proprietaire->isProprietaire()) {
            throw new VenteException('Seul le propriétaire donne son accord.');
        }
        $this->exigerPanier($vente);
        if (null === $vente->getAccordDemandeLe() || null !== $vente->getAccordReponduLe()) {
            throw new VenteException('Cette demande a déjà reçu une réponse.');
        }
        if (!$vente->panierInchange()) {
            throw new VenteException('Le panier a changé depuis la demande : le vendeur doit la renouveler.');
        }
        $vente->repondreAccord($donne, $proprietaire, $this->horloge->now());
        $this->eteindre($vente);
        $this->em->flush();
        $this->audit->journaliser($donne ? AuditLogger::VENTE_ACCORD_DONNE : AuditLogger::VENTE_ACCORD_REFUSE, $this->tenantContext->exigerPharmacie(), $vente, null, [
            'vendeur' => $vente->getAccordDemandePar()?->getNom(),
            'produits' => implode(', ', $vente->getLignes()->map(static fn ($l) => $l->getProduit()->getNomCommercial().' × '.$l->getQuantite())->toArray()),
        ]);
        $this->em->flush();
    }

    /** Les avis de la demande précédente n'ont plus lieu d'être. */
    private function eteindre(Vente $vente): void
    {
        $maintenant = $this->horloge->now();
        foreach ($this->tenantContext->sansFiltre(fn () => $this->notifications->createQueryBuilder('n')
            ->andWhere('n.cle LIKE :cle')->setParameter('cle', 'accord-vente-'.$vente->getId().'-%')
            ->andWhere('n.lueLe IS NULL')
            ->getQuery()->getResult()) as $notification) {
            $notification->marquerLue($maintenant);
        }
    }

    /**
     * @throws VenteException
     */
    private function exigerPanier(Vente $vente): void
    {
        if (!\in_array($vente->getStatut(), [StatutVente::EnCours, StatutVente::EnAttente], true)) {
            throw new VenteException('Cette vente est déjà validée ou abandonnée.');
        }
    }

    /** Une clé par demande : deux demandes dans la même seconde (panier modifié aussitôt) ne se confondent pas. */
    private static function cle(Vente $vente, \DateTimeImmutable $le): string
    {
        return 'accord-vente-'.$vente->getId().'-'.$le->format('YmdHis').'-'.bin2hex(random_bytes(4));
    }
}
