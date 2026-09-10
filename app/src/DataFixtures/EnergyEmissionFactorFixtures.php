<?php

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class EnergyEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'energy';
    private const DATA_FILE = __DIR__.'/data/emission/energy_factors_v1.csv';
    private const HEADERS = [
        'factor_id', 'geography', 'iso3', 'subcategory', 'activity', 'variant',
        'technology_fuel_material', 'destination_origin_supplier', 'input_unit',
        'activity_year', 'activity_year_scope', 'factor_year', 'factor_value',
        'factor_unit', 'temporal_type', 'factor_version', 'source', 'source_detail',
        'source_url', 'is_temporal_fallback', 'is_geographic_proxy', 'quality_status',
        'notes', 'source_workbook', 'source_sheet',
    ];

    public function __construct(private readonly EmissionFactorKeyGenerator $keyGenerator)
    {
    }

    public static function getGroups(): array
    {
        return ['emission', 'energy-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $file = new \SplFileObject(self::DATA_FILE, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException('Unexpected energy emission factor CSV headers.');
        }

        $factorIds = [];
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed energy emission factor CSV row %d.', $file->key() + 1));
            }

            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);
            if (EmissionFactor::TEMPORAL_TYPE_ANNUAL !== $row['temporal_type'] || '' !== $row['factor_version']) {
                throw new \RuntimeException(sprintf('Unexpected energy temporal contract in CSV row %d.', $file->key() + 1));
            }
            if ('' === $row['activity_year'] || '' === $row['factor_year'] || '' === $row['factor_value']) {
                throw new \RuntimeException(sprintf('Incomplete energy factor row %d.', $file->key() + 1));
            }
            $criteria = $this->keyGenerator->normalize([
                'geography' => $row['geography'],
                'category' => $row['subcategory'],
                'activity' => $row['activity'],
                'labeling' => $row['variant'],
                'supplier' => $row['destination_origin_supplier'],
                'unit' => $row['input_unit'],
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            if ('' === $row['factor_id'] || isset($factorIds[$row['factor_id']])) {
                throw new \RuntimeException(sprintf('Missing or duplicate energy factor ID in CSV row %d.', $file->key() + 1));
            }
            $factorIds[$row['factor_id']] = true;

            $factor = (new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setFactorId($row['factor_id'])
                ->setActivityYear((int) $row['activity_year'])
                ->setTemporalType($row['temporal_type'])
                ->setYear((int) $row['factor_year'])
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail('' === $row['source_detail'] ? null : $row['source_detail'])
                ->setMetadata([
                    'activityUnit' => $row['input_unit'],
                    'factorVersion' => '' === $row['factor_version'] ? null : $row['factor_version'],
                    'isTemporalFallback' => '1' === $row['is_temporal_fallback'],
                    'isGeographicProxy' => '1' === $row['is_geographic_proxy'],
                    'proxyGeography' => $this->noteValue($row['notes'], 'proxy_geography'),
                    'fallbackReason' => $this->noteValue($row['notes'], 'fallback_reason'),
                    'qualityStatus' => '' === $row['quality_status'] ? null : $row['quality_status'],
                    'sourceUrl' => '' === $row['source_url'] ? null : $row['source_url'],
                    'notes' => '' === $row['notes'] ? null : $row['notes'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                ]);
            $manager->persist($factor);
        }

        $manager->flush();
    }

    private function noteValue(string $notes, string $key): ?string
    {
        if (!preg_match('/(?:^|; )'.preg_quote($key, '/').'=([^;]+)/', $notes, $matches)) {
            return null;
        }

        return 'None' === $matches[1] ? null : $matches[1];
    }
}
