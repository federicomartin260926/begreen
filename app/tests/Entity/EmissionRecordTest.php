<?php

namespace App\Tests\Entity;

use App\DataFixtures\EmissionRecordFixtures;
use App\Entity\Category;
use App\Entity\EmissionActivity;
use App\Entity\EmissionRecord;
use PHPUnit\Framework\TestCase;

final class EmissionRecordTest extends TestCase
{
    public function testLegacyRecordFallsBackToActivityCategory(): void
    {
        $category = (new Category())->setName('Transporte');
        $activity = (new EmissionActivity())->setCategory($category);
        $record = (new EmissionRecord())->setActivity($activity);

        self::assertNull($record->getCategory());
        self::assertSame($category, $record->getEffectiveCategory());
    }

    public function testModernRecordUsesExplicitCategoryWithoutActivity(): void
    {
        $category = (new Category())->setName('Transporte');
        $record = (new EmissionRecord())->setCategory($category);

        self::assertNull($record->getActivity());
        self::assertSame($category, $record->getEffectiveCategory());
    }

    public function testExplicitCategoryTakesPrecedenceOverActivityCategory(): void
    {
        $explicit = (new Category())->setName('Transporte');
        $legacy = (new Category())->setName('Viajes');
        $activity = (new EmissionActivity())->setCategory($legacy);
        $record = (new EmissionRecord())->setActivity($activity)->setCategory($explicit);

        self::assertSame($explicit, $record->getEffectiveCategory());
    }

    public function testEmissionRecordFixturesAssignExplicitCategoryFromActivity(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/DataFixtures/EmissionRecordFixtures.php');

        self::assertIsString($source);
        self::assertStringContainsString('->setCategory($act->getCategory())', $source);
    }

    public function testLegacyFixturesExcludeAllMigratedCategories(): void
    {
        $modernCategories = (new \ReflectionClass(EmissionRecordFixtures::class))
            ->getReflectionConstant('MODERN_CATEGORIES')
            ?->getValue();
        $activityFixture = file_get_contents(__DIR__.'/../../src/DataFixtures/EmissionActivityFixtures.php');

        self::assertContains('Transporte', $modernCategories);
        self::assertContains('Energía', $modernCategories);
        self::assertContains('Agua', $modernCategories);
        self::assertIsString($activityFixture);
        self::assertStringNotContainsString("['Transporte',", $activityFixture);
        self::assertStringNotContainsString("['Agua',", $activityFixture);
    }
}
