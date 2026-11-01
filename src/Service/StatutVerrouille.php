<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Relit en base, sous verrou (SELECT … FOR UPDATE), le statut d'un document juste avant de le faire changer d'état.
 *
 * Un statut vérifié avant la transaction a pu être lu avant qu'une requête concurrente (double envoi du formulaire,
 * deux onglets, deux utilisateurs) ne traite le même document : les deux requêtes passeraient alors le contrôle et
 * sortiraient (ou entreraient) le stock deux fois. Relu sous verrou dans la transaction, le statut sérialise les
 * deux requêtes : la seconde attend la fin de la première, voit le statut qu'elle a écrit et s'arrête.
 *
 * Le verrou est tenu jusqu'à la fin de la transaction : l'appelant doit faire le contrôle puis le changement d'état
 * dans la même transaction, et verrouiller le document AVANT le compteur de numérotation et les lots
 * (toujours le même ordre, pour ne pas créer d'interblocage).
 */
class StatutVerrouille
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Vrai si le statut du document en base est l'un des statuts attendus. Un document pas encore enregistré
     * (sans identifiant) n'a pas de concurrent : il est accepté.
     *
     * @param non-empty-list<\BackedEnum> $attendus
     */
    public function estParmi(object $document, array $attendus, string $champ = 'statut'): bool
    {
        $connexion = $this->em->getConnection();
        if (!$connexion->isTransactionActive()) {
            throw new \LogicException('Le statut se relit sous verrou dans une transaction.');
        }

        $meta = $this->em->getClassMetadata($document::class);
        $identifiant = $meta->getIdentifierValues($document);
        if ([] === $identifiant) {
            return true;
        }

        $sql = \sprintf(
            'SELECT %s FROM %s WHERE %s = ? FOR UPDATE',
            $connexion->quoteSingleIdentifier($meta->getColumnName($champ)),
            $connexion->quoteSingleIdentifier($meta->getTableName()),
            $connexion->quoteSingleIdentifier($meta->getSingleIdentifierColumnName()),
        );
        $valeur = $connexion->fetchOne($sql, [reset($identifiant)]);
        if (false === $valeur || null === $valeur) {
            return false;
        }

        foreach ($attendus as $statut) {
            if ((string) $statut->value === (string) $valeur) {
                return true;
            }
        }

        return false;
    }
}
