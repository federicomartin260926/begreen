<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class MaterialEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'material';
    private const FACTOR_FILE = __DIR__.'/data/emission/material_factors_v1.csv';
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
        return ['emission', 'material-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $factorIds = [];
        $applicabilityIdentities = [];
        $counts = [
            EmissionFactor::TEMPORAL_TYPE_ANNUAL => 0,
            EmissionFactor::TEMPORAL_TYPE_VERSIONED => 0,
            EmissionFactor::TEMPORAL_TYPE_RULE => 0,
        ];
        $this->loadFile(self::FACTOR_FILE, $manager, $factorIds, $applicabilityIdentities, $counts);

        if (883 !== $counts[EmissionFactor::TEMPORAL_TYPE_ANNUAL]) {
            throw new \RuntimeException(sprintf('Expected 883 ANNUAL material factors, got %d.', $counts[EmissionFactor::TEMPORAL_TYPE_ANNUAL]));
        }
        if (931 !== $counts[EmissionFactor::TEMPORAL_TYPE_VERSIONED]) {
            throw new \RuntimeException(sprintf('Expected 931 VERSIONED material factors, got %d.', $counts[EmissionFactor::TEMPORAL_TYPE_VERSIONED]));
        }
        if (186 !== $counts[EmissionFactor::TEMPORAL_TYPE_RULE]) {
            throw new \RuntimeException(sprintf('Expected 186 RULE material factors, got %d.', $counts[EmissionFactor::TEMPORAL_TYPE_RULE]));
        }

        $manager->flush();
    }

    /** @param array<string, true> $identities
     *  @param array<string, int> $counts
     */
    private function loadFile(
        string $path,
        ObjectManager $manager,
        array &$factorIds,
        array &$applicabilityIdentities,
        array &$counts,
    ): void
    {
        $file = new \SplFileObject($path, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException(sprintf('Unexpected material CSV headers in "%s".', basename($path)));
        }

        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed material CSV row %d in "%s".', $file->key() + 1, basename($path)));
            }
            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);
            $temporalType = $row['temporal_type'];
            if (!array_key_exists($temporalType, $counts)) {
                throw new \RuntimeException(sprintf('Unsupported material temporal type in CSV row %d.', $file->key() + 1));
            }
            if ('' === $row['factor_value'] || !is_numeric($row['factor_value'])) {
                throw new \RuntimeException(sprintf('Invalid material factor value in CSV row %d.', $file->key() + 1));
            }

            if ('Materiales y productos' !== $row['category'] || '' === $row['factor_id']) {
                throw new \RuntimeException(sprintf('Invalid material identity in CSV row %d.', $file->key() + 1));
            }
            if (!preg_match('/^20(?:22|23|24|25|26)$/', $row['activity_year'])) {
                throw new \RuntimeException(sprintf('Invalid material activity year in CSV row %d.', $file->key() + 1));
            }
            $activityYear = (int) $row['activity_year'];
            $factorYear = '' === $row['factor_year'] ? null : (int) $row['factor_year'];

            $criteria = $this->keyGenerator->normalize([
                'activity' => $row['activity'],
                'subproduct' => $row['variant'],
                'origin' => $row['destination_origin_supplier'],
                'unit' => $row['input_unit'],
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            if (isset($factorIds[$row['factor_id']])) {
                throw new \RuntimeException(sprintf('Duplicate material factorId in CSV row %d.', $file->key() + 1));
            }
            $factorIds[$row['factor_id']] = true;
            $applicabilityIdentity = $functionalKey.'|'.$activityYear;
            if (isset($applicabilityIdentities[$applicabilityIdentity])) {
                throw new \RuntimeException(sprintf('Duplicate material applicability identity in CSV row %d.', $file->key() + 1));
            }
            $applicabilityIdentities[$applicabilityIdentity] = true;

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setFactorId($row['factor_id'])
                ->setActivityYear($activityYear)
                ->setYear($factorYear)
                ->setTemporalType($temporalType)
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail($row['source_detail'])
                ->setMetadata([
                    'geography' => $row['geography'],
                    'iso3' => '' === $row['iso3'] ? null : $row['iso3'],
                    'subcategory' => $row['subcategory'],
                    'technologyFuelMaterial' => '' === $row['technology_fuel_material'] ? null : $row['technology_fuel_material'],
                    'activityYearScope' => '' === $row['activity_year_scope'] ? null : $row['activity_year_scope'],
                    'factorVersion' => '' === $row['factor_version'] ? null : $row['factor_version'],
                    'sourceUrl' => '' === $row['source_url'] ? null : $row['source_url'],
                    'isTemporalFallback' => 'TRUE' === $row['is_temporal_fallback'],
                    'fallbackReason' => 'TRUE' === $row['is_temporal_fallback'] && '' !== $row['notes'] ? $row['notes'] : null,
                    'isGeographicProxy' => 'TRUE' === $row['is_geographic_proxy'],
                    'qualityStatus' => '' === $row['quality_status'] ? null : $row['quality_status'],
                    'notes' => '' === $row['notes'] ? null : $row['notes'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                ]));
            ++$counts[$temporalType];
        }
    }
}
