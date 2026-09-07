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
        'geography',
        'factor_type',
        'factor_year',
        'factor_value',
        'unit',
        'source',
        'source_edition',
        'status',
        'source_url',
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

        $identities = [];
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
            $identity = $functionalKey.'|'.$row['factor_year'];
            if (isset($identities[$identity])) {
                throw new \RuntimeException(sprintf('Duplicate water factor source identity in CSV row %d.', $file->key() + 1));
            }
            $identities[$identity] = true;

            $factor = (new EmissionFactor())
                ->setCategoryKey(self::CATEGORY_KEY)
                ->setFunctionalKey($functionalKey)
                ->setCriteria($criteria)
                ->setTemporalType(EmissionFactor::TEMPORAL_TYPE_ANNUAL)
                ->setYear((int) $row['factor_year'])
                ->setValue($row['factor_value'])
                ->setUnit($row['unit'])
                ->setSource($row['source'])
                ->setSourceDetail(null)
                ->setMetadata([
                    'sourceEdition' => $row['source_edition'],
                    'sourceUrl' => $row['source_url'],
                    'sourceStatus' => $row['status'],
                    'factorVersion' => 'water-v1',
                    'sourceGeography' => $row['geography'],
                    'factorType' => $row['factor_type'],
                    'activityUnit' => 'm3',
                ]);

            $manager->persist($factor);
        }

        $manager->flush();
    }
}
