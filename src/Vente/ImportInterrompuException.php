<?php

namespace App\Vente;

/**
 * L'import d'une vente hors ligne a échoué au milieu de son enregistrement (stock pris entre-temps par une autre
 * vente…) : tout est annulé et le gestionnaire d'entités a été vidé, l'envoi en cours s'arrête là.
 */
final class ImportInterrompuException extends \RuntimeException
{
}
