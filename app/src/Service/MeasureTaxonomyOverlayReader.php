<?php

namespace App\Service;

use App\Entity\MeasureVerificationSource;
use App\Entity\VerificationSource;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

final class MeasureTaxonomyOverlayReader
{
    private const CONFIG = [
        'be-green-my-film' => ['measures' => 200, 'departments' => 22, 'departmentLinks' => 663, 'evidenceLinks' => 3217, 'catalogId' => false],
        'be-green-my-event' => ['measures' => 200, 'departments' => 22, 'departmentLinks' => 554, 'evidenceLinks' => 3667, 'catalogId' => false],
        'be-green-my-animation' => ['measures' => 237, 'departments' => 24, 'departmentLinks' => 1202, 'evidenceLinks' => 4010, 'catalogId' => true],
    ];

    /**
     * @return array{protocolCode:string, departments:list<string>, rows:list<array{number:int,catalogId:?string,departments:list<string>,evidences:array<string,string>}>,departmentLinks:int,evidenceLinks:int}
     */
    public function read(string $path, string $protocolCode): array
    {
        return $this->readSpreadsheet(IOFactory::load($path), $protocolCode);
    }

    /**
     * @return array{protocolCode:string, departments:list<string>, rows:list<array{number:int,catalogId:?string,departments:list<string>,evidences:array<string,string>}>,departmentLinks:int,evidenceLinks:int}
     */
    public function readSpreadsheet(Spreadsheet $spreadsheet, string $protocolCode): array
    {
        $config = self::CONFIG[$protocolCode] ?? throw new RuntimeException(sprintf('Overlay no soportado para "%s".', $protocolCode));
        $sheet = $spreadsheet->getActiveSheet();
        $headerRow = null;
        $headers = [];

        for ($row = 1; $row <= min(10, $sheet->getHighestDataRow()); ++$row) {
            $candidate = [];
            for ($column = 1; $column <= Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); ++$column) {
                $label = trim((string) $sheet->getCell([$column, $row])->getValue());
                if ($label !== '') {
                    $candidate[$label] = $column;
                }
            }
            if (isset($candidate['N.º'])) {
                $headerRow = $row;
                $headers = $candidate;
                break;
            }
        }
        if ($headerRow === null) {
            throw new RuntimeException('No se encontró la cabecera N.º del overlay.');
        }

        $departmentColumns = [];
        $evidenceColumns = [];
        $canonicalCodesByName = array_flip(VerificationSource::CANONICAL_SOURCES);
        foreach ($headers as $label => $column) {
            if (str_starts_with($label, 'DEP · ')) {
                $departmentColumns[substr($label, strlen('DEP · '))] = $column;
            } elseif (str_starts_with($label, 'EVI · ')) {
                $name = substr($label, strlen('EVI · '));
                $code = $canonicalCodesByName[$name] ?? null;
                if ($code === null) {
                    throw new RuntimeException(sprintf('Tipo EVI sin código canónico: "%s".', $name));
                }
                $evidenceColumns[$code] = $column;
            }
        }

        if (count($departmentColumns) !== $config['departments']) {
            throw new RuntimeException(sprintf('Se esperaban %d cabeceras DEP y se encontraron %d.', $config['departments'], count($departmentColumns)));
        }
        if (count($evidenceColumns) !== count(VerificationSource::CANONICAL_SOURCES)) {
            throw new RuntimeException(sprintf('Se esperaban 64 cabeceras EVI y se encontraron %d.', count($evidenceColumns)));
        }
        if ($config['catalogId'] && !isset($headers['ID interno'])) {
            throw new RuntimeException('El overlay Animation no contiene ID interno.');
        }

        $rows = [];
        $seenNumbers = [];
        $seenCatalogIds = [];
        $departmentLinks = 0;
        $evidenceLinks = 0;
        for ($row = $headerRow + 1; $row <= $sheet->getHighestDataRow(); ++$row) {
            $rawNumber = trim((string) $sheet->getCell([$headers['N.º'], $row])->getValue());
            if ($rawNumber === '') {
                continue;
            }
            if (!ctype_digit($rawNumber)) {
                throw new RuntimeException(sprintf('N.º inválido en fila %d.', $row));
            }
            $number = (int) $rawNumber;
            if (isset($seenNumbers[$number])) {
                throw new RuntimeException(sprintf('N.º duplicado: %d.', $number));
            }
            $seenNumbers[$number] = true;

            $catalogId = null;
            if ($config['catalogId']) {
                $catalogId = trim((string) $sheet->getCell([$headers['ID interno'], $row])->getValue());
                if ($catalogId === '' || isset($seenCatalogIds[$catalogId])) {
                    throw new RuntimeException(sprintf('ID interno vacío o duplicado en fila %d.', $row));
                }
                $seenCatalogIds[$catalogId] = true;
            }

            $departments = [];
            foreach ($departmentColumns as $name => $column) {
                $value = mb_strtoupper(trim((string) $sheet->getCell([$column, $row])->getValue()));
                if ($value === '') {
                    continue;
                }
                if ($value !== 'X') {
                    throw new RuntimeException(sprintf('Valor DEP inválido en fila %d, "%s": "%s".', $row, $name, $value));
                }
                $departments[] = $name;
                ++$departmentLinks;
            }

            $evidences = [];
            foreach ($evidenceColumns as $code => $column) {
                $quality = trim((string) $sheet->getCell([$column, $row])->getValue());
                if ($quality === '') {
                    continue;
                }
                if (!in_array($quality, MeasureVerificationSource::SUGGESTED_QUALITIES, true)) {
                    throw new RuntimeException(sprintf('Calidad EVI inválida en fila %d, "%s": "%s".', $row, $code, $quality));
                }
                $evidences[$code] = $quality;
                ++$evidenceLinks;
            }

            $rows[] = ['number' => $number, 'catalogId' => $catalogId, 'departments' => $departments, 'evidences' => $evidences];
        }

        $expectedNumbers = range(1, $config['measures']);
        if (array_keys($seenNumbers) !== $expectedNumbers) {
            throw new RuntimeException(sprintf('La numeración debe ser exactamente 1..%d.', $config['measures']));
        }
        if ($departmentLinks !== $config['departmentLinks'] || $evidenceLinks !== $config['evidenceLinks']) {
            throw new RuntimeException(sprintf(
                'Conteos de overlay inesperados: DEP %d/%d, EVI %d/%d.',
                $departmentLinks,
                $config['departmentLinks'],
                $evidenceLinks,
                $config['evidenceLinks']
            ));
        }
        if ($protocolCode === 'be-green-my-film') {
            $row125 = $rows[124] ?? null;
            if ($row125 === null || count($row125['departments']) !== 15 || count($row125['evidences']) !== 25) {
                throw new RuntimeException('Film 125 no contiene exactamente 15 DEP y 25 EVI.');
            }
        }

        return [
            'protocolCode' => $protocolCode,
            'departments' => array_keys($departmentColumns),
            'rows' => $rows,
            'departmentLinks' => $departmentLinks,
            'evidenceLinks' => $evidenceLinks,
        ];
    }
}
