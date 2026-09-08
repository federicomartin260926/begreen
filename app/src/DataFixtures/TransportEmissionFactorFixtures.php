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
    private const SOURCE_CATEGORY = 'TRANSPORTE';
    private const DATA_FILE = __DIR__.'/data/emission/transport_factors_v20.csv';
    private const HEADERS = [
        'area',
        'category',
        'subcategory',
        'activity',
        'fuel',
        'unit',
        'method',
        'factor_year',
        'factor_value',
        'source',
        'source_detail',
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
                ->setYear((int) $row['factor_year'])
                ->setValue('' === $row['factor_value'] ? null : $row['factor_value'])
                ->setUnit($row['unit'])
                ->setSource($row['source'])
                ->setSourceDetail('' === $row['source_detail'] ? null : $row['source_detail']);

            $manager->persist($factor);
        }

        $manager->flush();
    }
}
