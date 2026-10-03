<?php

namespace App\Tenant;

use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Repository\AffectationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Pharmacie courante de la requête et pilotage du filtre Doctrine {@see TenantFilter}.
 *
 * La pharmacie courante est choisie parmi les affectations actives de l'utilisateur ;
 * un propriétaire Premium en change via {@see self::basculer()} (mémorisé en session).
 */
class TenantContext implements ResetInterface
{
    private const CLE_SESSION = 'tenant_pharmacie_id';

    /** @var list<Pharmacie>|null */
    private ?array $pharmacies = null;
    /** Utilisateur pour lequel {@see self::$pharmacies} a été calculé. */
    private ?string $pharmaciesPour = null;
    private ?Pharmacie $forcee = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly AffectationRepository $affectations,
    ) {
    }

    public function getUtilisateur(): ?Utilisateur
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }

    /**
     * @return list<Pharmacie>
     */
    public function pharmaciesAccessibles(): array
    {
        $utilisateur = $this->getUtilisateur();
        if (null === $utilisateur || $utilisateur->isSuperAdmin() || !$utilisateur->isActif()) {
            return [];
        }

        if (null !== $this->pharmacies && $this->pharmaciesPour === $utilisateur->getUserIdentifier()) {
            return $this->pharmacies;
        }
        $this->pharmaciesPour = $utilisateur->getUserIdentifier();

        $affectations = $this->sansFiltre(fn () => $this->affectations->activesDe($utilisateur));

        return $this->pharmacies = array_map(static fn ($a) => $a->getPharmacie(), $affectations);
    }

    public function getPharmacie(): ?Pharmacie
    {
        if (null !== $this->forcee) {
            return $this->forcee;
        }

        $pharmacies = $this->pharmaciesAccessibles();
        if ([] === $pharmacies) {
            return null;
        }

        $idEnSession = $this->session()?->get(self::CLE_SESSION);
        foreach ($pharmacies as $pharmacie) {
            if ($pharmacie->getId() === $idEnSession) {
                return $pharmacie;
            }
        }

        return $pharmacies[0];
    }

    public function exigerPharmacie(): Pharmacie
    {
        return $this->getPharmacie() ?? throw new \LogicException('Aucune pharmacie courante.');
    }

    /**
     * Change de pharmacie courante. Refuse une pharmacie à laquelle l'utilisateur n'a pas accès.
     */
    public function basculer(int $pharmacieId): bool
    {
        foreach ($this->pharmaciesAccessibles() as $pharmacie) {
            if ($pharmacie->getId() === $pharmacieId) {
                $this->session()?->set(self::CLE_SESSION, $pharmacieId);
                $this->activerFiltre($pharmacie);

                return true;
            }
        }

        return false;
    }

    /**
     * Fixe la pharmacie courante hors requête HTTP (commandes, tâches planifiées, tests).
     */
    public function forcer(?Pharmacie $pharmacie): void
    {
        $this->forcee = $pharmacie;
        $this->activerFiltre($pharmacie);
    }

    /**
     * Active le filtre sur une pharmacie. Sans pharmacie, le filtre reste actif et ne renvoie rien.
     */
    public function activerFiltre(?Pharmacie $pharmacie): void
    {
        $filtres = $this->em->getFilters();

        if (null === $pharmacie?->getId()) {
            // Repartir d'un filtre neuf : aucun paramètre d'une pharmacie précédente ne doit subsister.
            if ($filtres->isEnabled(TenantFilter::NOM)) {
                $filtres->disable(TenantFilter::NOM);
            }
            $filtres->enable(TenantFilter::NOM);

            return;
        }

        $filtre = $filtres->isEnabled(TenantFilter::NOM) ? $filtres->getFilter(TenantFilter::NOM) : $filtres->enable(TenantFilter::NOM);
        $filtre->setParameter(TenantFilter::PARAMETRE, $pharmacie->getId(), 'integer');
    }

    public function desactiverFiltre(): void
    {
        if ($this->em->getFilters()->isEnabled(TenantFilter::NOM)) {
            $this->em->getFilters()->disable(TenantFilter::NOM);
        }
    }

    public function filtreActif(): bool
    {
        return $this->em->getFilters()->isEnabled(TenantFilter::NOM);
    }

    /**
     * Exécute un traitement sans filtre tenant, puis rétablit l'état précédent (paramètre compris).
     *
     * @template T
     *
     * @param callable(): T $traitement
     *
     * @return T
     */
    public function sansFiltre(callable $traitement): mixed
    {
        $filtres = $this->em->getFilters();
        if (!$filtres->isEnabled(TenantFilter::NOM)) {
            return $traitement();
        }

        $filtre = $filtres->getFilter(TenantFilter::NOM);
        $parametre = $filtre->hasParameter(TenantFilter::PARAMETRE) ? (int) trim($filtre->getParameter(TenantFilter::PARAMETRE), "'") : null;
        $filtres->disable(TenantFilter::NOM);

        try {
            return $traitement();
        } finally {
            $filtre = $filtres->enable(TenantFilter::NOM);
            if (null !== $parametre) {
                $filtre->setParameter(TenantFilter::PARAMETRE, $parametre, 'integer');
            }
        }
    }

    public function reset(): void
    {
        $this->pharmacies = null;
        $this->pharmaciesPour = null;
        $this->forcee = null;
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $requete = $this->requestStack->getMainRequest();

        return null !== $requete && $requete->hasSession() ? $requete->getSession() : null;
    }
}
