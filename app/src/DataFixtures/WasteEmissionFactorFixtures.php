<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class WasteEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'waste';
    private const FACTOR_FILE = __DIR__.'/data/emission/waste_factors_v1.csv';

    private const HEADERS = [
        'category', 'factor_id', 'geography', 'iso3', 'subcategory', 'activity', 'variant',
        'technology_fuel_material', 'destination_origin_supplier', 'input_unit', 'activity_year',
        'activity_year_scope', 'factor_year', 'factor_value', 'factor_unit', 'temporal_type',
        'factor_version', 'source', 'source_detail', 'source_url', 'is_temporal_fallback',
        'is_geographic_proxy', 'quality_status', 'notes', 'source_workbook', 'source_sheet',
    ];

    public function __construct(private readonly EmissionFactorKeyGenerator $keyGenerator)
    {
    }

    public static function getGroups(): array
    {
        return ['emission', 'waste-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $factorIds = [];
        $applicabilityIdentities = [];
        $counts = [
            EmissionFactor::TEMPORAL_TYPE_ANNUAL => 0,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED => 0,
        ];

        $file = new \SplFileObject(self::FACTOR_FILE, 'rb');
        $file->setCsvControl(',', '"', '');

        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException('Unexpected waste CSV headers.');
        }

        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }

            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf(
                    'Malformed waste CSV row %d.',
                    $file->key() + 1,
                ));
            }

            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);

            if ('Residuos' !== $row['category'] || '' === $row['factor_id']) {
                throw new \RuntimeException(sprintf(
                    'Invalid waste identity in CSV row %d.',
                    $file->key() + 1,
                ));
            }

            if (!isset($counts[$row['temporal_type']])) {
                throw new \RuntimeException(sprintf(
                    'Unsupported waste temporal type in CSV row %d.',
                    $file->key() + 1,
                ));
            }

            if ('' === $row['factor_value'] || !is_numeric($row['factor_value'])) {
                throw new \RuntimeException(sprintf(
                    'Invalid waste factor value in CSV row %d.',
                    $file->key() + 1,
                ));
            }

            if ('kg' !== $row['input_unit'] || 'kgCO2e/kg' !== $row['factor_unit']) {
                throw new \RuntimeException(sprintf(
                    'Invalid waste units in CSV row %d.',
                    $file->key() + 1,
                ));
            }

            if (!preg_match('/^20(?:22|23|24|25|26)$/', $row['activity_year'])) {
                throw new \RuntimeException(sprintf(
                    'Invalid waste activity year in CSV row %d.',
                    $file->key() + 1,
                ));
            }

            $regionScope = match ($row['geography']) {
                'España' => 'spain',
                'Fuera de España' => 'outside_spain',
                default => throw new \RuntimeException(sprintf(
                    'Invalid waste geography in CSV row %d.',
                    $file->key() + 1,
                )),
            };

            $activityYear = (int) $row['activity_year'];
            $factorYear = '' === $row['factor_year'] ? null : (int) $row['factor_year'];

            $criteria = $this->keyGenerator->normalize([
                'regionScope' => $regionScope,
                'wasteType' => $row['subcategory'],
                'wasteActivity' => $row['activity'],
                'treatment' => $row['destination_origin_supplier'],
                'unit' => $row['input_unit'],
                'sourceFamily' => $row['source'],
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);

            if (isset($factorIds[$row['factor_id']])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate waste factorId in CSV row %d.',
                    $file->key() + 1,
                ));
            }
            $factorIds[$row['factor_id']] = true;

            $applicabilityIdentity = $functionalKey.'|'.$activityYear;
            if (isset($applicabilityIdentities[$applicabilityIdentity])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate waste applicability identity in CSV row %d.',
                    $file->key() + 1,
                ));
            }
            $applicabilityIdentities[$applicabilityIdentity] = true;

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setFactorId($row['factor_id'])
                ->setActivityYear($activityYear)
                ->setYear($factorYear)
                ->setTemporalType($row['temporal_type'])
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail($row['source_detail'])
                ->setMetadata([
                    'geography' => $row['geography'],
                    'iso3' => '' === $row['iso3'] ? null : $row['iso3'],
                    'subcategory' => $row['subcategory'],
                    'variant' => '' === $row['variant'] ? null : $row['variant'],
                    'technologyFuelMaterial' => '' === $row['technology_fuel_material']
                        ? null
                        : $row['technology_fuel_material'],
                    'activityYearScope' => '' === $row['activity_year_scope']
                        ? null
                        : $row['activity_year_scope'],
                    'factorVersion' => '' === $row['factor_version']
                        ? null
                        : $row['factor_version'],
                    'sourceUrl' => '' === $row['source_url'] ? null : $row['source_url'],
                    'isTemporalFallback' => 'TRUE' === $row['is_temporal_fallback'],
                    'fallbackReason' => 'TRUE' === $row['is_temporal_fallback'] && '' !== $row['notes']
                        ? $row['notes']
                        : null,
                    'isGeographicProxy' => 'TRUE' === $row['is_geographic_proxy'],
                    'qualityStatus' => '' === $row['quality_status']
                        ? null
                        : $row['quality_status'],
                    'notes' => '' === $row['notes'] ? null : $row['notes'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                    'sourceGeography' => 'OCCC' === $row['source'] ? 'ESP' : 'GBR',
                    'activityUnit' => $row['input_unit'],
                ]));

            ++$counts[$row['temporal_type']];
        }

        if (1270 !== count($factorIds)) {
            throw new \RuntimeException(sprintf(
                'Expected 1270 waste factors, got %d.',
                count($factorIds),
            ));
        }
        if (930 !== $counts[EmissionFactor::TEMPORAL_TYPE_VERSIONED]) {
            throw new \RuntimeException(sprintf(
                'Expected 930 VERSIONED waste factors, got %d.',
                $counts[EmissionFactor::TEMPORAL_TYPE_VERSIONED],
            ));
        }
        if (340 !== $counts[EmissionFactor::TEMPORAL_TYPE_ANNUAL]) {
            throw new \RuntimeException(sprintf(
                'Expected 340 ANNUAL waste factors, got %d.',
                $counts[EmissionFactor::TEMPORAL_TYPE_ANNUAL],
            ));
        }

        $manager->flush();
    }
}
