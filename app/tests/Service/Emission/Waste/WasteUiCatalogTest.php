<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Waste;

use App\Service\Emission\Waste\WasteUiCatalog;
use PHPUnit\Framework\TestCase;

final class WasteUiCatalogTest extends TestCase
{
    public function testSpainAndOutsideCatalogsStayIndependentWithOfficialLabels(): void
    {
        $catalog = new WasteUiCatalog();
        $spain = $catalog->wasteTypesForCountry('ESP');
        $outside = $catalog->wasteTypesForCountry('FRA');

        self::assertCount(25, $spain);
        self::assertCount(14, $outside);
        self::assertSame('Basura mezclada (sin separar)', $this->label($spain, 'Residuo general (no recogida selectiva)'));
        self::assertSame('Fracción resto (después de separar)', $this->label($spain, 'Resto (mezcla)'));
        self::assertNull($this->label($spain, 'Residuos domésticos residuales'));
        self::assertSame('Residuos domésticos residuales', $this->label($outside, 'Residuos domésticos residuales'));
    }

    public function testSubactivitiesAndTreatmentsAreValidatedByExactBranch(): void
    {
        $catalog = new WasteUiCatalog();
        self::assertTrue($catalog->requiresSubactivity('ESP', 'Residuos químicos (no peligrosos)'));
        self::assertContains('Pintura al agua', $catalog->activitiesFor('ESP', 'Residuos químicos (no peligrosos)'));
        self::assertSame(
            'Pintura al agua',
            $catalog->canonicalActivity('ESP', 'Residuos químicos (no peligrosos)', 'Pintura al agua'),
        );
        self::assertNull($catalog->canonicalActivity('ESP', 'Residuos químicos (no peligrosos)', 'Inventado'));
        self::assertNotNull($catalog->resolveRoute(
            'ESP',
            'Residuos químicos (no peligrosos)',
            'Pintura al agua',
            'Tratamiento físico-químico y biológico',
        ));
        self::assertNull($catalog->resolveRoute(
            'FRA',
            'Residuos químicos (no peligrosos)',
            'Pintura al agua',
            'Tratamiento físico-químico y biológico',
        ));
    }

    /** @param list<array{value: string, label: string, hasSubactivity: bool}> $types */
    private function label(array $types, string $value): ?string
    {
        foreach ($types as $type) {
            if ($value === $type['value']) {
                return $type['label'];
            }
        }

        return null;
    }
}
