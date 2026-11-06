<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Project;
use App\Entity\ProjectCompany;

/**
 * Assets de portada compartidos por PDF general y agrupado.
 * Extraídos sin alterar lógica de recorte, relaciones de aspecto ni gráfica.
 * Requiere pdfAssetDataUri() y getParameter() del controlador consumidor.
 */
trait PdfPlanCoverAssetsTrait
{
    private function pdfCommitmentChartDataUri(mixed $plannedCommitment): string
    {
        if (!is_array($plannedCommitment)) {
            return '';
        }

        $percentage = max(
            0.0,
            min(100.0, (float) ($plannedCommitment['percentageRounded'] ?? 0))
        );
        $points = (int) ($plannedCommitment['points'] ?? 0);
        $levelKey = (string) ($plannedCommitment['levelKey'] ?? 'seed');

        $stageIndexes = [
            'seed' => 0,
            'plant' => 1,
            'tree' => 2,
            'forest' => 3,
            'jungle' => 4,
        ];

        $stageIndex = $stageIndexes[$levelKey] ?? 0;
        $bandX = $stageIndex * 200;

        /*
         * Cada nivel ocupa una quinta parte del SVG.
         * Los anchos crecen progresivamente y las alturas se calculan
         * usando las proporciones reales de cada PNG.
         *
         * Todos terminan exactamente en baselineY.
         */
        $baselineY = 154.0;

        $images = [
            [
                'src' => $this->pdfAssetDataUri(
                    'public/images/commitment/commitment-brote.png',
                    'image/png'
                ),
                'naturalWidth' => 13,
                'naturalHeight' => 17,
                'width' => 18.0,
                'centerX' => 100.0,
                'baselineLift' => 0.0,
            ],
            [
                'src' => $this->pdfAssetDataUri(
                    'public/images/commitment/commitment-planta.png',
                    'image/png'
                ),
                'naturalWidth' => 58,
                'naturalHeight' => 49,
                'width' => 56.0,
                'centerX' => 300.0,
                'baselineLift' => 0.0,
            ],
            [
                'src' => $this->pdfAssetDataUri(
                    'public/images/commitment/commitment-arbol.png',
                    'image/png'
                ),
                'naturalWidth' => 134,
                'naturalHeight' => 82,
                'width' => 102.0,
                'centerX' => 500.0,
                'baselineLift' => 0.0,
            ],
            [
                'src' => $this->pdfAssetDataUri(
                    'public/images/commitment/commitment-bosque-v4.png',
                    'image/png'
                ),
                'naturalWidth' => 225,
                'naturalHeight' => 79,
                'width' => 154.0,
                'centerX' => 700.0,
                'baselineLift' => 0.0,
                'heightScale' => 1.28,
            ],
            [
                'src' => $this->pdfAssetDataUri(
                    'public/images/commitment/commitment-selva-v4.png',
                    'image/png'
                ),
                'naturalWidth' => 254,
                'naturalHeight' => 67,
                'width' => 196.0,
                'centerX' => 900.0,
                'baselineLift' => 0.0,
                'heightScale' => 1.40,
            ],
        ];

        $imageElements = '';

        foreach ($images as $image) {
            if ($image['src'] === '') {
                continue;
            }

            $width = (float) $image['width'];
            $height = $width
                * ((float) $image['naturalHeight'] / (float) $image['naturalWidth'])
                * (float) ($image['heightScale'] ?? 1.0);

            $x = (float) $image['centerX'] - ($width / 2);
            $y = $baselineY - $height - (float) ($image['baselineLift'] ?? 0);

            $imageElements .= sprintf(
                '<image href="%s" x="%.2f" y="%.2f" width="%.2f" height="%.2f"/>',
                $image['src'],
                $x,
                $y,
                $width,
                $height
            );
        }

        $markerX = $percentage * 10;
        $badgeCenterX = max(48.0, min(952.0, $markerX));
        $badgeX = $badgeCenterX - 44.0;
        $progressWidth = $markerX;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 168">'
            . '<rect x="%d" y="10" width="200" height="154" rx="20" fill="#e7f5e9"/>'
            . '%s'
            . '<rect x="0" y="154" width="1000" height="4" fill="#dfe9e4"/>'
            . '<rect x="0" y="154" width="%.2f" height="4" fill="#087154"/>'
            . '<line x1="%.2f" y1="78" x2="%.2f" y2="154" '
            . 'stroke="#66a994" stroke-width="1.5" stroke-dasharray="4 3"/>'
            . '<rect x="%.2f" y="48" width="88" height="25" rx="12.5" fill="#087154"/>'
            . '<text x="%.2f" y="65" text-anchor="middle" '
            . 'font-family="DejaVu Sans, sans-serif" font-size="13" '
            . 'font-weight="700" fill="#ffffff">%d pts</text>'
            . '<circle cx="%.2f" cy="156" r="8" fill="#e7f5e9" '
            . 'stroke="#087154" stroke-width="3"/>'
            . '</svg>',
            $bandX,
            $imageElements,
            $progressWidth,
            $badgeCenterX,
            $markerX,
            $badgeX,
            $badgeCenterX,
            $points,
            $markerX
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function pdfScoreRingDataUri(mixed $scorePct): string
    {
        $percentage = max(0.0, min(100.0, (float) $scorePct));
        $radius = 43.0;
        $circumference = 2 * M_PI * $radius;

        $progressLength = number_format(
            $circumference * ($percentage / 100),
            3,
            '.',
            ''
        );
        $circumferenceLength = number_format(
            $circumference,
            3,
            '.',
            ''
        );

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<circle cx="50" cy="50" r="43" fill="#ffffff" stroke="#dbe9e2" stroke-width="9"/>'
            . '<circle cx="50" cy="50" r="43" fill="none" stroke="#087154" stroke-width="9" '
            . 'stroke-linecap="round" stroke-dasharray="%s %s" transform="rotate(-90 50 50)"/>'
            . '</svg>',
            $progressLength,
            $circumferenceLength
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * @return list<array{name:string, src:string, widthMm:float, heightMm:float}>
     */
    private function buildPdfCompanyLogos(mixed $project): array
    {
        if (!$project instanceof Project || $project->getId() === null) {
            return [];
        }

        $logos = [];

        foreach ($project->getProjectCompanies() as $company) {
            if (!$company instanceof ProjectCompany || $company->getId() === null) {
                continue;
            }

            $logoPath = trim((string) $company->getLogoPath());
            if ($logoPath === '') {
                continue;
            }

            $expectedPrefix = sprintf(
                '/uploads/projects/%d/companies/%d/logo-',
                $project->getId(),
                $company->getId(),
            );

            if (
                !str_starts_with($logoPath, $expectedPrefix)
                || !preg_match(
                    '#^'.preg_quote($expectedPrefix, '#').'[a-f0-9]{32}\.(png|jpg|webp)$#D',
                    $logoPath,
                    $matches,
                )
            ) {
                continue;
            }

            $mimeType = match ($matches[1]) {
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'webp' => 'image/webp',
            };

            $asset = $this->buildPdfCompanyLogoAsset('public'.$logoPath, $mimeType);
            if ($asset === null) {
                continue;
            }

            $logos[] = [
                'name' => $company->getName(),
                'src' => $asset['src'],
                'ratio' => $asset['ratio'],
            ];
        }

        if ($logos === []) {
            return [];
        }

        // Keep every logo at the same height. Only reduce the whole row when
        // an unusually wide set of logos would exceed the safe cover width.
        $targetHeightMm = 7.0;
        $gapMm = 2.5;
        $maxRowWidthMm = 165.0;
        $ratioSum = array_sum(array_column($logos, 'ratio'));
        $gapWidthMm = $gapMm * max(0, count($logos) - 1);
        $availableLogoWidthMm = max(1.0, $maxRowWidthMm - $gapWidthMm);
        $heightMm = min($targetHeightMm, $availableLogoWidthMm / $ratioSum);

        return array_map(
            static fn (array $logo): array => [
                'name' => $logo['name'],
                'src' => $logo['src'],
                'widthMm' => round($logo['ratio'] * $heightMm, 2),
                'heightMm' => round($heightMm, 2),
            ],
            $logos,
        );
    }

    /**
     * @return array{src:string, ratio:float}|null
     */
    private function buildPdfCompanyLogoAsset(string $relativePath, string $mimeType): ?array
    {
        $path = $this->getParameter('kernel.project_dir').'/'.ltrim($relativePath, '/');
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            return null;
        }

        $size = @getimagesizefromstring($contents);
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }

        $original = [
            'src' => sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents)),
            'ratio' => $size[0] / $size[1],
        ];

        if (!function_exists('imagecreatefromstring') || !function_exists('imagecrop')) {
            return $original;
        }

        $image = @imagecreatefromstring($contents);
        if (!$image instanceof \GdImage) {
            return $original;
        }

        $cropped = $this->cropPdfCompanyLogoWhitespace($image);
        if (!$cropped instanceof \GdImage) {
            imagedestroy($image);

            return $original;
        }

        $width = imagesx($cropped);
        $height = imagesy($cropped);

        ob_start();
        imagesavealpha($cropped, true);
        $written = imagepng($cropped, null, 6);
        $normalizedContents = ob_get_clean();

        imagedestroy($cropped);
        imagedestroy($image);

        if (!$written || !is_string($normalizedContents) || $normalizedContents === '') {
            return $original;
        }

        return [
            'src' => 'data:image/png;base64,'.base64_encode($normalizedContents),
            'ratio' => $width / $height,
        ];
    }

    private function cropPdfCompanyLogoWhitespace(\GdImage $image): ?\GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 3 || $height < 3) {
            return null;
        }

        $transparentBackground = imagecolortransparent($image) >= 0;
        if (!$transparentBackground) {
            foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if (($color['alpha'] ?? 0) >= 120) {
                    $transparentBackground = true;
                    break;
                }
            }
        }

        $isBlank = function (int $x, int $y) use ($image, $transparentBackground): bool {
            $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $alpha = $color['alpha'] ?? 0;

            if ($alpha >= 120) {
                return true;
            }

            if ($transparentBackground) {
                return false;
            }

            return ($color['red'] ?? 0) >= 245
                && ($color['green'] ?? 0) >= 245
                && ($color['blue'] ?? 0) >= 245;
        };

        $top = 0;
        while ($top < $height) {
            $blank = true;
            for ($x = 0; $x < $width; ++$x) {
                if (!$isBlank($x, $top)) {
                    $blank = false;
                    break;
                }
            }

            if (!$blank) {
                break;
            }

            ++$top;
        }

        if ($top >= $height) {
            return null;
        }

        $bottom = $height - 1;
        while ($bottom > $top) {
            $blank = true;
            for ($x = 0; $x < $width; ++$x) {
                if (!$isBlank($x, $bottom)) {
                    $blank = false;
                    break;
                }
            }

            if (!$blank) {
                break;
            }

            --$bottom;
        }

        $left = 0;
        while ($left < $width) {
            $blank = true;
            for ($y = $top; $y <= $bottom; ++$y) {
                if (!$isBlank($left, $y)) {
                    $blank = false;
                    break;
                }
            }

            if (!$blank) {
                break;
            }

            ++$left;
        }

        $right = $width - 1;
        while ($right > $left) {
            $blank = true;
            for ($y = $top; $y <= $bottom; ++$y) {
                if (!$isBlank($right, $y)) {
                    $blank = false;
                    break;
                }
            }

            if (!$blank) {
                break;
            }

            --$right;
        }

        $leftMargin = $left;
        $rightMargin = $width - 1 - $right;
        $topMargin = $top;
        $bottomMargin = $height - 1 - $bottom;

        // Be conservative: trim only when the content is surrounded by an
        // unequivocal outer margin on all four sides.
        $minimumHorizontalMargin = max(1, (int) floor($width * 0.01));
        $minimumVerticalMargin = max(1, (int) floor($height * 0.01));

        if (
            $leftMargin < $minimumHorizontalMargin
            || $rightMargin < $minimumHorizontalMargin
            || $topMargin < $minimumVerticalMargin
            || $bottomMargin < $minimumVerticalMargin
        ) {
            return null;
        }

        // Preserve a tiny safety border for antialiasing.
        $paddingX = max(1, (int) round($width * 0.003));
        $paddingY = max(1, (int) round($height * 0.003));

        $cropX = max(0, $left - $paddingX);
        $cropY = max(0, $top - $paddingY);
        $cropRight = min($width - 1, $right + $paddingX);
        $cropBottom = min($height - 1, $bottom + $paddingY);

        $crop = imagecrop($image, [
            'x' => $cropX,
            'y' => $cropY,
            'width' => $cropRight - $cropX + 1,
            'height' => $cropBottom - $cropY + 1,
        ]);

        return $crop instanceof \GdImage ? $crop : null;
    }
}
