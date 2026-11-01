<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require dirname(__DIR__).'/vendor/autoload.php';

if ($argc < 5 || $argc > 6) {
    fwrite(STDERR, "Uso: php bin/generate-user-manual.php <composicion.md> <raiz-manuales> <salida.pdf> <perfil> [edicion]\n");
    exit(2);
}

[$script, $compositionArgument, $rootArgument, $outputFile, $profile] = $argv;
$edition = $argv[5] ?? 'Edición 2 · Noviembre de 2026';
$manualRoot = realpath($rootArgument);
$compositionFile = realpath($compositionArgument);

if (false === $manualRoot || false === $compositionFile || !str_starts_with($compositionFile, $manualRoot.'/')) {
    fwrite(STDERR, "La composición y la raíz de manuales deben existir y pertenecer al mismo árbol.\n");
    exit(2);
}

/** @return string Absolute path restricted to the manuals tree. */
function manualResourcePath(string $manualRoot, string $relativePath, array $extensions): string
{
    if (str_contains($relativePath, "\0") || str_starts_with($relativePath, '/') || preg_match('#(^|/)\.\.(/|$)#', $relativePath)) {
        throw new RuntimeException('Ruta de recurso no válida.');
    }

    $path = realpath($manualRoot.'/'.ltrim($relativePath, './'));
    $extension = false === $path ? '' : strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (false === $path || !str_starts_with($path, $manualRoot.'/') || !in_array($extension, $extensions, true)) {
        throw new RuntimeException('Recurso fuera del árbol permitido o con formato no admitido.');
    }

    return $path;
}

$includedFiles = [];
$expand = function (string $file) use (&$expand, &$includedFiles, $manualRoot): string {
    if (isset($includedFiles[$file])) {
        throw new RuntimeException('Inclusión Markdown circular o duplicada.');
    }
    $includedFiles[$file] = true;
    $content = file_get_contents($file);
    if (false === $content) {
        throw new RuntimeException('No se pudo leer una fuente Markdown.');
    }

    return preg_replace_callback(
        '/^<!--\s*include:\s*([^>]+?)\s*-->$/m',
        static function (array $matches) use (&$expand, $manualRoot): string {
            $included = manualResourcePath($manualRoot, trim($matches[1]), ['md']);

            return "\n".$expand($included)."\n";
        },
        $content,
    ) ?? throw new RuntimeException('No se pudo componer el manual.');
};

$markdown = $expand($compositionFile);
$lines = preg_split('/\R/', $markdown) ?: [];
$headings = [];
$slugCounts = [];
foreach ($lines as $line) {
    if (!preg_match('/^(#{1,3})\s+(.+)$/', trim($line), $matches)) {
        continue;
    }
    $rawLabel = trim($matches[2]);
    $explicitToc = str_ends_with($rawLabel, ' {toc}');
    $label = $explicitToc ? substr($rawLabel, 0, -6) : $rawLabel;
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $ascii), '-')) ?: 'seccion';
    $slugCounts[$slug] = ($slugCounts[$slug] ?? 0) + 1;
    $level = strlen($matches[1]);
    $headings[] = [
        'level' => $level,
        'label' => $label,
        'id' => $slug.'-'.$slugCounts[$slug],
        'toc' => 1 === $level || (2 === $level && $explicitToc),
    ];
}

$inline = static function (string $text) use ($manualRoot): string {
    $imageTokens = [];
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', static function (array $matches) use (&$imageTokens, $manualRoot): string {
        $path = manualResourcePath($manualRoot, trim($matches[2]), ['png', 'jpg', 'jpeg']);
        $mime = mime_content_type($path);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new RuntimeException('Tipo MIME de captura no admitido.');
        }
        $token = '@@IMAGE_'.count($imageTokens).'@@';
        $imageTokens[$token] = sprintf(
            '<figure><img src="data:%s;base64,%s" alt="%s"><figcaption>%s</figcaption></figure>',
            $mime,
            base64_encode((string) file_get_contents($path)),
            htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );

        return $token;
    }, $text) ?? $text;

    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;

    return str_replace(array_keys($imageTokens), array_values($imageTokens), $escaped);
};

