<?php

namespace App\Vente;

/**
 * Résultat des contrôles d'un panier avant l'encaissement : blocages (la vente ne peut pas passer) et
 * autorisations du propriétaire requises (code PIN : remise hors plafond, vente sans ordonnance).
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

    public function exigeCodePin(): bool
    {
        return [] !== $this->autorisations;
    }
}
