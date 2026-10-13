<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignCatalog;
use App\Service\ReportDesign\ReportDesignStorage;
use PHPUnit\Framework\TestCase;

final class ReportDesignStorageTest extends TestCase
{
    private string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir()
            .'/begreen-report-design-'
            .bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageDirectory);
    }

    public function testDraftAndApprovalLifecycle(): void
    {
        $definition = (new ReportDesignCatalog())->sustainabilityPlanGeneral();
        $storage = new ReportDesignStorage($this->storageDirectory);

        self::assertNull($storage->loadDraft($definition));
        self::assertNull($storage->loadApproved($definition));

        $draft = $storage->saveDraft(
            $definition,
            "{% include 'example.html.twig' %}\n",
            ".example { font-size: 12px; }\n",
            ['instruction' => 'Primera iteración'],
        );

        self::assertSame(
            "{% include 'example.html.twig' %}\n",
            $draft['html']
        );
        self::assertSame(
            'Primera iteración',
            $draft['metadata']['instruction']
        );

        $approved = $storage->approve($definition);

        self::assertSame($draft['html'], $approved['html']);
        self::assertSame($draft['css'], $approved['css']);
        self::assertSame('draft', $approved['metadata']['source']);

        $storage->saveDraft(
            $definition,
            "<div>segunda versión</div>\n",
            ".second { margin: 0; }\n",
        );

        $stillApproved = $storage->loadApproved($definition);

        self::assertNotNull($stillApproved);
        self::assertSame(
            "{% include 'example.html.twig' %}\n",
            $stillApproved['html']
        );
    }

    public function testIntegrityFailureIsDetected(): void
    {
        $definition = (new ReportDesignCatalog())->sustainabilityPlanGeneral();
        $storage = new ReportDesignStorage($this->storageDirectory);

        $storage->saveDraft(
            $definition,
            "<div>válido</div>\n",
            ".valid { margin: 0; }\n",
        );

        $htmlPath = $this->storageDirectory
            .'/sustainability-plan/general/draft/design.html';

        file_put_contents($htmlPath, "<div>alterado externamente</div>\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');

        $storage->loadDraft($definition);
    }

    public function testUnknownReportIsRejected(): void
    {
        $catalog = new ReportDesignCatalog();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown report design');

        $catalog->get('sustainability-plan', 'unknown');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
