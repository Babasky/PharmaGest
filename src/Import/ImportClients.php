<?php

namespace App\Import;

use App\Entity\Client;
use App\Entity\OrganismeAmo;
use App\Repository\ClientRepository;
use App\Repository\OrganismeAmoRepository;
use App\Util\Telephone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import des clients ; un client de même téléphone est mis à jour (deux homonymes restent distincts).
 */
final class ImportClients extends AbstractDefinitionImport
{
    public function __construct(
        EntityManagerInterface $em,
        ValidatorInterface $validateur,
        private readonly ClientRepository $clients,
        private readonly OrganismeAmoRepository $organismes,
    ) {
        parent::__construct($em, $validateur);
    }

    public function code(): string
    {
        return 'clients';
    }

    public function libelle(): string
    {
        return 'Clients';
    }

    public function colonnes(): array
    {
        return [
            new Colonne('nom', 'Nom', true, 'Nom et prénom.', 'Mariam Diallo'),
            new Colonne('telephone', 'Téléphone', false, 'Sert à reconnaître un client déjà présent.', '76 12 34 56'),
            new Colonne('privilegie', 'Privilégié', false, 'oui ou non. Seuls les clients privilégiés ont droit aux remises.', 'non'),
            new Colonne('organisme_amo', 'Organisme AMO', false, 'Code ou nom de l\'organisme (AMO ou autre assurance). Elle s\'ajoute aux assurances que le client a déjà ; les autres se saisissent sur sa fiche.', 'INPS'),
            new Colonne('numero_assure', 'N° d\'assuré', false, 'Obligatoire si un organisme est indiqué.', 'INPS-0045871'),
            new Colonne('entreprise', 'Entreprise', false, 'Entreprise ou mutuelle de rattachement.', ''),
        ];
    }

    public function exemples(): array
    {
        return ExemplesImport::clients();
    }

    public function preparer(int $numero, array $valeurs, bool $simulation): array
    {
        $erreurs = [];
        $nom = Valeurs::texte($valeurs, 'nom');
        if (null === $nom) {
            $erreurs[] = 'Le nom est obligatoire.';
        }
        $telephone = Valeurs::texte($valeurs, 'telephone');
        $normalise = null === $telephone ? null : Telephone::normaliser($telephone);
        if (null !== $normalise) {
            $this->dejaVu('tel:'.$normalise, $numero, 'Ce numéro de téléphone', $erreurs);
        }

        $client = (null !== $normalise ? $this->clients->findOneBy(['telephone' => $normalise]) : null) ?? new Client();
        $client->setNom((string) $nom)
            ->setTelephone($telephone)
            ->setPrivilegie(Valeurs::booleen($valeurs, 'privilegie', 'Privilégié', $erreurs))
            ->setEntreprise(Valeurs::texte($valeurs, 'entreprise'));

        // Une assurance par ligne : elle s'ajoute à celles que le client a déjà (ou met à jour son n° d'assuré).
        $numeroAssure = Valeurs::texte($valeurs, 'numero_assure');
        if (null !== ($organisme = Valeurs::texte($valeurs, 'organisme_amo'))) {
            $trouve = $this->organisme($organisme);
            if (null === $trouve) {
                $erreurs[] = \sprintf('Organisme AMO inconnu : « %s ».', $organisme);
            } elseif (null === $numeroAssure) {
                $erreurs[] = 'Le n° d\'assuré est obligatoire quand un organisme est indiqué.';
            } else {
                $client->definirAssurance($trouve, $numeroAssure);
            }
        } elseif (null !== $numeroAssure) {
            $erreurs[] = 'Indiquez l\'organisme de ce n° d\'assuré.';
        }

        $this->valider($client, $erreurs);

        return $this->resultat($numero, (string) $nom, $client, $erreurs, $simulation);
    }

    private function organisme(string $valeur): ?OrganismeAmo
    {
        foreach ($this->organismes->actifs() as $organisme) {
            if (0 === strcasecmp($organisme->getCode(), $valeur) || 0 === strcasecmp($organisme->getNom(), $valeur)) {
                return $organisme;
            }
        }

        return null;
    }
}
