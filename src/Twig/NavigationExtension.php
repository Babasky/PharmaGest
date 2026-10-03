<?php

namespace App\Twig;

use App\Menu\Navigation;
use Twig\Attribute\AsTwigFunction;

final class NavigationExtension
{
    public function __construct(private readonly Navigation $navigation)
    {
    }

    /**
     * @return list<array{titre: string, elements: list<array{libelle: string, icone: string, route: string, disponible: bool, prefixe: string}>}>
     */
    #[AsTwigFunction('navigation')]
    public function navigation(): array
    {
        return $this->navigation->sections();
    }
}
