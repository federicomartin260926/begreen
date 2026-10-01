<?php

namespace App\Tests\Service\CrewImport;

use App\Exception\CrewImport\CrewImportExtractionException;
use App\Service\CrewImport\CrewImportPdfTextExtractor;
use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;

final class CrewImportPdfTextExtractorTest extends TestCase
{
    public function testExtractsTextFromSyntheticPdfWithoutCreatingSidecarFiles(): void
    {
        $extractor = new CrewImportPdfTextExtractor();
        self::assertTrue($extractor->isAvailable());

        $directory = sys_get_temp_dir().'/crew_pdf_'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory.'/synthetic.pdf';

        $dompdf = new Dompdf();
        $dompdf->loadHtml('<p>Synthetic crew PDF text</p>');
        $dompdf->render();
        file_put_contents($path, $dompdf->output());
        $filesBeforeExtraction = scandir($directory);

        try {
            self::assertStringContainsString('Synthetic crew PDF text', $extractor->extract($path));
            self::assertSame($filesBeforeExtraction, scandir($directory));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testUnreadablePathIsControlled(): void
    {
        $this->expectException(CrewImportExtractionException::class);
        (new CrewImportPdfTextExtractor())->extract('/tmp/does-not-exist-crew.pdf');
    }
}
