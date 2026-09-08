<?php

declare(strict_types=1);

namespace App\Tests\Service\Emission\Waste;

use App\Service\Emission\Waste\WasteEmissionInput;
use App\Service\Emission\Waste\WasteEmissionRequestMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WasteEmissionRequestMapperTest extends TestCase
{
    public function testMapsCanonicalRequestAndKeepsOptionalActivityAndTreatmentNullable(): void
    {
        $request = Request::create('/waste', 'POST', [
            'startDate' => '2025-02-01',
            'endDate' => '2025-02-28',
            'country' => 'esp',
            'wasteType' => 'Vidrio',
            'weight' => '100',
            'weightUnit' => 'kg',
        ]);

        $input = (new WasteEmissionRequestMapper())->map($request);

        self::assertSame('2025-02-01', $input->startDate?->format('Y-m-d'));
        self::assertSame('2025-02-28', $input->endDate?->format('Y-m-d'));
        self::assertSame('ESP', $input->country);
        self::assertSame('Vidrio', $input->wasteType);
        self::assertNull($input->wasteActivity);
        self::assertNull($input->treatment);
        self::assertSame('100', $input->weight);
        self::assertSame(WasteEmissionInput::UNIT_KG, $input->weightUnit);
    }

    public function testRejectsCrossYearInvalidCountryAndUnsupportedUnit(): void
    {
        $base = [
            'startDate' => '2025-12-31',
            'endDate' => '2026-01-01',
            'country' => 'ESP',
            'wasteType' => 'Vidrio',
            'weight' => '100',
            'weightUnit' => 'kg',
        ];

        foreach ([
            $base,
            [...$base, 'endDate' => '2025-12-31', 'country' => 'ZZZ'],
            [...$base, 'endDate' => '2025-12-31', 'weightUnit' => 'lb'],
        ] as $payload) {
            try {
                (new WasteEmissionRequestMapper())->map(Request::create('/waste', 'POST', $payload));
                self::fail('Invalid waste request must be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
