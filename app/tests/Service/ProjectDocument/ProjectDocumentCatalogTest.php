<?php

namespace App\Tests\Service\ProjectDocument;

use App\Enum\ProjectDocumentCatalog;
use PHPUnit\Framework\TestCase;

final class ProjectDocumentCatalogTest extends TestCase
{
    public function testCatalogContainsExactExpectedCounts(): void
    {
        self::assertCount(18, ProjectDocumentCatalog::COMMON_TYPES);
        self::assertCount(10, ProjectDocumentCatalog::ANIMATION_TYPES);
        self::assertCount(28, ProjectDocumentCatalog::allTypeCodes());
        self::assertSame(
            ProjectDocumentCatalog::allTypeCodes(),
            array_values(array_unique(ProjectDocumentCatalog::allTypeCodes())),
        );
    }

    public function testAnimationSpecificTypesAreSeparatedFromCommonTypes(): void
    {
        self::assertFalse(ProjectDocumentCatalog::isAnimationType('script'));
        self::assertTrue(ProjectDocumentCatalog::isAnimationType('animatic'));
        self::assertTrue(ProjectDocumentCatalog::isAnimationType('capture_mocap_plan'));
        self::assertFalse(ProjectDocumentCatalog::isAnimationType('other'));
    }

    public function testChoicesExcludeAnimationTypesWhenRequested(): void
    {
        $common = ProjectDocumentCatalog::typeChoices(false);
        $animation = ProjectDocumentCatalog::typeChoices(true);

        self::assertCount(18, $common);
        self::assertCount(28, $animation);
        self::assertContains('final_report_photo', $common, true);
        self::assertContains('other', $common, true);
        self::assertNotContains('animatic', $common, true);
        self::assertContains('animatic', $animation, true);
    }

    public function testAllowedExtensionsMatchWireframe(): void
    {
        self::assertSame([
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'rtf',
            'csv',
            'jpg',
            'jpeg',
            'png',
            'webp',
            'zip',
        ], ProjectDocumentCatalog::ALLOWED_EXTENSIONS);
    }
}
