<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class AccommodationEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'accommodation';
    private const HOTEL_FILE = __DIR__.'/data/emission/accommodation_hotel_factors_v1.csv';
    private const CONTEXTUAL_FILE = __DIR__.'/data/emission/accommodation_contextual_factors_v1.csv';
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
        return ['emission', 'accommodation-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $factorIds = [];
        $this->loadHotelFactors($manager, $factorIds);
        $this->loadContextualFactors($manager, $factorIds);
        $manager->flush();
    }

    /** @param array<string, true> $factorIds */
    private function loadHotelFactors(ObjectManager $manager, array &$factorIds): void
    {
        $file = $this->csv(self::HOTEL_FILE, self::HEADERS, 'hotel');
        while (!$file->eof()) {
            $row = $this->row($file, self::HEADERS, 'hotel');
            if (null === $row) {
                continue;
            }
            if ('Hotel' !== $row['activity'] || EmissionFactor::TEMPORAL_TYPE_VERSIONED !== $row['temporal_type']) {
                throw new \RuntimeException('Unexpected hotel factor contract.');
            }
            if ('' === $row['factor_id'] || '' === $row['activity_year'] || '' === $row['factor_year'] || '' === $row['factor_value']) {
                throw new \RuntimeException('Incomplete hotel factor row.');
            }

            $stars = str_replace(' estrellas', '', $row['variant']);
            $criteria = $this->keyGenerator->normalize([
                'accommodationType' => 'hotel',
                'iso3' => $row['iso3'],
                'stars' => $stars,
                'unit' => 'occupied room-night',
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $this->assertUniqueFactorId($factorIds, $row['factor_id'], 'hotel', $file->key() + 1);

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setFactorId($row['factor_id'])
                ->setActivityYear((int) $row['activity_year'])
                ->setYear((int) $row['factor_year'])
                ->setTemporalType($row['temporal_type'])
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail('' === $row['source_detail'] ? null : $row['source_detail'])
                ->setMetadata([
                    'country' => $row['geography'],
                    'iso3' => $row['iso3'],
                    'stars' => $stars,
                    'factorVersion' => $row['factor_version'],
                    'isTemporalFallback' => $this->boolean($row['is_temporal_fallback'], 'is_temporal_fallback'),
                    'isGeographicProxy' => $this->boolean($row['is_geographic_proxy'], 'is_geographic_proxy'),
                    'qualityStatus' => '' === $row['quality_status'] ? null : $row['quality_status'],
                    'notes' => '' === $row['notes'] ? null : $row['notes'],
                    'sourceUrl' => '' === $row['source_url'] ? null : $row['source_url'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                    'activityUnit' => $row['input_unit'],
                ]));
        }
    }

    /** @param array<string, true> $factorIds */
    private function loadContextualFactors(ObjectManager $manager, array &$factorIds): void
    {
        $file = $this->csv(self::CONTEXTUAL_FILE, self::HEADERS, 'contextual accommodation');
        while (!$file->eof()) {
            $row = $this->row($file, self::HEADERS, 'contextual accommodation');
            if (null === $row) {
                continue;
            }
            if ('Apartamento / vivienda' !== $row['activity'] || 'VERSIONED' !== $row['temporal_type']) {
                throw new \RuntimeException('Unexpected contextual accommodation temporal type.');
            }

            $criteria = $this->keyGenerator->normalize([
                'accommodationType' => 'apartment',
                'countryScope' => $row['geography'],
                'unit' => 'persona-noche',
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $this->assertUniqueFactorId($factorIds, $row['factor_id'], 'contextual accommodation', $file->key() + 1);

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setFactorId($row['factor_id'])
                ->setActivityYear(null)
                ->setYear(null)
                ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_VERSIONED)
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail(null)
                ->setMetadata([
                    'accommodationTypeLabel' => $row['activity'],
                    'countryScope' => $row['geography'],
                    'activityYearScope' => $row['activity_year_scope'],
                    'factorVersion' => $row['factor_version'],
                    'isTemporalFallback' => $this->boolean($row['is_temporal_fallback'], 'is_temporal_fallback'),
                    'isGeographicProxy' => $this->boolean($row['is_geographic_proxy'], 'is_geographic_proxy'),
                    'qualityStatus' => '' === $row['quality_status'] ? null : $row['quality_status'],
                    'notes' => '' === $row['notes'] ? null : $row['notes'],
                    'sourceUrl' => '' === $row['source_url'] ? null : $row['source_url'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                    'activityUnit' => $row['input_unit'],
                ]));
        }
    }

    /** @param list<string> $headers */
    private function csv(string $path, array $headers, string $label): \SplFileObject
    {
        $file = new \SplFileObject($path, 'rb');
        $file->setCsvControl(',', '"', '');
        if ($headers !== $file->fgetcsv()) {
            throw new \RuntimeException(sprintf('Unexpected %s emission factor CSV headers.', $label));
        }

        return $file;
    }

    /** @param list<string> $headers
     *  @return array<string, string>|null
     */
    private function row(\SplFileObject $file, array $headers, string $label): ?array
    {
        $values = $file->fgetcsv();
        if (false === $values || [null] === $values) {
            return null;
        }
        if (count($headers) !== count($values)) {
            throw new \RuntimeException(sprintf('Malformed %s emission factor CSV row %d.', $label, $file->key() + 1));
        }

        /** @var array<string, string> $row */
        $row = array_combine($headers, $values);

        return $row;
    }

    /** @param array<string, true> $factorIds */
    private function assertUniqueFactorId(array &$factorIds, string $factorId, string $label, int $row): void
    {
        if ('' === $factorId || isset($factorIds[$factorId])) {
            throw new \RuntimeException(sprintf('Missing or duplicate %s factor ID in CSV row %d.', $label, $row));
        }
        $factorIds[$factorId] = true;
    }

    private function boolean(string $value, string $field): bool
    {
        return match ($value) {
            '1' => true,
            '0' => false,
            default => throw new \RuntimeException(sprintf('Invalid boolean value for %s.', $field)),
        };
    }
}
