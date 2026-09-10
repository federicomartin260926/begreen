<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class CateringEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'catering';
    private const FILE = __DIR__.'/data/emission/catering_factors_v1.csv';
    private const HEADERS = [
        'factor_id', 'activity_type', 'variant', 'activity_year', 'activity_year_scope',
        'factor_year', 'factor_value', 'factor_unit', 'temporal_type', 'factor_version',
        'source', 'source_detail', 'source_url', 'is_temporal_fallback',
        'is_geographic_proxy', 'quality_status', 'notes', 'source_workbook', 'source_sheet',
    ];
    private const MENU_VARIANTS = [
        'Vacuno' => 'beef',
        'Cordero' => 'lamb',
        'Pollo' => 'chicken',
        'Cerdo' => 'pork',
        'Pescado' => 'fish',
        'Vegetariano' => 'vegetarian',
        'Vegano' => 'vegan',
    ];
    private const TABLEWARE_VARIANTS = [
        'Vajilla compostable de un solo uso' => ['compostable', EmissionFactor::TEMPORAL_TYPE_COMPOSITE],
        'Vajilla reutilizable' => ['reusable', EmissionFactor::TEMPORAL_TYPE_PROXY_LCA],
    ];

    public function __construct(private readonly EmissionFactorKeyGenerator $keyGenerator)
    {
    }

    public static function getGroups(): array
    {
        return ['emission', 'catering-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $file = new \SplFileObject(self::FILE, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException('Unexpected catering emission factor CSV headers.');
        }

        $identities = [];
        $count = 0;
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed catering emission factor CSV row %d.', $file->key() + 1));
            }

            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);
            [$criteria, $temporalType, $activityUnit, $variant] = $this->mapRow($row, $file->key() + 1);
            $this->assertRequiredValues($row, $file->key() + 1);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $identity = $row['factor_id'];
            if (isset($identities[$identity])) {
                throw new \RuntimeException(sprintf('Duplicate catering factor identity in CSV row %d.', $file->key() + 1));
            }
            $identities[$identity] = true;

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setFactorId($row['factor_id'])
                ->setActivityYear('' === $row['activity_year'] ? null : (int) $row['activity_year'])
                ->setYear('' === $row['factor_year'] ? null : (int) $row['factor_year'])
                ->setTemporalType($temporalType)
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail('' === $row['source_detail'] ? null : $row['source_detail'])
                ->setMetadata([
                    'activityType' => 'meal',
                    'variant' => $variant,
                    'factorVersion' => $row['factor_version'],
                    'activityYearScope' => $row['activity_year_scope'],
                    'sourceUrl' => $row['source_url'],
                    'isTemporalFallback' => '1' === $row['is_temporal_fallback'],
                    'isGeographicProxy' => '1' === $row['is_geographic_proxy'],
                    'proxyGeography' => null,
                    'qualityStatus' => $row['quality_status'],
                    'notes' => $row['notes'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                    'activityUnit' => $activityUnit,
                ]));
            ++$count;
        }

        if (9 !== $count) {
            throw new \RuntimeException(sprintf('Expected 9 catering emission factors, got %d.', $count));
        }
        $manager->flush();
    }

    /** @param array<string, string> $row
     *  @return array{array<string, string>, string, string, string}
     */
    private function mapRow(array $row, int $line): array
    {
        if ('Menú' !== $row['activity_type']) {
            throw new \RuntimeException(sprintf('Unsupported catering activity type in CSV row %d.', $line));
        }

        if (isset(self::MENU_VARIANTS[$row['variant']])) {
            if (EmissionFactor::TEMPORAL_TYPE_VERSIONED !== $row['temporal_type'] || 'kgCO2e/menú preparado' !== $row['factor_unit']) {
                throw new \RuntimeException(sprintf('Invalid food factor type or unit in CSV row %d.', $line));
            }
            $variant = self::MENU_VARIANTS[$row['variant']];

            return [[
                'component' => 'food',
                'menuVariant' => $variant,
                'unit' => 'prepared_menu',
            ], EmissionFactor::TEMPORAL_TYPE_VERSIONED, 'prepared_menu', $variant];
        }

        if (isset(self::TABLEWARE_VARIANTS[$row['variant']])) {
            [$variant, $temporalType] = self::TABLEWARE_VARIANTS[$row['variant']];
            if ($temporalType !== $row['temporal_type'] || 'kgCO2e/menú consumido' !== $row['factor_unit']) {
                throw new \RuntimeException(sprintf('Invalid tableware factor type or unit in CSV row %d.', $line));
            }

            return [[
                'component' => 'tableware',
                'tablewareType' => $variant,
                'unit' => 'consumed_menu',
            ], $temporalType, 'consumed_menu', $variant];
        }

        throw new \RuntimeException(sprintf('Unsupported catering factor variant in CSV row %d.', $line));
    }

    /** @param array<string, string> $row */
    private function assertRequiredValues(array $row, int $line): void
    {
        foreach (array_diff(self::HEADERS, ['activity_year', 'factor_year']) as $field) {
            if ('' === trim($row[$field])) {
                throw new \RuntimeException(sprintf('Missing catering factor field %s in CSV row %d.', $field, $line));
            }
        }
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $row['factor_value']) || bccomp($row['factor_value'], '0', 18) <= 0) {
            throw new \RuntimeException(sprintf('Invalid catering factor value in CSV row %d.', $line));
        }
    }
}
