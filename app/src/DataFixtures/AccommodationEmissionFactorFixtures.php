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
    private const HOTEL_HEADERS = [
        'activity_year', 'country', 'iso3', 'stars', 'dataset', 'dataset_calendar_year',
        'tool_version', 'factor_value', 'factor_unit', 'method', 'sample_count',
        'is_temporal_fallback', 'is_geographic_proxy', 'source', 'source_url',
    ];
    private const CONTEXTUAL_HEADERS = [
        'accommodation_type', 'country_scope', 'activity_year_scope', 'factor_value',
        'factor_unit', 'temporal_type', 'factor_version', 'is_temporal_fallback',
        'is_geographic_proxy', 'source', 'source_url',
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
        $identities = [];
        $this->loadHotelFactors($manager, $identities);
        $this->loadContextualFactors($manager, $identities);
        $manager->flush();
    }

    /** @param array<string, true> $identities */
    private function loadHotelFactors(ObjectManager $manager, array &$identities): void
    {
        $file = $this->csv(self::HOTEL_FILE, self::HOTEL_HEADERS, 'hotel');
        while (!$file->eof()) {
            $row = $this->row($file, self::HOTEL_HEADERS, 'hotel');
            if (null === $row) {
                continue;
            }

            $criteria = $this->keyGenerator->normalize([
                'accommodationType' => 'hotel',
                'iso3' => $row['iso3'],
                'stars' => $row['stars'],
                'unit' => 'occupied room-night',
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $this->assertUnique($identities, $functionalKey, $row['activity_year'], 'hotel', $file->key() + 1);

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setYear((int) $row['activity_year'])
                ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL)
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail(null)
                ->setMetadata([
                    'country' => $row['country'],
                    'iso3' => $row['iso3'],
                    'stars' => $row['stars'],
                    'dataset' => $row['dataset'],
                    'datasetCalendarYear' => (int) $row['dataset_calendar_year'],
                    'toolVersion' => $row['tool_version'],
                    'method' => $row['method'],
                    'sampleCount' => '' === $row['sample_count'] ? null : (int) $row['sample_count'],
                    'sourceTemporalFallback' => $this->boolean($row['is_temporal_fallback'], 'is_temporal_fallback'),
                    'isGeographicProxy' => $this->boolean($row['is_geographic_proxy'], 'is_geographic_proxy'),
                    'sourceUrl' => $row['source_url'],
                    'activityUnit' => 'occupied room-night',
                ]));
        }
    }

    /** @param array<string, true> $identities */
    private function loadContextualFactors(ObjectManager $manager, array &$identities): void
    {
        $file = $this->csv(self::CONTEXTUAL_FILE, self::CONTEXTUAL_HEADERS, 'contextual accommodation');
        while (!$file->eof()) {
            $row = $this->row($file, self::CONTEXTUAL_HEADERS, 'contextual accommodation');
            if (null === $row) {
                continue;
            }
            if ('VERSIONED' !== $row['temporal_type']) {
                throw new \RuntimeException('Unexpected contextual accommodation temporal type.');
            }

            $criteria = $this->keyGenerator->normalize([
                'accommodationType' => 'apartment',
                'countryScope' => $row['country_scope'],
                'unit' => 'persona-noche',
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            $this->assertUnique($identities, $functionalKey, 'VERSIONED', 'contextual accommodation', $file->key() + 1);

            $manager->persist((new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setYear(2025)
                ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_VERSIONED)
                ->setValue($row['factor_value'])
                ->setUnit($row['factor_unit'])
                ->setSource($row['source'])
                ->setSourceDetail(null)
                ->setMetadata([
                    'accommodationTypeLabel' => $row['accommodation_type'],
                    'countryScope' => $row['country_scope'],
                    'activityYearScope' => $row['activity_year_scope'],
                    'factorVersion' => $row['factor_version'],
                    'sourceTemporalFallback' => $this->boolean($row['is_temporal_fallback'], 'is_temporal_fallback'),
                    'isGeographicProxy' => $this->boolean($row['is_geographic_proxy'], 'is_geographic_proxy'),
                    'sourceUrl' => $row['source_url'],
                    'activityUnit' => 'persona-noche',
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

    /** @param array<string, true> $identities */
    private function assertUnique(array &$identities, string $functionalKey, string $temporalIdentity, string $label, int $row): void
    {
        $identity = $functionalKey.'|'.$temporalIdentity;
        if (isset($identities[$identity])) {
            throw new \RuntimeException(sprintf('Duplicate %s factor source identity in CSV row %d.', $label, $row));
        }
        $identities[$identity] = true;
    }

    private function boolean(string $value, string $field): bool
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => throw new \RuntimeException(sprintf('Invalid boolean value for %s.', $field)),
        };
    }
}
