<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

final readonly class CateringEmissionInput
{
    public const TYPE_BREAKFAST = 'breakfast';
    public const TYPE_COFFEEBREAK = 'coffeebreak';
    public const TYPE_SNACK = 'snack';
    public const TYPE_MEAL = 'meal';
    public const TYPE_SANDWICH = 'sandwich';
    public const TYPE_WATER = 'water';
    public const TYPE_DRINK = 'drink';
    public const TYPE_COFFEE = 'coffee';
    public const TYPE_GAS = 'gas';

    /** @param list<CateringMenuLine> $menuLines */
    public function __construct(
        public ?\DateTimeInterface $startDate,
        public ?\DateTimeInterface $endDate,
        public ?string $country,
        public ?string $activityType,
        public ?string $people = null,
        public array $menuLines = [],
        public ?string $tablewareType = null,
        public ?string $sandwichType = null,
        public ?string $preparedCount = null,
        public ?string $consumedCount = null,
        public ?string $containerVolumeLiters = null,
        public ?string $containerMaterial = null,
        public ?string $containerCount = null,
        public ?string $description = null,
        public ?string $unitCount = null,
        public ?string $litersPerUnit = null,
        public ?string $serviceCount = null,
        public ?string $coffeeType = null,
        public ?string $gasType = null,
        public ?string $cylinderCount = null,
        public ?string $kgPerCylinder = null,
    ) {
    }

    /** @return list<string> */
    public static function activityTypes(): array
    {
        return [self::TYPE_BREAKFAST, self::TYPE_COFFEEBREAK, self::TYPE_SNACK, self::TYPE_MEAL, self::TYPE_SANDWICH, self::TYPE_WATER, self::TYPE_DRINK, self::TYPE_COFFEE, self::TYPE_GAS];
    }

    /** @return list<string> */
    public static function menuVariants(): array
    {
        return ['beef', 'lamb', 'chicken', 'pork', 'fish', 'vegetarian', 'vegan'];
    }

    /** @return list<string> */
    public static function tablewareTypes(): array
    {
        return ['reusable', 'compostable', 'other', 'unknown'];
    }
}
