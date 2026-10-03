<?php

namespace App\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Rendu PDF (Dompdf) d'un template Twig. Aucune ressource distante n'est chargée.
 */
class GenerateurPdf
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.cache_dir%/dompdf')]
        private readonly string $dossierTemporaire,
    ) {
    }

    /**
     * @param array<string, mixed> $contexte
     */
    public function rendre(string $template, array $contexte, string $format = 'A4'): string
    {
        if (!is_dir($this->dossierTemporaire)) {
            mkdir($this->dossierTemporaire, 0775, true);
        }

        $options = (new Options())
            ->setIsRemoteEnabled(false)
            ->setDefaultFont('DejaVu Sans')
            ->setTempDir($this->dossierTemporaire)
            ->setFontCache($this->dossierTemporaire);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render($template, $contexte), 'UTF-8');
        $dompdf->setPaper($format);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public static function reponse(string $contenu, string $nomFichier): Response
    {
        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $nomFichier),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
