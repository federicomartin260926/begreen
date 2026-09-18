<?php

namespace App\Tests\Service\Bgos;

use App\Service\Bgos\BgosSubcategoryCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BgosSubcategoryCatalogTest extends KernelTestCase
{
    public function testCatalogUsesSevenModernCategoriesAndExpectedSourceCounts(): void
    {
        self::bootKernel();
        $categories = self::getContainer()->get(BgosSubcategoryCatalog::class)->categories();

        self::assertSame(BgosSubcategoryCatalog::CATEGORY_KEYS, array_column($categories, 'key'));
        self::assertSame([
            'transport' => 2,
            'energy' => 4,
            'water' => 8,
            'accommodation' => 4,
            'catering' => 9,
            'materials' => 22,
            'waste' => 26,
        ], array_column(array_map(
            static fn (array $category): array => [
                'key' => $category['key'],
                'count' => count($category['subcategories']),
            ],
            $categories,
        ), 'count', 'key'));
    }

    public function testTransportMapsModernCategoriesToPeopleAndFreight(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(BgosSubcategoryCatalog::class);

        self::assertSame(['local', 'travel'], $catalog->find('transport', 'people')['sourceKeys'] ?? null);
        self::assertSame(['freight'], $catalog->find('transport', 'freight')['sourceKeys'] ?? null);
        self::assertSame('people', $catalog->findBySourceKey('transport', 'local')['subcategoryKey'] ?? null);
        self::assertSame('people', $catalog->findBySourceKey('transport', 'travel')['subcategoryKey'] ?? null);
        self::assertSame('freight', $catalog->findBySourceKey('transport', 'freight')['subcategoryKey'] ?? null);
        self::assertNull($catalog->find('transport', 'car'));
        self::assertNull($catalog->findBySourceKey('transport', 'car'));
    }

    public function testMaterialActivitiesKeepTheirNaturalFamilyGrouping(): void
    {
        self::bootKernel();
        $categories = self::getContainer()->get(BgosSubcategoryCatalog::class)->categories();
        $materials = array_values(array_filter(
            $categories,
            static fn (array $category): bool => 'materials' === $category['key'],
        ))[0]['subcategories'];

        self::assertNotEmpty($materials);
        foreach ($materials as $material) {
            self::assertNotNull($material['groupKey']);
            self::assertNotNull($material['groupLabel']);
        }

        $wood = array_values(array_filter(
            $materials,
            static fn (array $definition): bool => ['Madera'] === $definition['sourceKeys'],
        ))[0];
        self::assertSame('madera', $wood['subcategoryKey']);
        self::assertSame('wood', $wood['groupKey']);
        self::assertSame('Madera', $wood['groupLabel']);
    }
}
