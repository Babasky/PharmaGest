<?php

namespace App\Tests\Unit\Vente;

use App\Entity\Client;
use App\Entity\OrganismeAmo;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\TypeRemise;
use App\Enum\TypeVente;
use PHPUnit\Framework\TestCase;

/**
 * Règles de calcul de la caisse : RG-01 (arrondi), RG-07 (part AMO), RG-08 (remise et plafond), RG-09 (remise AMO).
 */
final class CalculVenteTest extends TestCase
{
    public function testExempleAmoDuCahierDesChargesR05(): void
    {
        $vente = $this->vente();
        $vente->ajouterLigne($this->produit(9000, remboursable: true), 2);
        $vente->ajouterLigne($this->produit(2000, remboursable: false), 1);
        $vente->setType(TypeVente::Amo);
        $vente->setClient($this->client(privilegie: true));
        $vente->definirAmo((new OrganismeAmo())->setCode('INPS'), 'INPS-1', 70);
        $vente->definirRemise(TypeRemise::Pourcentage, 10);

        $totaux = $vente->calculer();

        self::assertSame(20000, $totaux->totalBrut);
        self::assertSame(18000, $totaux->baseAmo);
        self::assertSame(12600, $totaux->partAmo, 'Part AMO = 18 000 × 70 %.');
        self::assertSame(7400, $totaux->partAssureAvantRemise());
        self::assertSame(740, $totaux->remiseTotale(), 'La remise ne porte que sur la part assuré (RG-09).');
        self::assertSame(6660, $totaux->aEncaisser());
        self::assertSame(19260, $totaux->totalNet());
        self::assertEqualsWithDelta(10.0, $totaux->tauxRemiseMaximal, 0.001);
    }

    public function testPartAmoArrondieAuFrancLePlusProche(): void
    {
        $vente = $this->vente();
        $vente->ajouterLigne($this->produit(1235, remboursable: true), 1);
        $vente->setType(TypeVente::Amo);
        $vente->definirAmo((new OrganismeAmo())->setCode('CMSS'), 'C-1', 70);

        // 1 235 × 70 % = 864,5 → 865 (RG-01 : 0,5 arrondi au supérieur).
        self::assertSame(865, $vente->calculer()->partAmo);
        self::assertSame(370, $vente->calculer()->aEncaisser());
    }

    public function testRemisesParLigneEtSurLeTotal(): void
    {
        $vente = $this->vente();
        $vente->setClient($this->client(privilegie: true));
        $doliprane = $vente->ajouterLigne($this->produit(1505), 2);
        $vente->ajouterLigne($this->produit(4000), 1);
        $doliprane->definirRemise(TypeRemise::Pourcentage, 10);
        $vente->definirRemise(TypeRemise::Montant, 500);

        $totaux = $vente->calculer();

        self::assertSame(7010, $totaux->totalBrut);
        self::assertSame(301, $totaux->remiseLignes, '3 010 × 10 % = 301.');
        self::assertSame(500, $totaux->remiseGlobale);
        self::assertSame(6209, $totaux->aEncaisser());
        self::assertSame(0, $totaux->partAmo);
        self::assertEqualsWithDelta(801 * 100 / 7010, $totaux->tauxRemiseMaximal, 0.001, 'Taux le plus élevé : la remise totale, 801 sur 7 010 (11,4 %).');
    }

    public function testLesRemisesSeCumulentPourLeControleDuPlafond(): void
    {
        $vente = $this->vente();
        $vente->setClient($this->client(privilegie: true));
        $vente->ajouterLigne($this->produit(10000), 1)->definirRemise(TypeRemise::Pourcentage, 10);
        $vente->definirRemise(TypeRemise::Pourcentage, 10);

        $totaux = $vente->calculer();

        self::assertSame(1900, $totaux->remiseTotale(), '1 000 sur la ligne puis 900 sur le reste.');
        self::assertEqualsWithDelta(19.0, $totaux->tauxRemiseMaximal, 0.001, 'Deux remises de 10 % font 19 % au total : au-delà d\'un plafond de 10 %.');
    }

    public function testUneRemiseNeDepassePasLeMontant(): void
    {
        $vente = $this->vente();
        $vente->setClient($this->client(privilegie: true));
        $vente->ajouterLigne($this->produit(1500), 1);
        $vente->definirRemise(TypeRemise::Montant, 5000);

        self::assertSame(1500, $vente->calculer()->remiseGlobale);
        self::assertSame(0, $vente->calculer()->aEncaisser());
    }

    public function testUnClientNonPrivilegieSupprimeLesRemises(): void
    {
        $vente = $this->vente();
        $vente->setClient($this->client(privilegie: true));
        $ligne = $vente->ajouterLigne($this->produit(1000), 1);
        $ligne->definirRemise(TypeRemise::Pourcentage, 5);
        $vente->definirRemise(TypeRemise::Montant, 100);

        $vente->setClient($this->client(privilegie: false));

        self::assertFalse($vente->remiseAutorisee(), 'RE-01 : remise réservée aux clients privilégiés.');
        self::assertSame(0, $vente->calculer()->remiseTotale());
        self::assertNull($ligne->getRemiseType());
    }

    public function testUneVenteAmoNAcceptePasDeRemiseParLigne(): void
    {
        $vente = $this->vente();
        $vente->setClient($this->client(privilegie: true));
        $ligne = $vente->ajouterLigne($this->produit(1000, remboursable: true), 1);
        $ligne->definirRemise(TypeRemise::Pourcentage, 5);

        $vente->setType(TypeVente::Amo);

        self::assertSame(0, $ligne->calculerRemise());
        self::assertSame(0, $vente->calculer()->remiseLignes);
    }

    private function vente(): Vente
    {
        return new Vente((new Utilisateur())->setNom('Vendeur'), new \DateTimeImmutable());
    }

    private function produit(int $prix, bool $remboursable = false): Produit
    {
        return (new Produit())->setNomCommercial('Produit '.$prix)->setPrixVente($prix)->setRemboursableAmo($remboursable);
    }

    private function client(bool $privilegie): Client
    {
        return (new Client())->setNom('Client')->setPrivilegie($privilegie);
    }
}
