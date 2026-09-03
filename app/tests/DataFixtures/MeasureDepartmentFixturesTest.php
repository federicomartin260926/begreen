<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\MeasureDepartmentFixtures;
use PHPUnit\Framework\TestCase;

final class MeasureDepartmentFixturesTest extends TestCase
{
    public function testContainsOnlyTheExpectedMeasureDepartments(): void
    {
        $reflection = new \ReflectionClass(MeasureDepartmentFixtures::class);
        $departments = $reflection->getReflectionConstant('DEPARTMENTS')?->getValue();

        self::assertIsArray($departments);
        self::assertCount(46, $departments);
        self::assertCount(22, array_filter($departments, static fn (array $department): bool => 'rodaje' === $department['projectType']));
        self::assertCount(24, array_filter($departments, static fn (array $department): bool => 'evento' === $department['projectType']));
        self::assertSame(['name', 'projectType', 'sortOrder'], array_keys($departments[0]));
        self::assertSame(['name', 'projectType', 'sortOrder'], array_keys($departments[array_key_last($departments)]));

        $source = file_get_contents($reflection->getFileName());
        self::assertIsString($source);
        self::assertStringNotContainsString('App\\Entity\\Position', $source);
        self::assertStringNotContainsString('new Position', $source);
    }
}
