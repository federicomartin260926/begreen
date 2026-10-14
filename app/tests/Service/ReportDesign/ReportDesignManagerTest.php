<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignDefinition;
use App\Service\ReportDesign\ReportDesignManager;
use App\Service\ReportDesign\ReportDesignStorage;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\TestCase;

final class ReportDesignManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            .'/begreen-report-design-manager-'
            .bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($this->directory);
    }

    public function testValidatesDraftBeforeSaving(): void
    {
        $manager = $this->manager();

        $draft = $manager->saveDraft(
            $this->definition(),
            $this->validHtml(),
            '.report-design-cover { background: #f3faf5; }',
            ['source' => 'test'],
        );

        self::assertSame($this->validHtml(), $draft['html']);
        self::assertSame('test', $draft['metadata']['source']);
    }

    public function testRejectsInvalidDraftBeforeSaving(): void
    {
        $manager = $this->manager();

        $this->expectException(\InvalidArgumentException::class);

        $manager->saveDraft(
            $this->definition(),
            '<script>alert(1)</script>',
            '',
        );
    }

    public function testRevalidatesDraftBeforeApproval(): void
    {
        $storage = new ReportDesignStorage($this->directory);
        $manager = $this->manager($storage);

        $storage->saveDraft(
            $this->definition(),
            '[COMPONENT:cover]',
            '',
        );

        $this->expectException(\InvalidArgumentException::class);

        $manager->approve($this->definition());
    }

    public function testApprovesValidDraft(): void
    {
        $storage = new ReportDesignStorage($this->directory);
        $manager = $this->manager($storage);

        $manager->saveDraft(
            $this->definition(),
            $this->validHtml(),
            '.report-design-cover { border: 1px solid #d3e8da; }',
        );

        $approved = $manager->approve($this->definition());

        self::assertSame($this->validHtml(), $approved['html']);
        self::assertSame('draft', $approved['metadata']['source']);
        self::assertArrayHasKey(
            'approved_at',
            $approved['metadata'],
        );
    }

    private function manager(
        ?ReportDesignStorage $storage = null,
    ): ReportDesignManager {
        return new ReportDesignManager(
            $storage ?? new ReportDesignStorage($this->directory),
            new ReportDesignValidator(
                new ReportDesignComponentCatalog(),
                new ReportDesignDataCatalog(),
            ),
        );
    }

    private function definition(): ReportDesignDefinition
    {
        return new ReportDesignDefinition(
            'sustainability-plan',
            'general',
            'Plan de Sostenibilidad — PDF general',
        );
    }

    private function validHtml(): string
    {
        return <<<'HTML'
[COMPONENT:cover]
[COMPONENT:analytics]
[COMPONENT:ai_report]
[COMPONENT:departments]
[COMPONENT:final_conclusion]
HTML;
    }
}
