<?php

namespace App\Vente;

/**
 * Résultat des contrôles d'un panier avant l'encaissement : blocages (la vente ne peut pas passer) et
 * accords du propriétaire requis (remise hors plafond, vente sans ordonnance), donnés depuis son compte.
 */
final class ControleVente
{
    /** @var list<string> */
    public array $blocages = [];

    /** @var list<string> */
    public array $autorisations = [];

    public bool $remiseHorsPlafond = false;

    /**
     * Produits « ordonnance obligatoire » vendus sans ordonnance avec l'accord du propriétaire (VE-03).
     *
     * @var list<string>
     */
    public array $sansOrdonnance = [];

    public function estBloquee(): bool
    {
        return [] !== $this->blocages;
    }

    public function exigeAccord(): bool
    {
        return [] !== $this->autorisations;
    }
}
