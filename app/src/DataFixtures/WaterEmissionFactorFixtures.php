<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

final class WaterEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'water';
    private const DATA_FILE = __DIR__.'/data/emission/water_factors_v1.csv';
    private const HEADERS = [
        'factor_id',
        'geography',
        'factor_type',
        'activity_year',
        'factor_year',
        'factor_value',
        'unit',
        'temporal_type',
        'factor_version',
        'source',
        'source_detail',
        'source_url',
        'is_temporal_fallback',
        'is_geographic_proxy',
        'quality_status',
        'notes',
        'source_workbook',
        'source_sheet',
    ];

    public function __construct(private readonly EmissionFactorKeyGenerator $keyGenerator)
    {
    }

    public static function getGroups(): array
    {
        return ['emission', 'water-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $file = new \SplFileObject(self::DATA_FILE, 'rb');
        $file->setCsvControl(',', '"', '');
        if (self::HEADERS !== $file->fgetcsv()) {
            throw new \RuntimeException('Unexpected water emission factor CSV headers.');
        }

        $factorIds = [];
        $count = 0;
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed water emission factor CSV row %d.', $file->key() + 1));
            }

            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);
            $criteria = $this->keyGenerator->normalize([
                'geography' => $row['geography'],
                'factorType' => $row['factor_type'],
                'unit' => 'm3',
            ]);
            $functionalKey = $this->keyGenerator->generate($criteria);
            if ('' === $row['factor_id'] || isset($factorIds[$row['factor_id']])) {
                throw new \RuntimeException(sprintf('Missing or duplicate water factor_id in CSV row %d.', $file->key() + 1));
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
                ->setUnit($row['unit'])
                ->setSource($row['source'])
                ->setSourceDetail('' === $row['source_detail'] ? null : $row['source_detail'])
                ->setMetadata([
                    'sourceEdition' => '' === $row['factor_version'] ? null : $row['factor_version'],
                    'factorVersion' => '' === $row['factor_version'] ? null : $row['factor_version'],
                    'sourceUrl' => '' === $row['source_url'] ? null : $row['source_url'],
                    'isTemporalFallback' => '1' === $row['is_temporal_fallback'],
                    'isGeographicProxy' => '1' === $row['is_geographic_proxy'],
                    'qualityStatus' => '' === $row['quality_status'] ? null : $row['quality_status'],
                    'notes' => '' === $row['notes'] ? null : $row['notes'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                    'sourceGeography' => $row['geography'],
                    'factorType' => $row['factor_type'],
                    'activityUnit' => 'm3',
                ]);

            $manager->persist($factor);
            ++$count;
        }

        if (12 !== $count) {
            throw new \RuntimeException(sprintf('Expected 12 water emission factors, got %d.', $count));
        }
        $manager->flush();
    }
}
