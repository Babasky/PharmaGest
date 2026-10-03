<?php

namespace App\Service;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Liens d'activation de compte et de réinitialisation du mot de passe.
 *
 * Le lien est signé (impossible à fabriquer), expire (72 h pour une activation, 1 h pour une
 * réinitialisation) et devient invalide dès que le mot de passe change (usage unique).
 */
class LienCompte
{
    public const ACTIVATION = 'activation';
    public const REINITIALISATION = 'reinitialisation';

    private const DUREES = [
        self::ACTIVATION => 'PT72H',
        self::REINITIALISATION => 'PT1H',
    ];

    public function __construct(
        private readonly UriSigner $signataire,
        private readonly UrlGeneratorInterface $routeur,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function generer(Utilisateur $utilisateur, string $usage): string
    {
        $url = $this->routeur->generate('app_compte_mot_de_passe', [
            'usage' => $usage,
            'id' => $utilisateur->getId(),
            'empreinte' => $utilisateur->getEmpreinteMotDePasse(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signataire->sign($url, $this->horloge->now()->add(new \DateInterval(self::DUREES[$usage])));
    }

    /**
     * Renvoie l'utilisateur visé par le lien, ou une explication si le lien n'est plus valable.
     */
    public function verifier(Request $requete): Utilisateur|string
    {
        try {
            $this->signataire->verify($requete);
        } catch (ExpiredSignedUriException) {
            return 'Ce lien a expiré. Demandez-en un nouveau.';
        } catch (SignedUriException) {
            return 'Ce lien est invalide.';
        }

        $utilisateur = $this->utilisateurs->find($requete->attributes->getInt('id'));
        if (null === $utilisateur || $utilisateur->getEmpreinteMotDePasse() !== $requete->attributes->get('empreinte')) {
            return 'Ce lien a déjà été utilisé. Demandez-en un nouveau si besoin.';
        }
        if (!$utilisateur->isActif()) {
            return 'Ce compte est désactivé. Contactez le responsable de votre pharmacie.';
        }

        return $utilisateur;
    }
}