$buildBody = static function (bool $withMarkers) use ($lines, $headings, $inline): string {
    $html = '';
    $paragraph = [];
    $listType = null;
    $headingIndex = 0;
    $flushParagraph = static function () use (&$html, &$paragraph, $inline): void {
        if ([] !== $paragraph) {
            $html .= '<p>'.$inline(implode(' ', $paragraph)).'</p>';
            $paragraph = [];
        }
    };
    $closeList = static function () use (&$html, &$listType): void {
        if (null !== $listType) {
            $html .= '</'.$listType.'>';
            $listType = null;
        }
    };

    for ($index = 0, $count = count($lines); $index < $count; ++$index) {
        $trimmed = trim($lines[$index]);
        if ('<!-- pagebreak -->' === $trimmed) {
            $flushParagraph();
            $closeList();
            $html .= '<div class="page-break"></div>';
            continue;
        }
        if ('' === $trimmed || str_starts_with($trimmed, '<!--')) {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed)) {
            $flushParagraph();
            $closeList();
            $heading = $headings[$headingIndex++];
            $marker = $withMarkers ? '<span class="page-marker">[[BGMF:'.$heading['id'].']]</span>' : '';
            $html .= sprintf('<h%d id="%s">%s%s</h%d>', $heading['level'], $heading['id'], $marker, $inline($heading['label']), $heading['level']);
            continue;
        }
        if (str_contains($trimmed, '|') && isset($lines[$index + 1]) && preg_match('/^\s*\|?\s*:?-{3,}/', trim($lines[$index + 1]))) {
            $flushParagraph();
            $closeList();
            $headers = array_map('trim', explode('|', trim($trimmed, '| ')));
            $html .= '<table><thead><tr>';
            foreach ($headers as $header) {
                $html .= '<th>'.$inline($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';
            ++$index;
            while (isset($lines[$index + 1]) && str_contains(trim($lines[$index + 1]), '|') && '' !== trim($lines[$index + 1])) {
                $cells = array_map('trim', explode('|', trim($lines[++$index], '| ')));
                $html .= '<tr>';
                foreach ($headers as $cellIndex => $unused) {
                    $html .= '<td>'.$inline($cells[$cellIndex] ?? '').'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches) || preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $wantedType = preg_match('/^\d+\./', $trimmed) ? 'ol' : 'ul';
            if ($listType !== $wantedType) {
                $closeList();
                $listType = $wantedType;
                $html .= '<'.$listType.'>';
            }
            $html .= '<li>'.$inline($matches[1]).'</li>';
            continue;
        }
        $paragraph[] = $trimmed;
    }
    $flushParagraph();
    $closeList();

    return $html;
};

$render = static function (array $pageMap, bool $withMarkers) use ($headings, $inline, $buildBody, $profile, $edition): string {
    $toc = '<section class="toc"><h1>Índice</h1><table class="toc-table">';
    foreach ($headings as $heading) {
        if (!$heading['toc']) {
            continue;
        }
        $page = $pageMap[$heading['id']] ?? '000';
        $toc .= sprintf(
            '<tr class="level-%d"><td class="toc-label"><a href="#%s">%s</a></td><td class="toc-page">%s</td></tr>',
            $heading['level'],
            $heading['id'],
            $inline($heading['label']),
            $page,
        );
    }
    $toc .= '</table></section>';

    return '<!doctype html><html lang="es"><head><meta charset="UTF-8"><style>
        @page { margin: 20mm 17mm 19mm; }
        body { color: #26352f; font-family: "DejaVu Sans", sans-serif; font-size: 9.8pt; line-height: 1.42; }
        .cover { page-break-after: always; padding-top: 48mm; text-align: center; }
        .cover .brand { color: #18764b; font-size: 14pt; font-weight: bold; letter-spacing: 1px; }
        .cover h1 { border: 0; color: #124e36; font-size: 29pt; margin: 18mm 0 5mm; page-break-before: auto; }
        .cover h2 { color: #4b6259; font-size: 18pt; font-weight: normal; }
        .cover .edition { color: #687a73; margin-top: 31mm; }
        .toc { page-break-after: always; }
        .toc h1 { page-break-before: auto; margin-bottom: 4mm; }
        .toc-table { border-collapse: collapse; width: 100%; }
        .toc-table td { border: 0; border-bottom: 1px dotted #c4d2cc; padding: 1.1mm 0; vertical-align: bottom; }
        .toc-table .level-2 .toc-label { padding-left: 6mm; font-size: 8.7pt; }
        .toc-label a { color: #26352f; text-decoration: none; }
        .toc-page { text-align: right; width: 12mm; }
        h1 { border-bottom: 2px solid #65ad86; color: #145d3e; font-size: 20pt; margin: 9mm 0 6mm; page-break-after: avoid; padding-bottom: 2.5mm; }
        h2 { color: #18764b; font-size: 14pt; margin: 7mm 0 2.5mm; page-break-after: avoid; }
        h3 { color: #316b52; font-size: 11pt; margin: 5mm 0 2mm; page-break-after: avoid; }
        p { margin: 0 0 3.4mm; orphans: 3; widows: 3; }
        li { margin-bottom: 1.5mm; }
        code { background: #edf4f0; font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; padding: 0.3mm 0.8mm; }
        table { border-collapse: collapse; font-size: 8.5pt; margin: 4mm 0 6mm; width: 100%; }
        th, td { border: 1px solid #c8d5cf; padding: 1.8mm; text-align: left; vertical-align: top; }
        th { background: #e7f2ec; color: #145d3e; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        figure { margin: 5mm auto; page-break-inside: avoid; text-align: center; }
        figure img { height: auto; max-height: 205mm; max-width: 100%; }
        figcaption { color: #687a73; font-size: 8pt; margin-top: 2mm; }
        .page-marker { color: #fff; font-size: 1px; position: absolute; }
        .page-break { page-break-before: always; }
        .footer { bottom: -12mm; color: #718078; font-size: 7.5pt; left: 0; position: fixed; right: 0; text-align: center; }
        .footer .page:after { content: counter(page); }
    </style></head><body>
    <div class="footer">Be Green My Friend · Manual '.$inline($profile).' · Página <span class="page"></span></div>
    <section class="cover"><div class="brand">BE GREEN MY FRIEND</div><h1>Manual de usuario</h1><h2>'.$inline($profile).'</h2><p class="edition">'.$inline($edition).'</p></section>'
    .$toc.$buildBody($withMarkers).'</body></html>';
};

$renderPdf = static function (string $html): string {
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setIsHtml5ParserEnabled(true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
};

$extractPageMap = static function (string $pdf, array $headings): array {
    $temporaryPdf = tempnam(sys_get_temp_dir(), 'manual-toc-');
    $temporaryText = tempnam(sys_get_temp_dir(), 'manual-toc-text-');
    if (false === $temporaryPdf || false === $temporaryText) {
        throw new RuntimeException('No se pudieron crear archivos temporales para calcular el índice.');
    }
    file_put_contents($temporaryPdf, $pdf);
    $command = sprintf('pdftotext %s %s 2>&1', escapeshellarg($temporaryPdf), escapeshellarg($temporaryText));
    exec($command, $commandOutput, $exitCode);
    if (0 !== $exitCode) {
        @unlink($temporaryPdf);
        @unlink($temporaryText);
        throw new RuntimeException('pdftotext no está disponible para calcular la paginación del índice.');
    }
    $pages = explode("\f", (string) file_get_contents($temporaryText));
    @unlink($temporaryPdf);
    @unlink($temporaryText);
    $map = [];
    foreach ($headings as $heading) {
        foreach ($pages as $pageIndex => $pageText) {
            if (str_contains($pageText, '[[BGMF:'.$heading['id'].']]')) {
                $map[$heading['id']] = $pageIndex + 1;
                break;
            }
        }
        if (!isset($map[$heading['id']])) {
            throw new RuntimeException('No se pudo localizar una sección al calcular el índice: '.$heading['label']);
        }
    }

    return $map;
};

$pageMap = [];
$stable = false;
for ($pass = 1; $pass <= 4; ++$pass) {
    $candidateMap = $extractPageMap($renderPdf($render($pageMap, true)), $headings);
    if ([] !== $pageMap && $candidateMap === $pageMap) {
        $stable = true;
        break;
    }
    $pageMap = $candidateMap;
}
if (!$stable) {
    throw new RuntimeException('La paginación del índice no se estabilizó tras cuatro pasadas.');
}

$outputDirectory = dirname($outputFile);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('No se pudo crear el directorio de salida.');
}
$finalPdf = $renderPdf($render($pageMap, false));
if (false === file_put_contents($outputFile, $finalPdf)) {
    throw new RuntimeException('No se pudo escribir el PDF.');
}

fwrite(STDOUT, sprintf("PDF generado: %s\nPerfil: %s\n", $outputFile, $profile));
foreach ($headings as $heading) {
    if ($heading['toc']) {
        fwrite(STDOUT, sprintf("TOC p.%d — %s\n", $pageMap[$heading['id']], $heading['label']));
    }
}
