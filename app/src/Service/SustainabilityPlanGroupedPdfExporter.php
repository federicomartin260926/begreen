<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final class SustainabilityPlanGroupedPdfExporter
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generate(string $template, array $context): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
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
