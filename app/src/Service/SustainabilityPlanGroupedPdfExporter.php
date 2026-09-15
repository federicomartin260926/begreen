<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class SustainabilityPlanGroupedPdfExporter
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generate(string $template, array $context): string
    {
        $fontCacheDir = $this->projectDir . '/var/cache/dompdf-fonts';
        if (
            !is_dir($fontCacheDir)
            && !@mkdir($fontCacheDir, 0775, true)
            && !is_dir($fontCacheDir)
        ) {
            throw new \RuntimeException(sprintf(
                'Unable to create Dompdf font cache directory "%s".',
                $fontCacheDir
            ));
        }

        if (!is_writable($fontCacheDir)) {
            throw new \RuntimeException(sprintf(
                'Dompdf font cache directory "%s" is not writable.',
                $fontCacheDir
            ));
        }

        $options = new Options();
        $options->set('defaultFont', 'Poppins');
        $options->set('fontDir', $fontCacheDir);
        $options->set('fontCache', $fontCacheDir);
        $options->setIsHtml5ParserEnabled(true);
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render($template, $context));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont(
            'Helvetica',
            'normal'
        );

        $canvas->page_text(
            $canvas->get_width() - 56,
            $canvas->get_height() - 24,
            '{PAGE_NUM} / {PAGE_COUNT}',
            $font,
            8,
            [0.34, 0.45, 0.41]
        );

        return $dompdf->output();
    }
}
