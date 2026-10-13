<?php

namespace App\Twig;

use App\Entity\SupportClient;
use App\Repository\SupportClientRepository;
use Twig\Attribute\AsTwigFunction;

/**
 * {{ support_client() }} : réglages du support, pour la barre du haut et la page d'accès suspendu.
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
}
