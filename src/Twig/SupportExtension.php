<?php

namespace App\Twig;

use App\Entity\SupportClient;
use App\Repository\SupportClientRepository;
use Twig\Attribute\AsTwigFunction;

/**
 * {{ support_client() }} : réglages du support, pour la barre du haut et la page d'accès suspendu.
 * {{ support_client_secours() }} : la même chose pour les pages d'erreur, qui doivent s'afficher même base en panne.
 */
final class SupportExtension
{
    public function __construct(private readonly SupportClientRepository $supports)
    {
    }

    #[AsTwigFunction('support_client')]
    public function supportClient(): SupportClient
    {
        return $this->supports->courant();
    }

    #[AsTwigFunction('support_client_secours')]
    public function supportClientSecours(): ?SupportClient
    {
        try {
            return $this->supports->courant();
        } catch (\Throwable) {
            return null;
        }
    }
}
