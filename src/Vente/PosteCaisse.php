<?php

namespace App\Vente;

use App\Entity\Caisse;
use App\Entity\Pharmacie;
use App\Entity\SessionCaisse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caisse à laquelle travaille ce poste (cet ordinateur). Avec une seule caisse, c'est toujours elle ; avec
 * plusieurs, le poste retient celle qu'on y a choisie dans un cookie, qui survit aux changements d'utilisateur.
 */
class PosteCaisse
{
    private const COOKIE = 'caisse_poste_';

    public function __construct(
        private readonly GestionCaisse $gestion,
        private readonly RequestStack $requetes,
    ) {
    }

    public function caisse(Pharmacie $pharmacie): Caisse
    {
        $caisses = $this->gestion->caisses($pharmacie);
        $choisie = (int) $this->requetes->getCurrentRequest()?->cookies->get(self::COOKIE.$pharmacie->getId());
        foreach ($caisses as $caisse) {
            if ($caisse->getId() === $choisie) {
                return $caisse;
            }
        }

        return $caisses[0];
    }

    /** Session ouverte de la caisse de ce poste : toute l'équipe y encaisse. */
    public function session(Pharmacie $pharmacie): ?SessionCaisse
    {
        return $this->gestion->sessionOuverte($this->caisse($pharmacie));
    }

    public function choisir(Response $reponse, Pharmacie $pharmacie, Caisse $caisse): Response
    {
        $reponse->headers->setCookie(Cookie::create(self::COOKIE.$pharmacie->getId(), (string) $caisse->getId(), new \DateTimeImmutable('+1 year')));

        return $reponse;
    }
}
