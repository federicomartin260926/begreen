<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignDataCatalog;
use PHPUnit\Framework\TestCase;

final class ReportDesignDataCatalogTest extends TestCase
{
    public function testCatalogExposesTreeWithoutObjects(): void
    {
        $catalog = new ReportDesignDataCatalog();
        $tree = $catalog->tree();

        self::assertArrayHasKey('project', $tree);
        self::assertArrayHasKey(
            'project.name',
            $tree['project']['variables']
        );
        self::assertArrayHasKey('score', $tree);
        self::assertArrayHasKey('ai', $tree);
    }

    public function testSnapshotContainsOnlyDeclaredKeys(): void
    {
        $project = new class {
            public function getName(): string
            {
                return 'Proyecto QA';
            }

            public function getSecret(): string
            {
                return 'NO DEBE EXPONERSE';
            }
        };

        $catalog = new ReportDesignDataCatalog();

        $snapshot = $catalog->snapshot([
            'project' => $project,
            'currentUserLabel' => 'Fede',
            'scoreGained' => 12,
            'scoreMax' => 20,
            'scorePct' => 60,
            'commitmentSummary' => [
                'planned' => [
                    'percentageRounded' => 75,
                ],
            ],
            'aiGeneralConclusion' => 'Resumen',
            'aiFinalConclusion' => 'Conclusión',
            'secret' => 'NO',
        ]);

        self::assertSame('Proyecto QA', $snapshot['project.name']);
        self::assertSame(60, $snapshot['score.percent']);
        self::assertSame(
            75,
            $snapshot['commitment.planned_percentage']
        );

        self::assertArrayNotHasKey('project.secret', $snapshot);
        self::assertArrayNotHasKey('secret', $snapshot);

        self::assertSame(
            array_keys($catalog->all()),
            array_keys($snapshot)
        );
    }
}
