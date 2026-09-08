<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

final class CateringEmissionSnapshot
{
    public const VERSION = 'catering-v1';

    /** @param array<string, scalar|null> $presentation */
    public function encode(CateringEmissionInput $input, CateringEmissionResult $result, array $presentation = []): string
    {
        return json_encode([
            'version' => self::VERSION,
            'calculatorVersion' => self::VERSION,
            'input' => $this->inputToArray($input),
            'calculation' => $result->toArray(),
            'presentation' => $presentation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function inputToArray(CateringEmissionInput $input): array
    {
        return [
            'startDate' => $input->startDate?->format('Y-m-d'),
            'endDate' => $input->endDate?->format('Y-m-d'),
            'country' => $input->country,
            'activityType' => $input->activityType,
            'people' => $input->people,
            'menuLines' => array_map(static fn (CateringMenuLine $line): array => [
                'menuVariant' => $line->menuVariant,
                'preparedCount' => $line->preparedCount,
                'consumedCount' => $line->consumedCount,
            ], $input->menuLines),
            'tablewareType' => $input->tablewareType,
            'sandwichType' => $input->sandwichType,
            'preparedCount' => $input->preparedCount,
            'consumedCount' => $input->consumedCount,
            'containerVolumeLiters' => $input->containerVolumeLiters,
            'containerMaterial' => $input->containerMaterial,
            'containerCount' => $input->containerCount,
            'description' => $input->description,
            'unitCount' => $input->unitCount,
            'litersPerUnit' => $input->litersPerUnit,
            'serviceCount' => $input->serviceCount,
            'coffeeType' => $input->coffeeType,
            'gasType' => $input->gasType,
            'cylinderCount' => $input->cylinderCount,
            'kgPerCylinder' => $input->kgPerCylinder,
        ];
    }

    public function decodeInput(string $snapshot): CateringEmissionInput
    {
        $input = $this->section($snapshot, 'input');

        return new CateringEmissionInput(
            $this->optionalDate($input, 'startDate'),
            $this->optionalDate($input, 'endDate'),
            $this->optionalString($input, 'country'),
            $this->optionalString($input, 'activityType'),
            $this->optionalString($input, 'people'),
            $this->menuLines($input),
            $this->optionalString($input, 'tablewareType'),
            $this->optionalString($input, 'sandwichType'),
            $this->optionalString($input, 'preparedCount'),
            $this->optionalString($input, 'consumedCount'),
            $this->optionalString($input, 'containerVolumeLiters'),
            $this->optionalString($input, 'containerMaterial'),
            $this->optionalString($input, 'containerCount'),
            $this->optionalString($input, 'description'),
            $this->optionalString($input, 'unitCount'),
            $this->optionalString($input, 'litersPerUnit'),
            $this->optionalString($input, 'serviceCount'),
            $this->optionalString($input, 'coffeeType'),
            $this->optionalString($input, 'gasType'),
            $this->optionalString($input, 'cylinderCount'),
            $this->optionalString($input, 'kgPerCylinder'),
        );
    }

    /** @return array<string, scalar|null> */
    public function decodePresentation(string $snapshot): array
    {
        $presentation = $this->section($snapshot, 'presentation');
        foreach ($presentation as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && null !== $value)) {
                throw new \UnexpectedValueException('Invalid catering emission snapshot presentation.');
            }
        }

        return $presentation;
    }

    /** @return array<string, mixed> */
    private function decode(string $snapshot): array
    {
        $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || self::VERSION !== ($data['version'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported catering emission snapshot version.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function section(string $snapshot, string $section): array
    {
        $data = $this->decode($snapshot);
        if (!is_array($data[$section] ?? null)) {
            throw new \UnexpectedValueException(sprintf('Invalid catering emission snapshot section: %s.', $section));
        }

        return $data[$section];
    }

    /** @param array<string, mixed> $input
     *  @return list<CateringMenuLine>
     */
    private function menuLines(array $input): array
    {
        if (!array_key_exists('menuLines', $input) || !is_array($input['menuLines']) || !array_is_list($input['menuLines'])) {
            throw new \UnexpectedValueException('Invalid catering emission snapshot input: menuLines.');
        }

        return array_map(function (mixed $line): CateringMenuLine {
            if (!is_array($line)) {
                throw new \UnexpectedValueException('Invalid catering emission snapshot menu line.');
            }

            return new CateringMenuLine(
                $this->optionalString($line, 'menuVariant'),
                $this->optionalString($line, 'preparedCount'),
                $this->optionalString($line, 'consumedCount'),
            );
        }, $input['menuLines']);
    }

    /** @param array<string, mixed> $input */
    private function optionalDate(array $input, string $field): ?\DateTimeImmutable
    {
        $value = $this->optionalString($input, $field);
        if (null === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            throw new \UnexpectedValueException(sprintf('Invalid catering emission snapshot date: %s.', $field));
        }

        return $date;
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $field): ?string
    {
        if (!array_key_exists($field, $input)) {
            throw new \UnexpectedValueException(sprintf('Missing catering emission snapshot input: %s.', $field));
        }
        $value = $input[$field];
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Invalid catering emission snapshot input: %s.', $field));
        }

        return $value;
    }
}
