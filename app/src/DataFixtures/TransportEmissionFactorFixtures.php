<?php

namespace App\DataFixtures;

use App\Entity\EmissionFactor;
use App\Service\Emission\EmissionFactorKeyGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Source: consolidated BGMF calculator master base, received 2026-09-05.
 * The versioned CSV contains the raw XLSX values.
 */
final class TransportEmissionFactorFixtures extends Fixture implements FixtureGroupInterface
{
    private const CATEGORY_KEY = 'transport';
    private const SOURCE_CATEGORY = 'Transporte';
    private const DATA_FILE = __DIR__.'/data/emission/transport_factors_v20.csv';
    private const HEADERS = [
        'factor_id',
        'area',
        'category',
        'subcategory',
        'activity',
        'fuel',
        'unit',
        'method',
        'activity_year',
        'factor_year',
        'factor_value',
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
        return ['emission', 'transport-emission-factors'];
    }

    public function load(ObjectManager $manager): void
    {
        $file = new \SplFileObject(self::DATA_FILE, 'rb');
        $file->setCsvControl(',', '"', '');

        $headers = $file->fgetcsv();
        if (self::HEADERS !== $headers) {
            throw new \RuntimeException('Unexpected transport emission factor CSV headers.');
        }

        $factorIds = [];
        $count = 0;
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if (false === $values || [null] === $values) {
                continue;
            }
            if (count(self::HEADERS) !== count($values)) {
                throw new \RuntimeException(sprintf('Malformed transport emission factor CSV row %d.', $file->key() + 1));
            }

            /** @var array<string, string> $row */
            $row = array_combine(self::HEADERS, $values);
            if (self::SOURCE_CATEGORY !== $row['category']) {
                throw new \RuntimeException(sprintf('Unexpected category in transport emission factor CSV row %d.', $file->key() + 1));
            }
            if ('' === $row['factor_id'] || isset($factorIds[$row['factor_id']])) {
                throw new \RuntimeException(sprintf('Missing or duplicate transport factor_id in CSV row %d.', $file->key() + 1));
            }
            $factorIds[$row['factor_id']] = true;

            $criteria = $this->keyGenerator->normalize([
                'area' => $row['area'],
                'subcategory' => $row['subcategory'],
                'activity' => $row['activity'],
                'fuel' => $row['fuel'],
                'unit' => $row['unit'],
                'method' => $row['method'],
            ]);

            $factor = (new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($this->keyGenerator->generate($criteria))
                ->setCriteria($criteria)
                ->setFactorId($row['factor_id'])
                ->setActivityYear((int) $row['activity_year'])
                ->setYear((int) $row['factor_year'])
                ->setTemporalType($row['temporal_type'])
                ->setValue('' === $row['factor_value'] ? null : $row['factor_value'])
                ->setUnit($row['unit'])
                ->setSource($row['source'])
                ->setSourceDetail('' === $row['source_detail'] ? null : $row['source_detail'])
                ->setMetadata([
                    'factorVersion' => '' === $row['factor_version'] ? null : $row['factor_version'],
                    'sourceUrl' => '' === $row['source_url'] ? null : $row['source_url'],
                    'isTemporalFallback' => '1' === $row['is_temporal_fallback'],
                    'isGeographicProxy' => '1' === $row['is_geographic_proxy'],
                    'proxyGeography' => null,
                    'qualityStatus' => '' === $row['quality_status'] ? null : $row['quality_status'],
                    'notes' => '' === $row['notes'] ? null : $row['notes'],
                    'sourceWorkbook' => $row['source_workbook'],
                    'sourceSheet' => $row['source_sheet'],
                ]);

            $manager->persist($factor);
            ++$count;
        }

        if (1193 !== $count) {
            throw new \RuntimeException(sprintf('Expected 1193 transport emission factors, got %d.', $count));
        }
        $manager->flush();
    }
}
