<?php

namespace App\Tests\Entity;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

final class BgosSubcategoryConfigTest extends TestCase
{
    public function testDefinesProjectScopedSubcategoryUniqueness(): void
    {
        $ormConstraint = $this->classAttribute(ORM\UniqueConstraint::class);
        $validationConstraint = $this->classAttribute(UniqueEntity::class);

        self::assertSame(
            ['project_id', 'category_key', 'subcategory_key'],
            $ormConstraint->columns
        );
        self::assertSame(
            ['project', 'categoryKey', 'subcategoryKey'],
            $validationConstraint->fields
        );
    }

    public function testStoresIndependentBgosIdentityAndPhaseFrequencies(): void
    {
        $project = new Project();
        $config = (new BgosSubcategoryConfig())
            ->setProject($project)
            ->setCategoryKey('transport')
            ->setSubcategoryKey('crew_shuttle')
            ->setLabel('Traslado del equipo')
            ->setActive(true)
            ->setPreproductionFrequency(BgosSubcategoryConfig::FREQUENCY_PUNCTUAL)
            ->setActivityFrequency(BgosSubcategoryConfig::FREQUENCY_DAILY)
            ->setPostproductionFrequency(BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE);

        self::assertSame($project, $config->getProject());
        self::assertSame('transport', $config->getCategoryKey());
        self::assertSame('crew_shuttle', $config->getSubcategoryKey());
        self::assertSame('Traslado del equipo', $config->getLabel());
        self::assertTrue($config->isActive());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_PUNCTUAL, $config->getPreproductionFrequency());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_DAILY, $config->getActivityFrequency());
        self::assertSame(BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE, $config->getPostproductionFrequency());
    }

    public function testFrequencyFieldsShareTheAllowedValues(): void
    {
        foreach (['preproductionFrequency', 'activityFrequency', 'postproductionFrequency'] as $propertyName) {
            $property = new \ReflectionProperty(BgosSubcategoryConfig::class, $propertyName);
            $attributes = $property->getAttributes(Assert\Choice::class);

            self::assertCount(1, $attributes);
            self::assertSame(BgosSubcategoryConfig::FREQUENCIES, $attributes[0]->newInstance()->choices);
        }
    }

    public function testRejectsUnsupportedFrequency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BgosSubcategoryConfig())->setActivityFrequency('weekly');
    }

    private function classAttribute(string $attribute): object
    {
        $attributes = (new \ReflectionClass(BgosSubcategoryConfig::class))->getAttributes($attribute);

        self::assertCount(1, $attributes);

        return $attributes[0]->newInstance();
    }
}
