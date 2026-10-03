<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Import\DefinitionImport;
use App\Import\ServiceImport;
use App\Stockage\StockageFichiers;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import Excel / CSV des produits, fournisseurs et clients (RF-08), par le propriétaire ou l'adjoint.
 *
 * 1. Dépôt du fichier → analyse sans rien enregistrer, rapport ligne par ligne.
 * 2. Confirmation → import des lignes valides. Le fichier déposé est gardé entre les deux étapes
 *    dans le dossier privé de la pharmacie, puis supprimé.
 */
#[Route('/imports/{type}', requirements: ['type' => 'produits|fournisseurs|clients'])]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class ImportController extends AbstractAppController
{
    private const DOSSIER = 'imports';
    private const RETOURS = ['produits' => 'app_produit_index', 'fournisseurs' => 'app_fournisseur_index', 'clients' => 'app_client_index'];

    public function __construct(
        private readonly ServiceImport $imports,
        private readonly StockageFichiers $stockage,
    ) {
    }

    #[Route('', name: 'app_import', methods: ['GET', 'POST'])]
    public function deposer(string $type, Request $requete, ValidatorInterface $validateur): Response
    {
        $definition = $this->definition($type);
        $rapport = null;
        $fichier = null;
        $erreur = null;

        if ($requete->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import-'.$type, $requete->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            /** @var UploadedFile|null $depot */
            $depot = $requete->files->get('fichier');
            $violations = $validateur->validate($depot, [
                new Assert\NotNull(message: 'Choisissez un fichier.'),
                new Assert\File(
                    maxSize: '2M',
                    // Un CSV est souvent détecté comme « text/plain » : on l'accepte explicitement.
                    extensions: [
                        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
                        'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2'],
                        'csv' => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'],
                        'txt' => ['text/plain', 'text/csv'],
                    ],
                    extensionsMessage: 'Formats acceptés : Excel (.xlsx, .xls) ou CSV.',
                ),
            ]);
            if (\count($violations) > 0) {
                $erreur = (string) $violations->get(0)->getMessage();
            } else {
                \assert($depot instanceof UploadedFile);
                $fichier = $this->stockage->enregistrer($this->pharmacie(), self::DOSSIER, $depot);
                $rapport = $this->imports->analyser($definition, (string) $this->chemin($fichier));
            }
        }

        return $this->render('import/index.html.twig', [
            'definition' => $definition,
            'rapport' => $rapport,
            'fichier' => $fichier,
            'erreur' => $erreur,
            'retour' => self::RETOURS[$type],
        ], new Response(status: null !== $erreur ? 422 : 200));
    }

    #[Route('/confirmer', name: 'app_import_confirmer', methods: ['POST'])]
    #[IsCsrfTokenValid(new \Symfony\Component\ExpressionLanguage\Expression('"import-" ~ args["type"]'))]
    public function confirmer(string $type, Request $requete): Response
    {
        $definition = $this->definition($type);
        $fichier = $requete->request->getString('fichier');
        $chemin = $this->chemin($fichier);
        if (null === $chemin) {
            $this->addFlash('warning', 'Le fichier n\'est plus disponible : déposez-le de nouveau.');

            return $this->redirectToRoute('app_import', ['type' => $type]);
        }

        $rapport = $this->imports->importer($definition, $chemin);
        $this->stockage->supprimer($this->pharmacie(), self::DOSSIER, $fichier);

        $this->addFlash('success', \sprintf(
            'Import terminé : %d créé(s), %d mis à jour, %d ligne(s) ignorée(s) car en erreur.',
            $rapport->compter('creation'),
            $rapport->compter('mise_a_jour'),
            $rapport->compter('erreur'),
        ));

        return $this->redirectToRoute(self::RETOURS[$type]);
    }

    #[Route('/modele', name: 'app_import_modele', methods: ['GET'])]
    public function modele(string $type): Response
    {
        $classeur = $this->imports->modele($this->definition($type));

        $reponse = new StreamedResponse(static function () use ($classeur): void {
            (new Xlsx($classeur))->save('php://output');
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('modele-import-%s.xlsx', $type)));

        return $reponse;
    }

    private function definition(string $type): DefinitionImport
    {
        return $this->imports->definition($type) ?? throw $this->createNotFoundException();
    }

    private function chemin(string $fichier): ?string
    {
        return $this->stockage->chemin($this->pharmacie(), self::DOSSIER, $fichier);
    }
}
